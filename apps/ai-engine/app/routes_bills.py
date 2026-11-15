"""Internal bill-scan endpoint (signed requests only). Stateless: file in, structured draft out. Laravel stores it,
charges credits and shows it to a person; nothing here posts anything."""

from __future__ import annotations

import base64
import binascii
from typing import Annotated, Any

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from app.bills.extract import normalize, system_prompt
from app.bills.prepare import Unsupported, prepare
from app.providers.base import ChatMessage, ChatRequest, ProviderError, ProviderSpec
from app.routes_providers import _adapter, _err
from app.security import AuthContext, require_signed_request

router = APIRouter(prefix="/internal/v1")


class Account(BaseModel):
    code: str = Field(pattern=r"^[0-9]{3,10}$")
    name: str = Field(max_length=120)


class ScanRequest(BaseModel):
    provider: ProviderSpec
    model: str
    mime: str = Field(max_length=40)
    file_b64: str = Field(max_length=9_000_000)
    accounts: list[Account] = Field(default_factory=list, max_length=80)
    max_tokens: int = Field(default=1500, ge=200, le=4000)


@router.post("/bills/scan")
async def scan(
    body: ScanRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> JSONResponse:
    try:
        data = base64.b64decode(body.file_b64, validate=True)
    except (binascii.Error, ValueError):
        return JSONResponse({"status": "unsupported", "reason": "bad_encoding"})
    try:
        prep = prepare(data, body.mime)
    except Unsupported as e:
        return JSONResponse({"status": "unsupported", "reason": e.code})
    accounts = [a.model_dump() for a in body.accounts]
    user = (
        "Read this document and return the JSON object."
        if prep.images
        else "Read this document text and return the JSON object.\n<document>\n"
        + prep.text
        + "\n</document>"
    )
    msgs = [
        ChatMessage(role="system", content=system_prompt(accounts)),
        ChatMessage(role="user", content=user, images=prep.images or None),  # type: ignore[arg-type]
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
    got = normalize(r.text, accounts)
    if got is None:
        return JSONResponse({"status": "unreadable", "pages": prep.pages} | usage)
    return JSONResponse(
        {"status": "ok", "pages": prep.pages, "data": got["data"], "flags": got["flags"]} | usage
    )
