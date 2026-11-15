"""Internal picture-reading endpoint (signed requests only).

Stateless: a picture or PDF in, plain text out. Laravel decides who may call it, charges the
credits and stores whatever it keeps; nothing here is saved."""

from __future__ import annotations

import base64
import binascii
from typing import Annotated, Any

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from app.bills.prepare import Unsupported, prepare
from app.providers.base import ChatMessage, ChatRequest, ProviderError, ProviderSpec
from app.routes_providers import _adapter, _err
from app.security import AuthContext, require_signed_request
from app.vision.read import MODES, clean, system_prompt, user_prompt

router = APIRouter(prefix="/internal/v1")

MAX_PAGES = 6
MAX_TEXT = 60_000


class ReadRequest(BaseModel):
    provider: ProviderSpec
    model: str
    mime: str = Field(max_length=40)
    file_b64: str = Field(max_length=9_000_000)
    mode: str = Field(default="transcribe", max_length=12)
    max_tokens: int = Field(default=3000, ge=200, le=6000)


@router.post("/vision/read")
async def read(
    body: ReadRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> JSONResponse:
    if body.mode not in MODES:
        return JSONResponse({"status": "unsupported", "reason": "bad_mode"})
    try:
        data = base64.b64decode(body.file_b64, validate=True)
    except (binascii.Error, ValueError):
        return JSONResponse({"status": "unsupported", "reason": "bad_encoding"})
    try:
        prep = prepare(data, body.mime, max_pages=MAX_PAGES, max_text=MAX_TEXT)
    except Unsupported as e:
        return JSONResponse({"status": "unsupported", "reason": e.code})
    if body.mode == "transcribe" and prep.text and not prep.images:
        # A PDF that already carries its text needs no model at all.
        text = clean(prep.text, "transcribe")
        return JSONResponse(
            {
                "status": "ok" if text else "empty",
                "text": text,
                "pages": prep.pages,
                "source": "text_layer",
            }
            | {"model": None, "input_tokens": 0, "output_tokens": 0}
        )
    msgs = [
        ChatMessage(role="system", content=system_prompt(body.mode)),
        ChatMessage(
            role="user",
            content=user_prompt(body.mode, bool(prep.images), prep.text),
            images=prep.images or None,  # type: ignore[arg-type]
        ),
    ]
    try:
        r = await _adapter(body.provider).complete(
            ChatRequest(model=body.model, messages=msgs, max_tokens=body.max_tokens, temperature=0)
        )
    except ProviderError as e:
        return _err(e)
    usage: dict[str, Any] = {
        "model": r.model,
        "input_tokens": r.input_tokens,
        "output_tokens": r.output_tokens,
    }
    text = clean(r.text, body.mode)
    return JSONResponse(
        {"status": "ok" if text else "empty", "text": text, "pages": prep.pages, "source": "vision"}
        | usage
    )
