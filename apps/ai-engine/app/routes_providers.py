"""Internal provider endpoints (signed requests only). Credentials are accepted in the body
and are never echoed, logged or stored."""

from __future__ import annotations

import json
from collections.abc import AsyncIterator
from typing import Annotated

import httpx
from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse, StreamingResponse
from pydantic import BaseModel

from app.providers.base import (
    Adapter,
    ChatRequest,
    ProviderError,
    ProviderSpec,
    timed_check,
)
from app.providers.registry import build_adapter
from app.security import AuthContext, require_signed_request

router = APIRouter(prefix="/internal/v1")

# Tests replace this to run adapters against httpx.MockTransport.
_transport: httpx.AsyncBaseTransport | None = None


def set_transport(t: httpx.AsyncBaseTransport | None) -> None:
    global _transport
    _transport = t


class TestRequest(BaseModel):
    __test__ = False
    provider: ProviderSpec


class CompleteRequest(BaseModel):
    provider: ProviderSpec
    request: ChatRequest


def _err(e: ProviderError) -> JSONResponse:
    status = {"auth_failed": 502, "rate_limited": 429, "timeout": 504}.get(e.code, 502)
    if e.code in ("invalid_base_url", "bad_request"):
        status = 422
    headers = {"Retry-After": str(e.retry_after)} if e.retry_after else {}
    return JSONResponse(
        {"error": {"code": e.code, "message": e.message, "upstream_status": e.status}},
        status_code=status,
        headers=headers,
    )


def _adapter(spec: ProviderSpec) -> Adapter:
    return build_adapter(spec, _transport)


@router.post("/providers/test")
async def test_connection(
    body: TestRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> JSONResponse:
    """Always 200 with ok=true/false so the admin UI can show the reason."""
    try:
        latency = await timed_check(_adapter(body.provider))
    except ProviderError as e:
        return JSONResponse({"ok": False, "error_code": e.code, "message": e.message})
    return JSONResponse({"ok": True, "latency_ms": latency})


@router.post("/ai/complete")
async def complete(
    body: CompleteRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> JSONResponse:
    try:
        r = await _adapter(body.provider).complete(body.request)
    except ProviderError as e:
        return _err(e)
    return JSONResponse(
        {
            "text": r.text,
            "model": r.model,
            "input_tokens": r.input_tokens,
            "output_tokens": r.output_tokens,
            "finish_reason": r.finish_reason,
        }
    )


@router.post("/ai/stream", response_model=None)
async def stream(
    body: CompleteRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> StreamingResponse | JSONResponse:
    try:
        adapter = _adapter(body.provider)
    except ProviderError as e:
        return _err(e)
    it = adapter.stream(body.request)

    # Pull the first event before committing to a 200 stream, so upstream auth/rate errors
    # surface as real HTTP errors instead of a broken stream.
    try:
        first = await anext(it, None)
    except ProviderError as e:
        return _err(e)

    async def gen() -> AsyncIterator[bytes]:
        try:
            if first is not None:
                yield _sse("delta", first)
            async for ev in it:
                yield _sse("delta", ev)
            yield b"event: done\ndata: {}\n\n"
        except ProviderError as e:
            payload = json.dumps({"code": e.code, "message": e.message})
            yield f"event: error\ndata: {payload}\n\n".encode()

    return StreamingResponse(gen(), media_type="text/event-stream")


def _sse(event: str, ev: object) -> bytes:
    d = {
        "text": getattr(ev, "text", ""),
        "input_tokens": getattr(ev, "input_tokens", None),
        "output_tokens": getattr(ev, "output_tokens", None),
        "finish_reason": getattr(ev, "finish_reason", None),
    }
    return f"event: {event}\ndata: {json.dumps(d)}\n\n".encode()
