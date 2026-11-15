"""Pure HTML -> SEO facts and per-page issue codes. No network."""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from urllib.parse import urljoin, urlsplit

from bs4 import BeautifulSoup

WORD_RE = re.compile(r"[\wঀ-৿]+", re.UNICODE)  # Latin + Bengali letters/marks
SKIP_EXT = (".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg", ".pdf", ".zip", ".css", ".js", ".xml", ".ico", ".mp4", ".mp3", ".doc", ".docx", ".xls", ".xlsx")


@dataclass
class PageSeo:
    url: str
    status: int
    title: str = ""
    description: str = ""
    h1: list[str] = field(default_factory=list)
    lang: str = ""
    has_viewport: bool = False
    canonical: str = ""
    noindex: bool = False
    images: int = 0
    images_no_alt: int = 0
    words: int = 0
    has_og: bool = False
    has_schema: bool = False
    intro: str = ""
    links: list[str] = field(default_factory=list)  # absolute http(s) URLs, any host


def analyze_html(url: str, status: int, html: str) -> PageSeo:
    soup = BeautifulSoup(html, "lxml")
    p = PageSeo(url=url, status=status)
    p.title = " ".join((soup.title.get_text(" ") if soup.title else "").split())
    d = soup.find("meta", attrs={"name": re.compile(r"^description$", re.I)})
    p.description = " ".join(str(d.get("content", "")).split()) if d else ""
    p.h1 = [" ".join(h.get_text(" ").split()) for h in soup.find_all("h1")]
    root = soup.find("html")
    p.lang = str(root.get("lang", "")).strip() if root else ""
    p.has_viewport = soup.find("meta", attrs={"name": re.compile(r"^viewport$", re.I)}) is not None
    c = soup.find("link", rel=lambda v: v and "canonical" in (v if isinstance(v, list) else [v]))
    p.canonical = str(c.get("href", "")).strip() if c else ""
    r = soup.find("meta", attrs={"name": re.compile(r"^robots$", re.I)})
    p.noindex = bool(r and "noindex" in str(r.get("content", "")).lower())
    imgs = soup.find_all("img")
    p.images = len(imgs)
    p.images_no_alt = sum(1 for i in imgs if i.get("alt") is None or not str(i.get("alt")).strip())
    p.has_og = soup.find("meta", attrs={"property": re.compile(r"^og:(title|image)$", re.I)}) is not None
    p.has_schema = soup.find("script", attrs={"type": re.compile(r"ld\+json", re.I)}) is not None
    for t in soup(["script", "style", "noscript", "template"]):
        t.decompose()
    text = " ".join(soup.get_text(" ").split())
    words = WORD_RE.findall(text)
    p.words = len(words)
    p.intro = " ".join(words[:100]).lower()
    for a in soup.find_all("a", href=True):
        href = str(a["href"]).strip()
        if not href or href.startswith(("#", "mailto:", "tel:", "javascript:", "sms:")):
            continue
        absu = urljoin(url, href).split("#", 1)[0]
        if urlsplit(absu).scheme in ("http", "https"):
            p.links.append(absu)
    return p


def internal_links(p: PageSeo, limit: int = 40) -> list[str]:
    """Same-host page links (no files, no query noise), de-duplicated, in document order."""
    host = urlsplit(p.url).netloc.lower().removeprefix("www.")
    out: list[str] = []
    seen = {p.url.rstrip("/")}
    for u in p.links:
        sp = urlsplit(u)
        if sp.netloc.lower().removeprefix("www.") != host or sp.path.lower().endswith(SKIP_EXT):
            continue
        norm = f"{sp.scheme}://{sp.netloc}{sp.path}".rstrip("/")
        if norm in seen:
            continue
        seen.add(norm)
        out.append(f"{sp.scheme}://{sp.netloc}{sp.path or '/'}")
        if len(out) >= limit:
            break
    return out


def page_codes(p: PageSeo, keyword: str | None = None) -> set[str]:
    if p.status >= 400:
        return {"page_error"}
    c: set[str] = set()
    n = len(p.title)
    if n == 0:
        c.add("no_title")
    elif n < 30:
        c.add("title_short")
    elif n > 60:
        c.add("title_long")
    m = len(p.description)
    if m == 0:
        c.add("no_description")
    elif m < 70:
        c.add("description_short")
    elif m > 160:
        c.add("description_long")
    if not p.h1:
        c.add("no_h1")
    elif len(p.h1) > 1:
        c.add("multi_h1")
    if not p.lang:
        c.add("no_lang")
    if not p.has_viewport:
        c.add("no_viewport")
    if not p.canonical:
        c.add("no_canonical")
    if p.noindex:
        c.add("noindex")
    if p.images_no_alt:
        c.add("img_no_alt")
    if p.words < 300:
        c.add("thin_content")
    if not p.has_og:
        c.add("no_og")
    if not p.has_schema:
        c.add("no_schema")
    kw = (keyword or "").strip().lower()
    if kw:
        if kw not in p.title.lower():
            c.add("kw_not_in_title")
        if not any(kw in h.lower() for h in p.h1):
            c.add("kw_not_in_h1")
        if kw not in p.intro:
            c.add("kw_not_in_intro")
    return c
