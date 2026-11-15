"""Site SEO audit: homepage + a few internal pages + robots.txt + sitemap.xml.  Stateless; Laravel stores the result.

Polite and SSRF-safe because it reuses SafeFetcher (robots.txt is obeyed, every redirect hop and every connection is guarded).
Rule-based: no AI call, so it costs no credits.
"""

from __future__ import annotations

import re
import time
from typing import Any
from urllib.parse import urlsplit

from app.leads.crawler import normalize_start, site_domain
from app.leads.fetch import FetchError, SafeFetcher
from app.leads.ssrf import UnsafeUrl, parse_url
from app.seo.page import SKIP_EXT, PageSeo, analyze_html, internal_links, page_codes
from app.seo.rules import RULES, score

DEADLINE_SECONDS = 70.0
MAX_PAGES = 8
LOC_RE = re.compile(r"<loc>\s*([^<\s]+)\s*</loc>", re.I)


def _same_site(a: str, b: str) -> bool:
    return site_domain(a) == site_domain(b)


async def _sitemap_urls(fetcher: SafeFetcher, origin: str, home: str) -> tuple[bool, list[str]]:
    try:
        r = await fetcher.get_any(origin + "/sitemap.xml")
    except FetchError:
        return False, []
    head = r.html[:4000].lower()
    if r.status != 200 or ("<urlset" not in head and "<sitemapindex" not in head):
        return False, []
    urls: list[str] = []
    for loc in LOC_RE.findall(r.html):
        sp = urlsplit(loc)
        if sp.scheme in ("http", "https") and _same_site(loc, home) and not sp.path.lower().endswith(SKIP_EXT):
            urls.append(f"{sp.scheme}://{sp.netloc}{sp.path or '/'}")
    return True, urls


def _issue(code: str, urls: list[str]) -> dict[str, Any]:
    sev, label, advice = RULES[code]
    return {"code": code, "severity": sev, "label": label, "advice": advice, "count": len(urls), "pages": urls[:5]}


async def audit_site(url: str, fetcher: SafeFetcher, *, max_pages: int = 5, keyword: str | None = None) -> dict[str, Any]:
    started = time.monotonic()
    url = normalize_start(url)
    base: dict[str, Any] = {"status": "ok", "url": url, "final_url": url, "domain": ""}
    try:
        parse_url(url, allow_private=fetcher.allow_private)
    except UnsafeUrl as e:
        return base | {"status": "unsafe_url", "error": e.code}
    base["domain"] = site_domain(url)
    try:
        if not await fetcher.allowed(url):
            return base | {"status": "blocked_by_robots"}
        home = await fetcher.get(url)
    except FetchError as e:
        st = "unsafe_url" if e.code.startswith("unsafe_url") else "not_html" if e.code == "not_html" else "timeout" if e.code == "timeout" else "unreachable"
        return base | {"status": st, "error": e.code}
    except UnsafeUrl as e:
        return base | {"status": "unsafe_url", "error": e.code}
    if home.status >= 400:
        return base | {"status": "unreachable", "error": f"http_{home.status}"}

    final = home.url
    sp = urlsplit(final)
    origin = f"{sp.scheme}://{sp.netloc}"
    base["final_url"] = final
    base["domain"] = site_domain(final)

    pages: list[PageSeo] = [analyze_html(final, home.status, home.html)]
    has_sitemap, sm_urls = await _sitemap_urls(fetcher, origin, final)
    try:
        rob = await fetcher.get_any(origin + "/robots.txt")
        has_robots = rob.status == 200 and bool(rob.html.strip())
    except FetchError:
        has_robots = False

    want = max(1, min(max_pages, MAX_PAGES))
    seen = {final.rstrip("/")}
    for cand in internal_links(pages[0]) + sm_urls:
        if len(pages) >= want or time.monotonic() - started > DEADLINE_SECONDS:
            break
        if cand.rstrip("/") in seen:
            continue
        seen.add(cand.rstrip("/"))
        try:
            if not await fetcher.allowed(cand):
                continue
            pg = await fetcher.get(cand)
        except (FetchError, UnsafeUrl):
            continue
        pages.append(analyze_html(pg.url, pg.status, pg.html) if pg.status < 400 else PageSeo(url=pg.url, status=pg.status))

    codes_by_page: list[set[str]] = [page_codes(p, keyword if i == 0 else None) for i, p in enumerate(pages)]
    by_code: dict[str, list[str]] = {}
    for p, cs in zip(pages, codes_by_page, strict=True):
        for c in cs:
            by_code.setdefault(c, []).append(p.url)
    ok_pages = [p for p in pages if p.status < 400]
    for attr, code in (("title", "dup_title"), ("description", "dup_description")):
        groups: dict[str, list[str]] = {}
        for p in ok_pages:
            v = getattr(p, attr).strip().lower()
            if v:
                groups.setdefault(v, []).append(p.url)
        dups = [u for urls in groups.values() if len(urls) > 1 for u in urls]
        if dups:
            by_code[code] = dups
    if not final.lower().startswith("https://"):
        by_code["no_https"] = [final]
    if not has_sitemap:
        by_code["no_sitemap"] = [origin]
    if not has_robots:
        by_code["no_robots"] = [origin]

    order = {"high": 0, "medium": 1, "low": 2}
    issues = sorted((_issue(c, u) for c, u in by_code.items()), key=lambda i: (order[i["severity"]], -i["count"], i["code"]))
    return base | {
        "pages_checked": len(pages),
        "score": score(set(by_code)),
        "site": {"https": final.lower().startswith("https://"), "robots_txt": has_robots, "sitemap": has_sitemap, "sitemap_urls": len(sm_urls)},
        "issues": issues,
        "pages": [
            {"url": p.url, "status": p.status, "title": p.title[:160], "words": p.words, "issues": sorted(cs)}
            for p, cs in zip(pages, codes_by_page, strict=True)
        ],
        "keyword": (keyword or "").strip()[:80] or None,
    }
