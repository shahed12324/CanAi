"""Internal lead-research endpoint (signed requests only). Stateless: crawl one site, return facts. Laravel stores them."""

from __future__ import annotations

import asyncio
from typing import Annotated, Any

import httpx
from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from app.config import Settings, get_settings
from app.leads.crawler import crawl_site
from app.leads.emailcheck import DnsResolver, Resolver
from app.leads.fetch import SafeFetcher
from app.leads.render import PlaywrightRenderer
from app.security import AuthContext, require_signed_request

router = APIRouter(prefix="/internal/v1")

# Tests replace these (fixture transport / fake DNS).
_transport: httpx.AsyncBaseTransport | None = None
_resolver: Resolver | None = None
_semaphore: asyncio.Semaphore | None = None


def set_test_hooks(transport: httpx.AsyncBaseTransport | None, resolver: Resolver | None) -> None:
    global _transport, _resolver
    _transport, _resolver = transport, resolver


class CrawlRequest(BaseModel):
    url: str = Field(min_length=3, max_length=2048)
    max_pages: int = Field(default=4, ge=1, le=8)
    check_email: bool = True
    render_js: bool = False


def _sem(n: int) -> asyncio.Semaphore:
    global _semaphore
    if _semaphore is None:
        _semaphore = asyncio.Semaphore(max(1, n))
    return _semaphore


@router.post("/leads/crawl")
async def crawl(
    body: CrawlRequest,
    _: Annotated[AuthContext, Depends(require_signed_request)],
    settings: Annotated[Settings, Depends(get_settings)],
) -> JSONResponse:
    allow_private = settings.crawler_allow_private and settings.env in ("local", "test")
    fetcher = SafeFetcher(
        allow_private=allow_private, transport=_transport, min_delay=settings.crawler_min_delay
    )
    renderer = PlaywrightRenderer(allow_private) if body.render_js else None
    result: dict[str, Any]
    try:
        async with _sem(settings.crawler_concurrency):
            result = await asyncio.wait_for(
                crawl_site(
                    body.url,
                    fetcher,
                    _resolver or DnsResolver(),
                    max_pages=body.max_pages,
                    check_email=body.check_email,
                    renderer=renderer,
                ),
                timeout=90,
            )
    except TimeoutError:
        result = {"status": "timeout", "url": body.url}
    finally:
        await fetcher.aclose()
    return JSONResponse(result)
