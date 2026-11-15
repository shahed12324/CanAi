import json

import httpx
import pytest

from app.config import Settings, get_settings
from app.main import app
from app.routes_leads import set_test_hooks
from tests.conftest import HMAC_SECRET, JWT_SECRET, make_headers
from tests.test_leads import CONTACT, HOME, FakeResolver

PATH = "/internal/v1/leads/crawl"


@pytest.fixture
def lead_client(client):
    app.dependency_overrides[get_settings] = lambda: Settings(
        hmac_secret=HMAC_SECRET,
        jwt_secret=JWT_SECRET,
        env="test",
        crawler_allow_private=True,
        crawler_min_delay=0,
    )
    routes = {
        "/": (200, "text/html", HOME),
        "/contact": (200, "text/html", CONTACT),
        "/robots.txt": (404, "text/plain", ""),
    }
    set_test_hooks(
        httpx.MockTransport(
            lambda r: httpx.Response(
                routes.get(r.url.path, (404, "", ""))[0],
                headers={"content-type": "text/html"},
                text=routes.get(r.url.path, (404, "", "x"))[2],
            )
        ),
        FakeResolver(),
    )
    yield client
    set_test_hooks(None, None)


def post(c, payload, **kw):
    body = json.dumps(payload).encode()
    h = make_headers(method="POST", path=PATH, body=body, **kw) | {
        "content-type": "application/json"
    }
    return c.post(PATH, content=body, headers=h)


def test_unsigned_request_rejected(lead_client):
    assert lead_client.post(PATH, json={"url": "http://fixture.test/"}).status_code in (401, 403)


def test_signed_crawl_returns_facts(lead_client):
    r = post(lead_client, {"url": "http://fixture.test/", "max_pages": 2})
    assert r.status_code == 200
    j = r.json()
    assert j["status"] == "ok" and j["domain"] == "fixture.test" and j["pages_fetched"] == 2
    assert {e["email"] for e in j["emails"]} >= {"sales@fixture.test"}


def test_production_mode_ignores_allow_private(client):
    """Even if someone sets the flag in production, env != local/test keeps the SSRF guard on."""
    app.dependency_overrides[get_settings] = lambda: Settings(
        hmac_secret=HMAC_SECRET, jwt_secret=JWT_SECRET, env="production", crawler_allow_private=True
    )
    r = post(client, {"url": "http://127.0.0.1/"})
    assert r.status_code == 200 and r.json()["status"] == "unsafe_url"
    r = post(client, {"url": "http://169.254.169.254/latest/meta-data/"})
    assert r.json()["status"] == "unsafe_url"


def test_bad_body_is_422_without_echo(lead_client):
    r = post(lead_client, {"url": "x", "max_pages": 99})
    assert r.status_code == 422 and "99" not in r.text
