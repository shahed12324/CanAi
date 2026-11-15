import json

import httpx
import pytest

from app.config import Settings, get_settings
from app.leads.fetch import SafeFetcher
from app.main import app
from app.routes_leads import set_test_hooks
from app.seo.audit import audit_site
from app.seo.page import analyze_html, internal_links, page_codes
from app.seo.rules import RULES, WEIGHTS, score
from tests.conftest import HMAC_SECRET, JWT_SECRET, make_headers

LONG = " ".join(["আমরা ঢাকায় সেরা মানের পণ্য ও সেবা দিই"] * 60)
GOOD = f"""<!doctype html><html lang="bn"><head><title>আলো ডিজিটাল | ঢাকার সেরা ডিজিটাল মার্কেটিং এজেন্সি</title>
<meta name="description" content="আলো ডিজিটাল ঢাকায় ফেসবুক বিজ্ঞাপন, এসইও ও ওয়েবসাইট তৈরির কাজ করে। আজই বিনামূল্যে পরামর্শ নিন।">
<meta name="viewport" content="width=device-width, initial-scale=1"><link rel="canonical" href="https://fixture.test/">
<meta property="og:title" content="আলো"><script type="application/ld+json">{{}}</script></head>
<body><h1>ডিজিটাল মার্কেটিং এজেন্সি ঢাকা</h1><img src="a.png" alt="লোগো"><p>{LONG}</p>
<a href="/about">About</a> <a href="/about#team">again</a> <a href="/logo.png">img</a> <a href="https://other.example.net/x">off</a> <a href="mailto:a@b.c">m</a></body></html>"""
BAD = "<html><head><title>Hi</title><meta name='robots' content='noindex,follow'></head><body><h1>A</h1><h1>B</h1><img src=x.png><p>short</p></body></html>"
SITEMAP = '<?xml version="1.0"?><urlset><url><loc>http://fixture.test/</loc></url><url><loc>http://fixture.test/about</loc></url><url><loc>http://fixture.test/team</loc></url><url><loc>http://other.example.net/z</loc></url><url><loc>http://fixture.test/file.pdf</loc></url></urlset>'


def site(routes):
    seen: list[str] = []

    def handler(req: httpx.Request) -> httpx.Response:
        seen.append(req.url.path)
        status, ctype, body = routes.get(req.url.path, (404, "text/plain", "nope"))
        return httpx.Response(status, headers={"content-type": ctype}, text=body)

    return httpx.MockTransport(handler), seen


def fetcher(tr):
    return SafeFetcher(allow_private=True, transport=tr, min_delay=0)


# ---------- pure page analysis ----------
def test_good_page_has_no_issues_apart_from_nothing_flagged():
    p = analyze_html("https://fixture.test/", 200, GOOD)
    assert p.lang == "bn" and p.has_viewport and p.has_og and p.has_schema and len(p.h1) == 1
    assert p.images == 1 and p.images_no_alt == 0 and p.words > 300
    assert page_codes(p) == set()


def test_bad_page_codes():
    p = analyze_html("https://x.test/", 200, BAD)
    assert page_codes(p) >= {"title_short", "no_description", "multi_h1", "no_lang", "no_viewport", "no_canonical", "noindex", "img_no_alt", "thin_content", "no_og", "no_schema"}
    assert "no_title" not in page_codes(p)


def test_missing_title_and_boundaries():
    def codes(title, desc):
        html = f"<html lang=en><head><title>{title}</title><meta name=description content='{desc}'></head><body><h1>x</h1></body></html>"
        return page_codes(analyze_html("https://x.test/", 200, html))

    assert "no_title" in codes("", "d")
    assert "title_short" in codes("a" * 29, "d") and "title_short" not in codes("a" * 30, "d")
    assert "title_long" in codes("a" * 61, "d") and "title_long" not in codes("a" * 60, "d")
    assert "description_short" in codes("t", "d" * 69) and "description_short" not in codes("t", "d" * 70)
    assert "description_long" in codes("t", "d" * 161) and "description_long" not in codes("t", "d" * 160)
    assert "no_description" in codes("t", "")


def test_error_page_only_reports_page_error():
    assert page_codes(analyze_html("https://x.test/a", 404, "")) == {"page_error"}


def test_keyword_checks_only_when_given_and_are_case_insensitive():
    p = analyze_html("https://fixture.test/", 200, GOOD)
    assert page_codes(p, "ডিজিটাল মার্কেটিং") == set()
    assert page_codes(p, "unicorn") >= {"kw_not_in_title", "kw_not_in_h1", "kw_not_in_intro"}
    assert not any(c.startswith("kw_") for c in page_codes(p, "  "))


def test_scripts_do_not_count_as_words_and_images_without_alt_text_are_counted():
    p = analyze_html("https://x.test/", 200, "<html><body><script>var a = 'one two three four five';</script><img src=a alt=''><img src=b alt='ok'><p>one two</p></body></html>")
    assert p.words == 2 and p.images == 2 and p.images_no_alt == 1


def test_internal_links_same_host_no_files_deduped():
    p = analyze_html("https://www.fixture.test/", 200, GOOD.replace("https://fixture.test/", "https://www.fixture.test/"))
    assert internal_links(p) == ["https://www.fixture.test/about"]


def test_score_counts_each_kind_once_and_never_negative():
    assert score(set()) == 100
    assert score({"no_title"}) == 100 - WEIGHTS["high"]
    assert score(set(RULES)) == 0
    assert all(sev in WEIGHTS for sev, _, _ in RULES.values())


# ---------- site audit ----------
async def test_clean_site_scores_100_with_sitemap_and_robots():
    tr, seen = site({"/": (200, "text/html", GOOD), "/about": (200, "text/html", GOOD.replace("আলো ডিজিটাল |", "আমাদের কথা |").replace("এসইও ও", "এসইও এবং")), "/team": (200, "text/html", GOOD.replace("आलो", "x").replace("আলো ডিজিটাল |", "আমাদের দল |").replace("এসইও ও", "এসইও আর")), "/robots.txt": (200, "text/plain", "User-agent: *\nAllow: /\nSitemap: http://fixture.test/sitemap.xml"), "/sitemap.xml": (200, "application/xml", SITEMAP)})
    r = await audit_site("http://fixture.test/", fetcher(tr), max_pages=3)
    assert r["status"] == "ok" and r["pages_checked"] == 3 and r["site"] == {"https": False, "robots_txt": True, "sitemap": True, "sitemap_urls": 3}
    assert [i["code"] for i in r["issues"]] == ["no_https"]  # plain http is the only finding
    assert "/z" not in seen and "/file.pdf" not in seen  # other hosts and files are never fetched


async def test_site_findings_sorted_high_first_and_pages_listed():
    tr, _ = site({"/": (200, "text/html", BAD), "/robots.txt": (404, "text/plain", "")})
    r = await audit_site("http://fixture.test/", fetcher(tr), max_pages=2)
    sev = [i["severity"] for i in r["issues"]]
    assert sev == sorted(sev, key={"high": 0, "medium": 1, "low": 2}.get)
    codes = {i["code"] for i in r["issues"]}
    assert {"no_https", "no_sitemap", "no_robots", "noindex", "no_viewport"} <= codes
    assert r["score"] == score(codes) and 0 <= r["score"] < 40
    first = r["issues"][0]
    assert first["pages"] and first["label"] and first["advice"]


async def test_duplicate_titles_and_broken_internal_pages():
    home = GOOD.replace('<a href="/about">About</a>', '<a href="/a">a</a><a href="/b">b</a><a href="/gone">c</a>').replace('<a href="/about#team">again</a>', "")
    tr, _ = site({"/": (200, "text/html", home), "/a": (200, "text/html", GOOD), "/b": (200, "text/html", GOOD), "/gone": (404, "text/html", "nope")})
    r = await audit_site("http://fixture.test/", fetcher(tr), max_pages=5)
    by = {i["code"]: i for i in r["issues"]}
    assert by["dup_title"]["count"] == 3 and by["dup_description"]["count"] == 3
    assert by["page_error"]["pages"] == ["http://fixture.test/gone"]
    gone = next(p for p in r["pages"] if p["url"].endswith("/gone"))
    assert gone["status"] == 404 and gone["issues"] == ["page_error"]


async def test_keyword_only_judged_on_homepage():
    tr, _ = site({"/": (200, "text/html", GOOD), "/about": (200, "text/html", BAD)})
    r = await audit_site("http://fixture.test/", fetcher(tr), max_pages=2, keyword="unicorn")
    home, about = r["pages"][0], r["pages"][1]
    assert "kw_not_in_title" in home["issues"] and not any(c.startswith("kw_") for c in about["issues"])
    assert r["keyword"] == "unicorn"


async def test_robots_disallow_blocks_the_whole_audit_and_page_limit_is_respected():
    tr, seen = site({"/": (200, "text/html", GOOD), "/robots.txt": (200, "text/plain", "User-agent: *\nDisallow: /")})
    assert (await audit_site("http://fixture.test/", fetcher(tr)))["status"] == "blocked_by_robots"
    assert seen == ["/robots.txt"]
    links = "".join(f'<a href="/p{i}">x</a>' for i in range(20))
    tr2, seen2 = site({"/": (200, "text/html", GOOD.replace('<a href="/about">About</a>', links))} | {f"/p{i}": (200, "text/html", GOOD) for i in range(20)})
    r = await audit_site("http://fixture.test/", fetcher(tr2), max_pages=3)
    assert r["pages_checked"] == 3 and len([p for p in seen2 if p.startswith("/p")]) == 2


async def test_failure_statuses():
    tr, _ = site({"/": (200, "application/pdf", "x")})
    assert (await audit_site("http://fixture.test/", fetcher(tr)))["status"] == "not_html"
    tr2, _ = site({"/": (503, "text/html", "down")})
    r = await audit_site("http://fixture.test/", fetcher(tr2))
    assert r["status"] == "unreachable" and r["error"] == "http_503"
    strict = SafeFetcher(allow_private=False, transport=tr2, min_delay=0)
    for bad in ("http://127.0.0.1/", "http://localhost/", "ftp://x.test/", "http://10.0.0.1/", "http://a.test:22/"):
        assert (await audit_site(bad, strict))["status"] == "unsafe_url"


# ---------- signed route ----------
PATH = "/internal/v1/seo/audit"


@pytest.fixture
def seo_client(client):
    app.dependency_overrides[get_settings] = lambda: Settings(hmac_secret=HMAC_SECRET, jwt_secret=JWT_SECRET, env="test", crawler_allow_private=True, crawler_min_delay=0)
    tr, _ = site({"/": (200, "text/html", GOOD), "/robots.txt": (404, "text/plain", "")})
    set_test_hooks(tr, None)
    yield client
    set_test_hooks(None, None)


def post(c, payload, **kw):
    body = json.dumps(payload).encode()
    h = make_headers(method="POST", path=PATH, body=body, **kw) | {"content-type": "application/json"}
    return c.post(PATH, content=body, headers=h)


def test_route_requires_a_signature(seo_client):
    assert seo_client.post(PATH, json={"url": "http://fixture.test/"}).status_code in (401, 403)


def test_route_returns_the_audit_and_validates_input(seo_client):
    r = post(seo_client, {"url": "http://fixture.test/", "max_pages": 1})
    assert r.status_code == 200 and r.json()["status"] == "ok" and r.json()["pages_checked"] == 1
    assert post(seo_client, {"url": "http://fixture.test/", "max_pages": 99}).status_code == 422
    assert post(seo_client, {"url": "x"}).status_code == 422
    assert post(seo_client, {"url": "http://fixture.test/", "keyword": "k" * 81}).status_code == 422


def test_route_refuses_private_addresses_in_production_mode(client):
    app.dependency_overrides[get_settings] = lambda: Settings(hmac_secret=HMAC_SECRET, jwt_secret=JWT_SECRET, env="production", crawler_allow_private=True, crawler_min_delay=0)
    set_test_hooks(None, None)
    r = post(client, {"url": "http://127.0.0.1/"})
    assert r.json()["status"] == "unsafe_url"


# ---------- boundaries the mutation check found untested ----------
def _words(n):
    return analyze_html("https://x.test/", 200, "<html><body><p>" + " ".join(["শব্দ"] * n) + "</p></body></html>")


def test_thin_content_boundary_is_300_words():
    assert "thin_content" in page_codes(_words(299)) and "thin_content" not in page_codes(_words(300))


def test_score_weights_are_fixed_numbers():
    assert WEIGHTS == {"high": 15, "medium": 7, "low": 3}
    assert score({"no_title", "title_short", "no_og"}) == 100 - 15 - 7 - 3


async def test_exactly_two_pages_with_the_same_title_are_flagged():
    home = GOOD.replace('<a href="/about">About</a>', '<a href="/a">a</a>').replace('<a href="/about#team">again</a>', "")
    other = GOOD.replace("এসইও ও", "এসইও এবং")  # same title, different description
    tr, _ = site({"/": (200, "text/html", home), "/a": (200, "text/html", other)})
    by = {i["code"]: i for i in (await audit_site("http://fixture.test/", fetcher(tr), max_pages=2))["issues"]}
    assert by["dup_title"]["count"] == 2 and "dup_description" not in by
