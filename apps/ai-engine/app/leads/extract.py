"""Pure HTML -> facts. No network. Everything here is deterministic and unit-tested against fixture pages."""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from urllib.parse import parse_qs, unquote, urljoin, urlsplit

import phonenumbers
from bs4 import BeautifulSoup, Tag

EMAIL_RE = re.compile(
    r"(?<![\w.+-])[A-Za-z0-9._%+-]{1,64}@[A-Za-z0-9-]{1,63}(?:\.[A-Za-z0-9-]{1,63})+(?![\w-])"
)
OBFUSCATED_RE = re.compile(
    r"([A-Za-z0-9._%+-]{1,64})\s*[\[\(\{]\s*(?:at|AT)\s*[\]\)\}]\s*([A-Za-z0-9-]{1,63})\s*[\[\(\{]\s*(?:dot|DOT)\s*[\]\)\}]\s*([A-Za-z]{2,10})"
)
PHONE_CANDIDATE_RE = re.compile(r"(?<![\w/])\+?\d[\d\s().-]{7,18}\d(?![\w/])")
BAD_EMAIL_SUFFIX = (".png", ".jpg", ".jpeg", ".gif", ".webp", ".svg", ".css", ".js")
BAD_EMAIL_DOMAINS = (
    "example.com",
    "example.org",
    "domain.com",
    "email.com",
    "sentry.io",
    "wixpress.com",
    "yoursite.com",
    "yourdomain.com",
)
SOCIAL_HOSTS = {
    "facebook.com": "facebook",
    "fb.com": "facebook",
    "instagram.com": "instagram",
    "linkedin.com": "linkedin",
    "twitter.com": "twitter",
    "x.com": "twitter",
    "youtube.com": "youtube",
    "tiktok.com": "tiktok",
    "wa.me": "whatsapp",
    "api.whatsapp.com": "whatsapp",
    "t.me": "telegram",
    "pinterest.com": "pinterest",
}
SOCIAL_SKIP_PATHS = (
    "sharer",
    "share",
    "intent",
    "dialog",
    "plugins",
    "tr",
    "login",
    "sharearticle",
    "sharer.php",
)
CONTACT_HINTS = (
    "contact",
    "about",
    "support",
    "reach",
    "team",
    "যোগাযোগ",
    "আমাদের-সম্পর্কে",
    "about-us",
    "contact-us",
    "help",
)


@dataclass
class PageFacts:
    url: str
    title: str = ""
    description: str = ""
    site_name: str = ""
    lang: str = ""
    emails: list[str] = field(default_factory=list)
    phones: list[tuple[str, str]] = field(default_factory=list)  # (e164, kind)
    social: dict[str, str] = field(default_factory=dict)
    tech: list[str] = field(default_factory=list)
    tracking: dict[str, bool] = field(default_factory=dict)
    has_viewport: bool = False
    has_form: bool = False
    has_whatsapp: bool = False
    address: str = ""
    links: list[str] = field(default_factory=list)  # same-site candidate contact/about pages


def _attr(tag: Tag, name: str) -> str:
    v = tag.get(name, "")
    return " ".join(v) if isinstance(v, list) else str(v)


def decode_cfemail(hexstr: str) -> str:
    """Cloudflare 'email protection': first byte is an XOR key for the rest."""
    try:
        raw = bytes.fromhex(hexstr)
        key = raw[0]
        return bytes(b ^ key for b in raw[1:]).decode("utf-8")
    except (ValueError, IndexError, UnicodeDecodeError):
        return ""


def clean_email(e: str) -> str | None:
    e = unquote(e).strip().strip(".,;:<>()[]\"'").lower()
    if not EMAIL_RE.fullmatch(e) or e.endswith(BAD_EMAIL_SUFFIX) or "@2x" in e or "@3x" in e:
        return None
    domain = e.rsplit("@", 1)[1]
    if domain.endswith(BAD_EMAIL_DOMAINS) or len(e) > 120:
        return None
    return e


def extract_emails(soup: BeautifulSoup, text: str) -> list[str]:
    found: list[str] = []
    for a in soup.find_all("a", href=True):
        h = _attr(a, "href")
        if h.lower().startswith("mailto:"):
            found.append(h[7:].split("?", 1)[0].split(",")[0])
        if "/cdn-cgi/l/email-protection#" in h:
            found.append(decode_cfemail(h.split("#", 1)[1]))
    for el in (t for t in soup.find_all(True) if t.has_attr("data-cfemail")):
        found.append(decode_cfemail(_attr(el, "data-cfemail")))
    found += EMAIL_RE.findall(text)
    found += [f"{u}@{d}.{t}" for u, d, t in OBFUSCATED_RE.findall(text)]
    out: list[str] = []
    for e in found:
        c = clean_email(e)
        if c and c not in out:
            out.append(c)
    return out[:10]


def parse_phone(raw: str, region: str = "BD") -> tuple[str, str] | None:
    try:
        n = phonenumbers.parse(raw, region)
    except phonenumbers.NumberParseException:
        return None
    if not phonenumbers.is_valid_number(n):
        return None
    t = phonenumbers.number_type(n)
    kind = {
        phonenumbers.PhoneNumberType.MOBILE: "mobile",
        phonenumbers.PhoneNumberType.FIXED_LINE: "fixed",
        phonenumbers.PhoneNumberType.FIXED_LINE_OR_MOBILE: "mobile",
    }.get(t, "other")
    return phonenumbers.format_number(n, phonenumbers.PhoneNumberFormat.E164), kind


def extract_phones(soup: BeautifulSoup, text: str, region: str = "BD") -> list[tuple[str, str]]:
    cands: list[str] = []
    for a in soup.find_all("a", href=True):
        h = _attr(a, "href")
        if h.lower().startswith("tel:"):
            cands.append(unquote(h[4:]))
        m = re.match(r"https?://(?:wa\.me/|api\.whatsapp\.com/send\?phone=)(\+?\d{8,15})", h)
        if m:
            cands.append("+" + m.group(1).lstrip("+"))
    cands += PHONE_CANDIDATE_RE.findall(text)
    out: list[tuple[str, str]] = []
    seen: set[str] = set()
    for c in cands:
        p = parse_phone(c, region)
        if p and p[0] not in seen:
            seen.add(p[0])
            out.append(p)
    return out[:10]


def _social_url(href: str) -> tuple[str, str] | None:
    try:
        p = urlsplit(href)
    except ValueError:
        return None
    host = (p.hostname or "").lower().removeprefix("www.").removeprefix("m.")
    net = SOCIAL_HOSTS.get(host)
    if net is None or p.scheme not in ("http", "https"):
        return None
    seg = [s for s in p.path.split("/") if s]
    if net == "whatsapp":
        return net, f"https://{host}/" + "/".join(seg[:1]) if seg else (net, href)  # type: ignore[return-value]
    if not seg or seg[0].lower() in SOCIAL_SKIP_PATHS or "share" in p.path.lower():
        return None
    if net in ("linkedin",) and len(seg) >= 2:
        return net, f"https://www.linkedin.com/{seg[0]}/{seg[1]}"
    if net == "youtube" and seg[0] in ("channel", "c", "user") and len(seg) >= 2:
        return net, f"https://www.youtube.com/{seg[0]}/{seg[1]}"
    if net == "facebook" and seg[0] == "profile.php":
        q = parse_qs(p.query).get("id", [""])[0]
        return (net, f"https://www.facebook.com/profile.php?id={q}") if q.isdigit() else None
    return net, f"https://{host}/{seg[0]}"


def analyze_page(url: str, html: str, region: str = "BD") -> PageFacts:
    soup = BeautifulSoup(html, "lxml")
    facts = PageFacts(url=url)
    low = html.lower()

    t = soup.find("title")
    facts.title = re.sub(r"\s+", " ", t.get_text()).strip()[:200] if t else ""
    d = soup.find("meta", attrs={"name": re.compile("^description$", re.I)})
    facts.description = str(d.get("content", "")).strip()[:300] if d else ""
    og = soup.find("meta", attrs={"property": "og:site_name"})
    facts.site_name = str(og.get("content", "")).strip()[:120] if og else ""
    root = soup.find("html")
    facts.lang = str(root.get("lang", ""))[:10] if root else ""
    facts.has_viewport = (
        soup.find("meta", attrs={"name": re.compile("^viewport$", re.I)}) is not None
    )

    addr = soup.find("address")
    if addr:
        facts.address = re.sub(r"\s+", " ", addr.get_text(" ")).strip()[:200]

    for s in soup(["script", "style", "noscript", "template"]):
        s.extract()
    text = soup.get_text(" ")
    text = re.sub(r"[ \t\r\f\v]+", " ", text)

    facts.emails = extract_emails(soup, text)
    facts.phones = extract_phones(soup, text, region)

    for a in soup.find_all("a", href=True):
        soc = _social_url(urljoin(url, _attr(a, "href")))
        if soc and soc[0] not in facts.social:
            facts.social[soc[0]] = soc[1]
    facts.has_whatsapp = "whatsapp" in facts.social or "wa.me/" in low or "api.whatsapp.com" in low

    tech = []
    for key, needles in {
        "wordpress": ("wp-content", "wp-includes"),
        "woocommerce": ("woocommerce",),
        "shopify": ("cdn.shopify.com", "shopify.theme"),
        "wix": ("wixstatic.com", "wix.com"),
        "joomla": ("/media/jui/", "joomla"),
        "squarespace": ("squarespace",),
    }.items():
        if any(n in low for n in needles):
            tech.append(key)
    gen = soup.find("meta", attrs={"name": re.compile("^generator$", re.I)})
    if (
        gen
        and str(gen.get("content", "")).lower().startswith("wordpress")
        and "wordpress" not in tech
    ):
        tech.append("wordpress")
    facts.tech = tech
    facts.tracking = {
        "ga": any(
            n in low for n in ("google-analytics.com", "gtag/js", "googletagmanager.com/gtag")
        ),
        "gtm": "googletagmanager.com/gtm.js" in low or "googletagmanager.com/ns.html" in low,
        "fb_pixel": "connect.facebook.net" in low and "fbevents" in low or "fbq(" in low,
        "tiktok_pixel": "analytics.tiktok.com" in low,
    }

    for form in soup.find_all("form"):
        inputs = [
            i
            for i in form.find_all(["input", "textarea"])
            if i.get("type", "text")
            not in ("hidden", "submit", "button", "search", "checkbox", "radio")
        ]
        if (
            len(inputs) >= 2
            and "search" not in (form.get("role") or "")
            and not form.find("input", attrs={"type": "search"})
        ):
            facts.has_form = True
            break

    base_host = (urlsplit(url).hostname or "").removeprefix("www.")
    for a in soup.find_all("a", href=True):
        href = urljoin(url, str(a["href"])).split("#", 1)[0]
        pp = urlsplit(href)
        if (
            pp.scheme not in ("http", "https")
            or (pp.hostname or "").removeprefix("www.") != base_host
        ):
            continue
        hint = (pp.path + " " + a.get_text(" ")).lower()
        if any(h in hint for h in CONTACT_HINTS) and href not in facts.links and href != url:
            facts.links.append(href)
    facts.links = facts.links[:8]
    return facts
