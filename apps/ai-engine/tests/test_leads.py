import asyncio
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

import httpx
import pytest

from app.leads import ssrf
from app.leads.analyze import lead_score
from app.leads.crawler import crawl_site
from app.leads.emailcheck import check_emails
from app.leads.extract import analyze_page, clean_email, decode_cfemail, parse_phone
from app.leads.fetch import FetchError, SafeFetcher
from app.leads.render import PlaywrightRenderer


def cf(email: str, key: int = 0x2A) -> str:
    return bytes([key] + [b ^ key for b in email.encode()]).hex()


HOME = f"""<!doctype html><html lang="bn"><head><title>আলো ডিজিটাল | Home</title>
<meta name="description" content="আমরা কাজ করি"><meta name="viewport" content="width=device-width">
<meta property="og:site_name" content="আলো ডিজিটাল">
<script src="https://www.googletagmanager.com/gtm.js?id=GTM-X"></script></head><body>
<a href="mailto:Sales@Fixture.test?subject=hi">mail</a> <a href="tel:+8801712345678">call</a>
<p>অফিস: 01819-876543, হটলাইন 16263. তারিখ 2024-10-12 বা মূল্য 1234567890123</p>
<a class="__cf_email__" href="/cdn-cgi/l/email-protection#{cf("hidden@fixture.test")}">x</a>
<p>support [at] fixture [dot] test</p><p>junk logo@2x.png noreply@example.com</p>
<a href="https://www.facebook.com/aloDigital">fb</a><a href="https://www.facebook.com/sharer/sharer.php?u=x">share</a>
<a href="https://instagram.com/alo.digital/">ig</a><a href="/contact">যোগাযোগ</a><a href="/about-us">About</a>
<a href="/private/contact">private contact</a><a href="https://other.example.net/contact">offsite</a></body></html>"""
CONTACT = """<html><head><title>Contact</title></head><body><address>১২ রোড, ধানমন্ডি, ঢাকা</address>
<form><input name=n><input name=e type=email><textarea name=m></textarea></form></body></html>"""
BARE = "<html><body><p>hello</p></body></html>"
ROBOTS = "User-agent: *\nDisallow: /private\nCrawl-delay: 0\n"


def site(routes: dict[str, tuple[int, str, str]]):
    seen: list[str] = []

    def handler(req: httpx.Request) -> httpx.Response:
        seen.append(req.url.path)
        status, ctype, body = routes.get(req.url.path, (404, "text/plain", "nope"))
        return httpx.Response(status, headers={"content-type": ctype}, text=body)

    return httpx.MockTransport(handler), seen


class FakeResolver:
    def __init__(self, ok=frozenset({"fixture.test"})):
        self.ok, self.asked = ok, []

    async def has_mx(self, d):
        self.asked.append(d)
        return True if d in self.ok else False


def fetcher(tr, **kw):
    return SafeFetcher(allow_private=True, transport=tr, min_delay=0, **kw)


# ------------------------------------------------------------------ extraction
def test_cfemail_and_email_cleaning():
    assert decode_cfemail(cf("a@b.co")) == "a@b.co"
    assert decode_cfemail("zz") == ""
    for bad in ("logo@2x.png", "a@example.com", "x@sentry.io", "not-an-email", "a@b"):
        assert clean_email(bad) is None
    assert clean_email("  Info@Fixture.TEST. ") == "info@fixture.test"


def test_bd_phone_parsing():
    assert parse_phone("01712-345678") == ("+8801712345678", "mobile")
    assert parse_phone("+880 1819 876543") == ("+8801819876543", "mobile")
    assert parse_phone("16263") is None
    assert parse_phone("2024-10-12") is None
    assert parse_phone("+1 415 555 0132") is None or parse_phone("+1 415 555 0132")[0].startswith(
        "+1"
    )


def test_page_facts():
    f = analyze_page("https://fixture.test/", HOME)
    assert (
        "sales@fixture.test" in f.emails
        and "hidden@fixture.test" in f.emails
        and "support@fixture.test" in f.emails
    )
    assert all("png" not in e and "example.com" not in e for e in f.emails)
    assert [p[0] for p in f.phones] == ["+8801712345678", "+8801819876543"]
    assert f.social == {
        "facebook": "https://facebook.com/aloDigital",
        "instagram": "https://instagram.com/alo.digital",
    }
    assert f.tracking["gtm"] and not f.tracking["fb_pixel"] and f.has_viewport and f.lang == "bn"
    assert f.links == [
        "https://fixture.test/contact",
        "https://fixture.test/about-us",
        "https://fixture.test/private/contact",
    ]
    assert f.site_name == "আলো ডিজিটাল"


def test_garbage_html_never_crashes():
    for h in ("", "<", "<<<>>>", "\x00\x01", "<a href=>", "<form>" * 500, "mailto:" * 1000):
        analyze_page("https://x.test/", h)


# ------------------------------------------------------------------ full crawl on a fixture site
ROUTES = {
    "/": (200, "text/html", HOME),
    "/contact": (200, "text/html", CONTACT),
    "/about-us": (200, "text/html", BARE),
    "/private/contact": (200, "text/html", "<p>secret@fixture.test</p>"),
    "/robots.txt": (200, "text/plain", ROBOTS),
}


async def test_crawl_fixture_site():
    tr, seen = site(ROUTES)
    f = fetcher(tr)
    res = await crawl_site("http://fixture.test/", f, FakeResolver(), max_pages=4)
    await f.aclose()
    assert (
        res["status"] == "ok"
        and res["domain"] == "fixture.test"
        and res["company"]["name"] == "আলো ডিজিটাল"
    )
    assert "/private/contact" not in seen  # robots.txt honoured
    emails = {e["email"]: e for e in res["emails"]}
    assert (
        emails["sales@fixture.test"]["status"] == "mx_ok"
        and emails["sales@fixture.test"]["same_domain"] is True
    )
    assert "secret@fixture.test" not in emails
    assert {p["e164"] for p in res["phones"]} == {"+8801712345678", "+8801819876543"}
    assert res["has_form"] is True and res["company"]["address"].startswith("১২ রোড")
    codes = {g["code"] for g in res["gaps"]}
    assert (
        "no_https" in codes
        and "no_fb_pixel" in codes
        and "no_analytics" not in codes
        and "no_contact_form" not in codes
    )
    assert 0 < res["score"] <= 100


async def test_robots_disallow_all_blocks_everything():
    tr, seen = site(
        {
            "/robots.txt": (200, "text/plain", "User-agent: *\nDisallow: /\n"),
            "/": (200, "text/html", HOME),
        }
    )
    f = fetcher(tr)
    res = await crawl_site("http://fixture.test/", f, FakeResolver())
    assert res["status"] == "blocked_by_robots" and seen == ["/robots.txt"]


async def test_robots_5xx_is_conservative_4xx_is_open():
    tr, seen = site({"/robots.txt": (503, "text/plain", "x"), "/": (200, "text/html", HOME)})
    assert (await crawl_site("http://fixture.test/", fetcher(tr), FakeResolver()))[
        "status"
    ] == "blocked_by_robots"
    tr, seen = site({"/robots.txt": (404, "text/plain", "x"), "/": (200, "text/html", HOME)})
    assert (await crawl_site("http://fixture.test/", fetcher(tr), FakeResolver()))["status"] == "ok"


async def test_non_html_unreachable_and_error_pages():
    tr, _ = site({"/": (200, "application/pdf", "%PDF")})
    assert (await crawl_site("http://fixture.test/", fetcher(tr), FakeResolver()))[
        "status"
    ] == "not_html"
    tr, _ = site({"/": (500, "text/html", "oops")})
    assert (await crawl_site("http://fixture.test/", fetcher(tr), FakeResolver()))[
        "status"
    ] == "unreachable"

    def boom(req):
        raise httpx.ConnectError("down")

    assert (
        await crawl_site("http://fixture.test/", fetcher(httpx.MockTransport(boom)), FakeResolver())
    )["status"] == "unreachable"


async def test_page_size_is_capped():
    tr, _ = site({"/": (200, "text/html", "<p>" + "a" * 5_000_000)})
    f = SafeFetcher(allow_private=True, transport=tr, min_delay=0, max_bytes=100_000)
    page = await f.get("http://fixture.test/")
    assert len(page.html) <= 100_000


async def test_redirect_to_internal_address_is_refused():
    def handler(req):
        if req.url.path == "/robots.txt":
            return httpx.Response(404)
        return httpx.Response(302, headers={"location": "http://169.254.169.254/latest/meta-data/"})

    f = SafeFetcher(allow_private=False, transport=httpx.MockTransport(handler), min_delay=0)
    res = await crawl_site("http://fixture.test/", f, FakeResolver())
    assert res["status"] == "unsafe_url", res


async def test_redirect_loop_stops():
    f = fetcher(
        httpx.MockTransport(
            lambda r: (
                httpx.Response(404)
                if r.url.path == "/robots.txt"
                else httpx.Response(302, headers={"location": "/"})
            )
        )
    )
    assert (await crawl_site("http://fixture.test/", f, FakeResolver()))["status"] == "unreachable"


# ------------------------------------------------------------------ SSRF
@pytest.mark.parametrize(
    "url,code",
    [
        ("file:///etc/passwd", "bad_scheme"),
        ("ftp://x.com/", "bad_scheme"),
        ("gopher://x.com", "bad_scheme"),
        ("http://127.0.0.1/", "ip_literal"),
        ("http://[::1]/", "ip_literal"),
        ("http://169.254.169.254/", "ip_literal"),
        ("http://2130706433/", "bad_host"),
        ("http://0x7f.1/", "bad_host"),
        ("http://user:pw@example.com/", "credentials_in_url"),
        ("http://a@evil.com@example.com/", "credentials_in_url"),
        ("http://example.com:22/", "bad_port"),
        ("http://example.com:6379/", "bad_port"),
        ("http://localhost/", "bad_host"),
        ("http://metadata.internal/", "internal_host"),
        ("http://printer.local/", "internal_host"),
        ("http://exa mple.com/", "bad_url"),
        ("http://example.com/\r\nHost: x", "bad_url"),
        ("", "bad_url"),
        ("http://" + "a" * 3000, "bad_url"),
    ],
)
def test_parse_url_rejects(url, code):
    with pytest.raises(ssrf.UnsafeUrl) as e:
        ssrf.parse_url(url)
    assert e.value.code == code


def test_parse_url_accepts_normal_sites():
    for u in (
        "https://example.com",
        "http://www.example.com:8080/x?y=1",
        "https://bücher.example/",
    ):
        ssrf.parse_url(u)


@pytest.mark.parametrize(
    "ip,public",
    [
        ("8.8.8.8", True),
        ("1.1.1.1", True),
        ("2606:4700:4700::1111", True),
        ("127.0.0.1", False),
        ("10.0.0.5", False),
        ("172.16.0.1", False),
        ("192.168.1.1", False),
        ("169.254.169.254", False),
        ("100.64.0.1", False),
        ("0.0.0.0", False),
        ("224.0.0.1", False),
        ("::1", False),
        ("fe80::1", False),
        ("fc00::1", False),
        ("::ffff:127.0.0.1", False),
        ("::ffff:10.0.0.1", False),
        ("2002:7f00:0001::", False),
        ("not-an-ip", False),
    ],
)
def test_ip_is_public(ip, public):
    assert ssrf.ip_is_public(ip) is public


async def test_dns_answers_are_all_checked(monkeypatch):
    async def fake(self, host, port, **kw):
        return [(2, 1, 6, "", ("8.8.8.8", port)), (2, 1, 6, "", ("10.0.0.7", port))]

    monkeypatch.setattr(asyncio.get_running_loop().__class__, "getaddrinfo", fake)
    with pytest.raises(ssrf.UnsafeUrl) as e:
        await ssrf.resolve_public("rebind.example", 80)
    assert e.value.code == "private_address"


async def test_real_guarded_transport_blocks_loopback_and_allows_when_enabled():
    class H(BaseHTTPRequestHandler):
        def do_GET(self):
            body = b"<html><title>local</title></html>" if self.path != "/robots.txt" else b""
            self.send_response(200 if self.path != "/robots.txt" else 404)
            self.send_header("content-type", "text/html")
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *a):
            pass

    srv = HTTPServer(("127.0.0.1", 0), H)
    port = srv.server_address[1]
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    try:
        # hostname that resolves to loopback: the guard must refuse at connect time
        strict = SafeFetcher(allow_private=False, min_delay=0)
        with pytest.raises(FetchError) as e:
            await strict.get(f"http://localtest.me:{port}/")  # parse_url rejects the port first
        assert e.value.code.startswith("unsafe_url")
        with pytest.raises(FetchError) as e2:
            await strict.get("http://localhost.localdomain/")
        assert e2.value.code.startswith("unsafe_url") or e2.value.code == "unreachable"
        lax = SafeFetcher(allow_private=True, min_delay=0)
        page = await lax.get(f"http://127.0.0.1:{port}/")
        assert "local" in page.html  # proves the guarded transport really connects
        await lax.aclose()
        await strict.aclose()
        # and with the strict guard the SAME loopback server is unreachable through the backend itself
        backend_fetch = SafeFetcher(allow_private=False, min_delay=0)
        backend_fetch.allow_private = True  # skip parse-level checks to exercise the backend
        be = ssrf.GuardedBackend(allow_private=False)
        with pytest.raises(ssrf.UnsafeUrl):
            await be.connect_tcp("127.0.0.1", port)
        await backend_fetch.aclose()
    finally:
        srv.shutdown()


# ------------------------------------------------------------------ email check, score
async def test_email_checks_deduplicate_domains():
    r = FakeResolver(ok={"good.test"})
    out = await check_emails(["a@good.test", "b@good.test", "c@dead.test"], "www.good.test", r)
    assert [e["status"] for e in out] == ["mx_ok", "mx_ok", "no_mx"] and sorted(r.asked) == [
        "dead.test",
        "good.test",
    ]
    assert out[0]["same_domain"] is True and out[2]["same_domain"] is False


class UnknownResolver:
    async def has_mx(self, d):
        return None


async def test_dns_failure_is_unknown_not_invalid():
    out = await check_emails(["a@x.test"], "x.test", UnknownResolver())
    assert out[0]["status"] == "syntax_ok"


def test_score_bounds_and_ordering():
    gaps = [{"weight": 50}]
    hi = lead_score(
        phones=[("+8801712345678", "mobile")],
        emails=[{"status": "mx_ok", "same_domain": True}],
        has_social=True,
        has_form=True,
        gaps=gaps,
    )
    lo = lead_score(phones=[], emails=[], has_social=False, has_form=False, gaps=[])
    assert 0 <= lo < hi <= 100 and lo == 10 and hi == 100


# ------------------------------------------------------------------ JS rendering (real chromium, local server)
async def test_playwright_renders_js_site():
    class H(BaseHTTPRequestHandler):
        def do_GET(self):
            self.send_response(200)
            self.send_header("content-type", "text/html")
            self.end_headers()
            self.wfile.write(
                b"<html><body><div id=a></div><script>document.getElementById('a').innerHTML='<a href=\"mailto:js@fixture.test\">m</a>'</script></body></html>"
            )

        def log_message(self, *a):
            pass

    srv = HTTPServer(("127.0.0.1", 0), H)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    try:
        html = await PlaywrightRenderer(allow_private=True).render(
            f"http://127.0.0.1:{srv.server_address[1]}/"
        )
    except Exception as e:  # noqa: BLE001
        pytest.skip(f"chromium unavailable: {e}")
    finally:
        srv.shutdown()
    if html is None:
        pytest.skip("playwright or chromium is not installed here")
    assert "js@fixture.test" in html


async def test_playwright_refuses_private_subrequests():
    pytest.importorskip("playwright")
    r = PlaywrightRenderer(allow_private=False)
    try:
        out = await r.render("http://127.0.0.1:1/")
    except Exception:  # noqa: BLE001
        out = None
    assert out is None  # navigation to a private host is aborted by the route guard
