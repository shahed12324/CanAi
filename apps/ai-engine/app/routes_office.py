"""Internal file writing (signed requests only).

Stateless: a checked spec in, DOCX / XLSX / PPTX bytes out. No model is called, nothing is stored.
"""

from __future__ import annotations

import asyncio
from typing import Annotated

from fastapi import APIRouter, Depends
from fastapi.responses import Response

from app.office.build import DOCX, PPTX, XLSX, build_docx, build_pptx, build_xlsx
from app.office.models import DeckSpec, DocSpec, SheetSpec
from app.security import AuthContext, require_signed_request

router = APIRouter(prefix="/internal/v1/office")


@router.post("/docx", response_model=None)
async def docx(
    spec: DocSpec, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> Response:
    return Response(await asyncio.to_thread(build_docx, spec), media_type=DOCX)


@router.post("/xlsx", response_model=None)
async def xlsx(
    spec: SheetSpec, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> Response:
    return Response(await asyncio.to_thread(build_xlsx, spec), media_type=XLSX)


@router.post("/pptx", response_model=None)
async def pptx(
    spec: DeckSpec, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> Response:
    return Response(await asyncio.to_thread(build_pptx, spec), media_type=PPTX)
