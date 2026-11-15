"""Internal SEO-audit endpoint (signed requests only). Stateless: audit one site, return findings. Laravel stores them."""

from __future__ import annotations

import asyncio
from typing import Annotated, Any

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from app import routes_leads
from app.config import Settings, get_settings
from app.leads.fetch import SafeFetcher
from app.security import AuthContext, require_signed_request
from app.seo.audit import audit_site

router = APIRouter(prefix="/internal/v1")


class AuditRequest(BaseModel):
    url: str = Field(min_length=3, max_length=2048)
    max_pages: int = Field(default=5, ge=1, le=8)
    keyword: str | None = Field(default=None, max_length=80)


@router.post("/seo/audit")
async def audit(
    body: AuditRequest,
    _: Annotated[AuthContext, Depends(require_signed_request)],
    settings: Annotated[Settings, Depends(get_settings)],
) -> JSONResponse:
    allow_private = settings.crawler_allow_private and settings.env in ("local", "test")
    fetcher = SafeFetcher(
        allow_private=allow_private, transport=routes_leads._transport, min_delay=settings.crawler_min_delay
    )
    result: dict[str, Any]
    try:
        async with routes_leads._sem(settings.crawler_concurrency):
            result = await asyncio.wait_for(
                audit_site(body.url, fetcher, max_pages=body.max_pages, keyword=body.keyword), timeout=90
            )
    except TimeoutError:
        result = {"status": "timeout", "url": body.url}
    finally:
        await fetcher.aclose()
    return JSONResponse(result)
