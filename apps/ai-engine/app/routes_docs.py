"""Internal document rendering (signed requests only). Stateless: structured proposal in, PDF or DOCX bytes out."""

from __future__ import annotations

import asyncio
from typing import Annotated

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse, Response

from app.docs.docx_builder import build_docx
from app.docs.models import ProposalDoc
from app.docs.pdf import PdfUnavailable, render_pdf
from app.security import AuthContext, require_signed_request

router = APIRouter(prefix="/internal/v1")
DOCX = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
_sem: asyncio.Semaphore | None = None


def _gate() -> asyncio.Semaphore:
    global _sem
    if _sem is None:
        _sem = asyncio.Semaphore(2)  # Chromium is heavy
    return _sem


@router.post("/render/proposal.docx", response_model=None)
async def render_docx(
    doc: ProposalDoc, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> Response:
    data = await asyncio.to_thread(build_docx, doc)
    return Response(data, media_type=DOCX)


@router.post("/render/proposal.pdf", response_model=None)
async def render_pdf_route(
    doc: ProposalDoc, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> Response:
    try:
        async with _gate():
            data = await asyncio.wait_for(render_pdf(doc), timeout=60)
    except PdfUnavailable:
        return JSONResponse({"error": "pdf_unavailable"}, status_code=503)
    except TimeoutError:
        return JSONResponse({"error": "render_timeout"}, status_code=504)
    return Response(data, media_type="application/pdf")
