"""Laravel -> AI engine request authentication.

Every request carries:
  Authorization: Bearer <JWT HS256: iss, aud, iat, exp (<=60s), jti, tid>
  X-Timestamp:   unix seconds
  X-Nonce:       unique per request (must equal the JWT jti)
  X-Signature:   hex HMAC-SHA256 over the canonical string below

Canonical string (joined with "\n"):
  METHOD (upper) / PATH (no query) / SORTED_QUERY / TIMESTAMP / NONCE / SHA256_HEX(body)
"""

import hashlib
import hmac
import time
from dataclasses import dataclass
from typing import Annotated, Protocol

import jwt
from fastapi import Depends, HTTPException, Request, status

from app.config import Settings, get_settings


class NonceStore(Protocol):
    def claim(self, nonce: str, ttl_seconds: int) -> bool:
        """Return True if the nonce was unused (and is now claimed)."""


class InMemoryNonceStore:
    def __init__(self) -> None:
        self._seen: dict[str, float] = {}

    def claim(self, nonce: str, ttl_seconds: int) -> bool:
        now = time.monotonic()
        self._seen = {n: exp for n, exp in self._seen.items() if exp > now}
        if nonce in self._seen:
            return False
        self._seen[nonce] = now + ttl_seconds
        return True


class RedisNonceStore:
    def __init__(self, url: str) -> None:
        import redis

        self._r = redis.Redis.from_url(url)

    def claim(self, nonce: str, ttl_seconds: int) -> bool:
        return bool(self._r.set(f"ai:nonce:{nonce}", "1", nx=True, ex=ttl_seconds))


_store: NonceStore | None = None


def get_nonce_store(settings: Annotated[Settings, Depends(get_settings)]) -> NonceStore:
    global _store
    if _store is None:
        _store = RedisNonceStore(settings.redis_url) if settings.redis_url else InMemoryNonceStore()
    return _store


@dataclass(frozen=True)
class AuthContext:
    tenant_id: str
    user_id: str | None
    nonce: str


def canonical_string(
    method: str, path: str, query: str, timestamp: str, nonce: str, body: bytes
) -> str:
    sorted_query = "&".join(sorted(query.split("&"))) if query else ""
    return "\n".join(
        [method.upper(), path, sorted_query, timestamp, nonce, hashlib.sha256(body).hexdigest()]
    )


def sign(secret: str, canonical: str) -> str:
    return hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()


def _deny(reason: str) -> HTTPException:
    # Generic message to the caller; the reason is for logs/tests only.
    return HTTPException(
        status.HTTP_401_UNAUTHORIZED, detail="unauthorized", headers={"X-Auth-Reason": reason}
    )


async def require_signed_request(
    request: Request,
    settings: Annotated[Settings, Depends(get_settings)],
    store: Annotated[NonceStore, Depends(get_nonce_store)],
) -> AuthContext:
    h = request.headers
    timestamp, nonce, signature = h.get("x-timestamp"), h.get("x-nonce"), h.get("x-signature")
    auth = h.get("authorization", "")
    if not (timestamp and nonce and signature and auth.startswith("Bearer ")):
        raise _deny("missing_header")

    try:
        ts = int(timestamp)
    except ValueError:
        raise _deny("bad_timestamp") from None
    if abs(time.time() - ts) > settings.max_age_seconds:
        raise _deny("stale_timestamp")

    body = await request.body()
    expected = sign(
        settings.hmac_secret,
        canonical_string(
            request.method, request.url.path, request.url.query, timestamp, nonce, body
        ),
    )
    if not hmac.compare_digest(expected, signature):
        raise _deny("bad_signature")

    try:
        claims = jwt.decode(
            auth[7:],
            settings.jwt_secret,
            algorithms=["HS256"],
            audience=settings.jwt_audience,
            issuer=settings.jwt_issuer,
            options={"require": ["exp", "iat", "jti", "tid", "iss", "aud"]},
        )
    except jwt.PyJWTError:
        raise _deny("bad_token") from None

    if claims["exp"] - claims["iat"] > settings.max_age_seconds:
        raise _deny("token_too_long_lived")
    if claims["jti"] != nonce:
        raise _deny("nonce_mismatch")
    # Claim the nonce last, so a request that fails any check above cannot burn a valid nonce.
    if not store.claim(nonce, settings.max_age_seconds * 2):
        raise _deny("replay")

    return AuthContext(tenant_id=str(claims["tid"]), user_id=claims.get("uid"), nonce=nonce)
