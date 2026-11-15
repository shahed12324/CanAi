"""Shared types, error model, secret redaction and base-URL safety for provider adapters."""

from __future__ import annotations

import ipaddress
import re
import time
from collections.abc import AsyncIterator
from dataclasses import dataclass
from typing import Any, Literal
from urllib.parse import urlsplit

import httpx
from pydantic import BaseModel, Field, SecretStr

AdapterName = Literal[
    "openai", "anthropic", "gemini", "openrouter", "groq", "ollama", "openai_compatible"
]

MODEL_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/\-]{0,99}$")
_SECRET_LIKE = re.compile(r"(sk-[A-Za-z0-9_\-]{8,}|AIza[0-9A-Za-z_\-]{20,}|Bearer\s+\S+)")


class ProviderError(Exception):
    """Sanitised failure. `message` never contains credentials."""

    def __init__(
        self, code: str, message: str, *, status: int | None = None, retry_after: int | None = None
    ) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.status = status
        self.retry_after = retry_after


def redact(text: str, secrets: list[str] | None = None, limit: int = 300) -> str:
    for s in secrets or []:
        if s and len(s) >= 4:
            text = text.replace(s, "[redacted]")
    text = _SECRET_LIKE.sub("[redacted]", text)
    return text[:limit]


class ImagePart(BaseModel):
    """One image sent with a user message (re-encoded by the engine: no EXIF, bounded size)."""

    mime: Literal["image/jpeg", "image/png", "image/webp"]
    data: str = Field(max_length=8_000_000)  # base64, no data: prefix


class ChatMessage(BaseModel):
    role: Literal["system", "user", "assistant"]
    content: str = Field(max_length=200_000)
    images: list[ImagePart] | None = Field(default=None, max_length=6)


class ProviderSpec(BaseModel):
    adapter: AdapterName
    base_url: str | None = Field(default=None, max_length=300)
    api_key: SecretStr | None = None
    timeout_s: float = Field(default=60, ge=1, le=300)
    # Set by Laravel from the admin's provider record; allows localhost/private hosts (e.g. Ollama).
    allow_private: bool = False


class ChatRequest(BaseModel):
    model: str
    messages: list[ChatMessage] = Field(min_length=1, max_length=500)
    max_tokens: int | None = Field(default=None, ge=1, le=200_000)
    temperature: float | None = Field(default=None, ge=0, le=2)


@dataclass(frozen=True)
class ChatResult:
    text: str
    input_tokens: int | None
    output_tokens: int | None
    finish_reason: str | None
    model: str


@dataclass(frozen=True)
class StreamEvent:
    text: str = ""
    input_tokens: int | None = None
    output_tokens: int | None = None
    finish_reason: str | None = None


_METADATA_HOSTS = {"metadata.google.internal", "metadata", "instance-data"}


def validate_base_url(url: str, *, allow_private: bool) -> str:
    """Reject URLs that would let a provider record reach internal infrastructure (SSRF).

    Literal-IP/hostname checks only: DNS rebinding is not covered (documented in SECURITY.md).
    Cloud metadata addresses are refused even with allow_private.
    """
    parts = urlsplit(url)
    if parts.scheme not in ("http", "https") or not parts.hostname:
        raise ProviderError("invalid_base_url", "base_url must be an http(s) URL")
    if parts.username or parts.password:
        raise ProviderError("invalid_base_url", "credentials in base_url are not allowed")
    host = parts.hostname.lower().rstrip(".")
    private = host == "localhost" or host.endswith((".local", ".internal", ".localhost"))
    try:
        ip = ipaddress.ip_address(host)
        if ip.is_link_local or ip == ipaddress.ip_address("100.100.100.200"):
            raise ProviderError("invalid_base_url", "address not allowed")
        private = private or ip.is_private or ip.is_loopback or ip.is_reserved or ip.is_multicast
    except ValueError:
        pass
    if host in _METADATA_HOSTS:
        raise ProviderError("invalid_base_url", "address not allowed")
    if private and not allow_private:
        raise ProviderError("invalid_base_url", "private addresses are not allowed")
    if parts.scheme == "http" and not private and not allow_private:
        raise ProviderError("invalid_base_url", "https is required")
    return url.rstrip("/")


class Adapter:
    """Base class. Subclasses implement the wire format; this class owns HTTP and error mapping."""

    default_base_url: str | None = None
    name: str = "base"

    def __init__(self, spec: ProviderSpec, transport: httpx.AsyncBaseTransport | None = None):
        base = spec.base_url or self.default_base_url
        if not base:
            raise ProviderError("invalid_base_url", "base_url is required for this adapter")
        self.base_url = validate_base_url(base, allow_private=spec.allow_private)
        self._key = spec.api_key.get_secret_value() if spec.api_key else ""
        self._timeout = spec.timeout_s
        self._transport = transport

    # -- to be provided by subclasses --
    def headers(self) -> dict[str, str]:
        raise NotImplementedError

    async def complete(self, req: ChatRequest) -> ChatResult:
        raise NotImplementedError

    def stream(self, req: ChatRequest) -> AsyncIterator[StreamEvent]:
        raise NotImplementedError

    async def check(self) -> None:
        raise NotImplementedError

    # -- shared plumbing --
    def _client(self) -> httpx.AsyncClient:
        # No redirects: a redirect must never carry the credential to another host.
        return httpx.AsyncClient(
            base_url=self.base_url,
            timeout=self._timeout,
            transport=self._transport,
            follow_redirects=False,
        )

    def _clean(self, text: str) -> str:
        return redact(text, [self._key])

    def _raise_for(self, resp: httpx.Response) -> None:
        if resp.status_code < 300:
            return
        msg = ""
        try:
            data = resp.json()
            err = data.get("error", data) if isinstance(data, dict) else {}
            msg = str(err.get("message", "")) if isinstance(err, dict) else str(err)
        except ValueError:
            msg = ""
        s = resp.status_code
        retry = resp.headers.get("retry-after")
        retry_after = int(retry) if retry and retry.isdigit() else None
        code = (
            "auth_failed"
            if s in (401, 403)
            else "rate_limited"
            if s == 429
            else "bad_request"
            if s in (400, 404, 405, 409, 413, 422)
            else "upstream_error"
        )
        raise ProviderError(code, self._clean(msg) or code, status=s, retry_after=retry_after)

    def _json(self, resp: httpx.Response) -> dict[str, Any]:
        try:
            data = resp.json()
        except ValueError:
            raise ProviderError("bad_response", "provider returned a non-JSON body") from None
        if not isinstance(data, dict):
            raise ProviderError("bad_response", "unexpected provider response")
        return data

    async def _request(
        self, method: str, path: str, json: dict[str, object] | None = None
    ) -> httpx.Response:
        try:
            async with self._client() as c:
                resp = await c.request(method, path, headers=self.headers(), json=json)
        except httpx.TimeoutException:
            raise ProviderError("timeout", "provider timed out") from None
        except httpx.HTTPError:
            raise ProviderError("unreachable", "provider unreachable") from None
        self._raise_for(resp)
        return resp

    async def _stream_lines(self, path: str, json: dict[str, object]) -> AsyncIterator[str]:
        try:
            async with (
                self._client() as c,
                c.stream("POST", path, headers=self.headers(), json=json) as resp,
            ):
                if resp.status_code >= 300:
                    await resp.aread()
                    self._raise_for(resp)
                async for line in resp.aiter_lines():
                    yield line
        except httpx.TimeoutException:
            raise ProviderError("timeout", "provider timed out") from None
        except httpx.HTTPError:
            raise ProviderError("unreachable", "provider unreachable") from None


async def timed_check(adapter: Adapter) -> int:
    """Run the adapter's cheap connectivity check and return latency in ms."""
    t0 = time.perf_counter()
    await adapter.check()
    return int((time.perf_counter() - t0) * 1000)


def check_model(model: str) -> str:
    if not MODEL_RE.match(model):
        raise ProviderError("bad_request", "invalid model key")
    return model
