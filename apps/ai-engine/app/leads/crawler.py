"""Crawl one website: homepage + a few contact/about pages, merge the facts, analyse, score."""

from __future__ import annotations

import asyncio
import time
from typing import Any
from urllib.parse import urlsplit

from app.leads.analyze import find_gaps, lead_score
from app.leads.emailcheck import Resolver, check_emails
from app.leads.extract import PageFacts, analyze_page
from app.leads.fetch import FetchError, SafeFetcher
from app.leads.ssrf import UnsafeUrl, parse_url

DEADLINE_SECONDS = 60.0
RENDER_IF_TEXT_BELOW = 200


def site_domain(url: str) -> str:
    host = (urlsplit(url).hostname or "").lower().encode("ascii", "ignore").decode()
    return host.removeprefix("www.")


def guess_name(home: PageFacts, domain: str) -> str:
    if home.site_name:
        return home.site_name[:120]
    if home.title:
        parts = [
            p.strip()
            for p in __import__("re").split(r"\s[|\-–—:·]\s|\s*[|–—]\s*", home.title)
            if p.strip()
        ]
        if parts:
            return str(min(parts, key=lambda p: (len(p) < 3, len(p) > 60, abs(len(p) - 18)))[:120])
    return str(domain.split(".")[0].replace("-", " ").title())


def normalize_start(url: str) -> str:
    url = url.strip()
    return url if "://" in url else "https://" + url


async def crawl_site(
    url: str,
    fetcher: SafeFetcher,
    resolver: Resolver,
    *,
    max_pages: int = 4,
    check_email: bool = True,
    renderer: Any = None,
) -> dict[str, Any]:
    started = time.monotonic()
    url = normalize_start(url)
    base: dict[str, Any] = {
        "status": "ok",
        "url": url,
        "final_url": url,
        "domain": "",
        "pages_fetched": 0,
    }
    try:
        parse_url(url, allow_private=fetcher.allow_private)
    except UnsafeUrl as e:
        return base | {"status": "unsafe_url", "error": e.code}
    base["domain"] = site_domain(url)

    try:
        if not await fetcher.allowed(url):
            return base | {"status": "blocked_by_robots"}
        home_page = await fetcher.get(url)
    except FetchError as e:
        st = (
            "unsafe_url"
            if e.code.startswith("unsafe_url")
            else "not_html"
            if e.code == "not_html"
            else "unreachable"
        )
        return base | {"status": st, "error": e.code}
    except UnsafeUrl as e:
        return base | {"status": "unsafe_url", "error": e.code}
    if home_page.status >= 400:
        return base | {"status": "unreachable", "error": f"http_{home_page.status}"}

    final_url = home_page.url
    base["final_url"] = final_url
    base["domain"] = site_domain(final_url)
    html = home_page.html
    pages = [analyze_page(final_url, html)]
    if renderer is not None and len(html) < RENDER_IF_TEXT_BELOW * 4:
        try:
            rendered = await renderer.render(final_url)
            if rendered:
                pages = [analyze_page(final_url, rendered)]
        except Exception:  # noqa: BLE001, S110  - rendering is best-effort
            pass

    for link in pages[0].links:
        if len(pages) >= max(1, min(max_pages, 8)) or time.monotonic() - started > DEADLINE_SECONDS:
            break
        try:
            if not await fetcher.allowed(link):
                continue
            pg = await fetcher.get(link)
        except (FetchError, UnsafeUrl):
            continue
        if pg.status < 400:
            pages.append(analyze_page(pg.url, pg.html))

    base["pages_fetched"] = len(pages)
    domain = base["domain"]

    emails_raw: list[tuple[str, str]] = []
    phones: dict[str, tuple[str, str]] = {}
    social: dict[str, str] = {}
    for pf in pages:
        for addr in pf.emails:
            if addr not in [x[0] for x in emails_raw]:
                emails_raw.append((addr, pf.url))
        for e164, kind in pf.phones:
            phones.setdefault(e164, (kind, pf.url))
        for k, v in pf.social.items():
            social.setdefault(k, v)

    source = {e: u for e, u in emails_raw}
    if check_email:
        emails = await asyncio.wait_for(
            check_emails([e for e, _ in emails_raw], domain, resolver), timeout=15
        )
    else:
        emails = [
            {
                "email": addr,
                "status": "syntax_ok",
                "same_domain": addr.rsplit("@", 1)[1].removeprefix("www.") == domain,
            }
            for addr, _ in emails_raw[:5]
        ]
    for item in emails:
        item["source_url"] = source.get(str(item["email"]), final_url)
    phone_list = [{"e164": k, "type": v[0], "source_url": v[1]} for k, v in phones.items()]
    phone_pairs = [(p["e164"], p["type"]) for p in phone_list]

    gaps = find_gaps(pages, final_url, bool(phone_list))
    has_form = any(pf.has_form for pf in pages)
    home = pages[0]
    tech = sorted({t for pf in pages for t in pf.tech})
    return base | {
        "company": {
            "name": guess_name(home, domain),
            "title": home.title,
            "description": home.description,
            "lang": home.lang,
            "address": next((pf.address for pf in pages if pf.address), ""),
        },
        "emails": emails,
        "phones": phone_list,
        "social": social,
        "tech": tech,
        "tracking": {
            k: any(pf.tracking.get(k) for pf in pages)
            for k in ("ga", "gtm", "fb_pixel", "tiktok_pixel")
        },
        "has_form": has_form,
        "gaps": gaps,
        "score": lead_score(
            phones=phone_pairs, emails=emails, has_social=bool(social), has_form=has_form, gaps=gaps
        ),
    }
