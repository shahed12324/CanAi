"""Internal document and photo tools (signed requests only). Stateless: files in, one file out (a .zip when there are several)."""

from __future__ import annotations

import base64
import binascii
from typing import Annotated, Any

from fastapi import APIRouter, Depends
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import JSONResponse, Response
from pydantic import BaseModel, Field

from app.doctools.ops import MAX_FILES, ToolError, run
from app.security import AuthContext, require_signed_request

router = APIRouter(prefix="/internal/v1")
MAX_FILE_B64 = 34_000_000  # about 25 MB of file


class FileIn(BaseModel):
    name: str = Field(max_length=200)
    b64: str = Field(max_length=MAX_FILE_B64)


class RunRequest(BaseModel):
    op: str = Field(max_length=40)
    files: list[FileIn] = Field(default=[], max_length=MAX_FILES)
    params: dict[str, Any] = {}


@router.post("/doctools/run", response_model=None)
async def doctools_run(req: RunRequest, _: Annotated[AuthContext, Depends(require_signed_request)]) -> Response:
    try:
        files = [(f.name, base64.b64decode(f.b64, validate=True)) for f in req.files]
    except (binascii.Error, ValueError):
        return JSONResponse({"error": "bad_file"}, status_code=422)
    try:
        data, mime, ext = await run_in_threadpool(run, req.op, files, req.params)
    except ToolError as e:
        return JSONResponse({"error": "tool_error", "message": str(e)}, status_code=422)
    except (ValueError, TypeError):
        return JSONResponse({"error": "tool_error", "message": "Those settings are not valid."}, status_code=422)
    return Response(data, media_type=mime, headers={"X-File-Ext": ext})
