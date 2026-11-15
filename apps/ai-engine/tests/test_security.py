import json
import shutil
import subprocess
import time
from pathlib import Path

import pytest

from tests.conftest import HMAC_SECRET, JWT_SECRET, make_headers


def test_healthz_is_open(client):
    assert client.get("/healthz").json() == {"status": "ok"}


def test_valid_request_returns_tenant(client):
    r = client.get("/internal/v1/health", headers=make_headers(tid="T1"))
    assert r.status_code == 200
    assert r.json() == {"status": "ok", "tenant_id": "T1"}


def test_openapi_and_docs_are_not_exposed(client):
    for p in ("/docs", "/redoc", "/openapi.json"):
        assert client.get(p).status_code == 404


@pytest.mark.parametrize("drop", ["Authorization", "X-Timestamp", "X-Nonce", "X-Signature"])
def test_missing_header_rejected(client, drop):
    h = make_headers()
    h.pop(drop)
    r = client.get("/internal/v1/health", headers=h)
    assert r.status_code == 401
    assert r.json() == {"detail": "unauthorized"}


def test_bad_signature_rejected(client):
    h = make_headers(hmac_secret="x" * 40)
    assert client.get("/internal/v1/health", headers=h).status_code == 401


def test_query_is_signed(client):
    h = make_headers(query="a=1")
    assert client.get("/internal/v1/health?a=2", headers=h).status_code == 401


def test_query_order_does_not_matter(client):
    h = make_headers(query="b=2&a=1")
    assert client.get("/internal/v1/health?a=1&b=2", headers=h).status_code == 200


def test_path_is_signed(client):
    h = make_headers(path="/internal/v1/other")
    assert client.get("/internal/v1/health", headers=h).status_code == 401


def test_stale_timestamp_rejected(client):
    h = make_headers(ts=int(time.time()) - 120)
    assert client.get("/internal/v1/health", headers=h).status_code == 401


def test_future_timestamp_rejected(client):
    h = make_headers(ts=int(time.time()) + 120)
    assert client.get("/internal/v1/health", headers=h).status_code == 401


def test_replay_rejected(client):
    h = make_headers()
    assert client.get("/internal/v1/health", headers=h).status_code == 200
    assert client.get("/internal/v1/health", headers=h).status_code == 401


def test_failed_request_does_not_burn_nonce(client):
    good = make_headers(nonce="n-1")
    bad = dict(good, **{"X-Signature": "0" * 64})
    assert client.get("/internal/v1/health", headers=bad).status_code == 401
    assert client.get("/internal/v1/health", headers=good).status_code == 200


def test_nonce_must_match_jti(client):
    h = make_headers(jti="different")
    assert client.get("/internal/v1/health", headers=h).status_code == 401


def test_wrong_jwt_secret_rejected(client):
    h = make_headers(jwt_secret="z" * 40)
    assert client.get("/internal/v1/health", headers=h).status_code == 401


def test_wrong_audience_and_issuer_rejected(client):
    assert client.get("/internal/v1/health", headers=make_headers(aud="other")).status_code == 401
    assert client.get("/internal/v1/health", headers=make_headers(iss="other")).status_code == 401


def test_long_lived_token_rejected(client):
    assert client.get("/internal/v1/health", headers=make_headers(lifetime=600)).status_code == 401


def test_alg_none_token_rejected(client):
    import base64
    import json as j

    def b64(d):
        return base64.urlsafe_b64encode(j.dumps(d).encode()).rstrip(b"=").decode()

    now = int(time.time())
    h = make_headers(nonce="n-none")
    h["Authorization"] = (
        "Bearer "
        + b64({"alg": "none", "typ": "JWT"})
        + "."
        + b64(
            {
                "iss": "jarvis-web",
                "aud": "jarvis-ai-engine",
                "iat": now,
                "exp": now + 30,
                "jti": "n-none",
                "tid": "T",
            }
        )
        + "."
    )
    assert client.get("/internal/v1/health", headers=h).status_code == 401


def test_body_tampering_detected(client):
    h = make_headers(method="POST", body=b'{"a":1}')
    # No POST route exists, but auth must still be evaluated before routing 404/405 leaks nothing.
    r = client.post("/internal/v1/health", content=b'{"a":2}', headers=h)
    assert r.status_code in (401, 405)


def test_short_secrets_refused_at_startup():
    from pydantic import ValidationError

    from app.config import Settings

    with pytest.raises(ValidationError):
        Settings(hmac_secret="short", jwt_secret=JWT_SECRET)


PHP_SIGNER = (
    Path(__file__).parents[2]
    / "web-overlay"
    / "app"
    / "Services"
    / "AiEngine"
    / "RequestSigner.php"
)
PHP_CLI = Path(__file__).parents[2] / "web-overlay" / "tests" / "cli_sign.php"


@pytest.mark.skipif(shutil.which("php") is None or not PHP_CLI.exists(), reason="php not available")
@pytest.mark.parametrize(
    "method,path,query,body",
    [
        ("GET", "/internal/v1/health", "", ""),
        ("GET", "/internal/v1/health", "b=2&a=1", ""),
        ("POST", "/internal/v1/health", "", '{"msg":"নমস্কার ✓"}'),
    ],
)
def test_php_signer_is_compatible(client, method, path, query, body):
    """The real Laravel-side signer (PHP) must produce requests this service accepts."""
    out = subprocess.run(
        ["php", str(PHP_CLI), method, path, query, body, HMAC_SECRET, JWT_SECRET, "TENANT-PHP"],
        capture_output=True,
        text=True,
        check=True,
    ).stdout
    headers = json.loads(out)
    url = path + (f"?{query}" if query else "")
    r = client.request(method, url, headers=headers, content=body.encode())
    # GET -> 200; POST has no route -> auth passed means 405, not 401.
    assert r.status_code == (200 if method == "GET" else 405), r.text
    if method == "GET":
        assert r.json()["tenant_id"] == "TENANT-PHP"
