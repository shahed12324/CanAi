import time
import uuid

import jwt
import pytest
from fastapi.testclient import TestClient

from app.config import Settings, get_settings
from app.main import app
from app.security import InMemoryNonceStore, canonical_string, get_nonce_store, sign

HMAC_SECRET = "h" * 40
JWT_SECRET = "j" * 40


@pytest.fixture
def settings() -> Settings:
    return Settings(hmac_secret=HMAC_SECRET, jwt_secret=JWT_SECRET)


@pytest.fixture
def client(settings: Settings):
    store = InMemoryNonceStore()
    app.dependency_overrides[get_settings] = lambda: settings
    app.dependency_overrides[get_nonce_store] = lambda: store
    yield TestClient(app)
    app.dependency_overrides.clear()


def make_headers(
    *,
    method="GET",
    path="/internal/v1/health",
    query="",
    body=b"",
    tid="01TENANT",
    ts=None,
    nonce=None,
    hmac_secret=HMAC_SECRET,
    jwt_secret=JWT_SECRET,
    aud="jarvis-ai-engine",
    iss="jarvis-web",
    lifetime=60,
    jti=None,
):
    now = int(time.time())
    ts = now if ts is None else ts
    nonce = nonce or uuid.uuid4().hex
    token = jwt.encode(
        {
            "iss": iss,
            "aud": aud,
            "iat": now,
            "exp": now + lifetime,
            "jti": jti or nonce,
            "tid": tid,
        },
        jwt_secret,
        algorithm="HS256",
    )
    sig = sign(hmac_secret, canonical_string(method, path, query, str(ts), nonce, body))
    return {
        "Authorization": f"Bearer {token}",
        "X-Timestamp": str(ts),
        "X-Nonce": nonce,
        "X-Signature": sig,
    }
