"""Internal design-studio endpoints (signed requests only).

`/studio/generate` asks an image model for a background picture (no text in it, ever: text is
drawn afterwards by the composer). `/studio/compose` lays brand, logo and real fonts on top and
exports every requested size. Stateless: Laravel decides who may call, charges credits and keeps
the files."""

from __future__ import annotations

import base64
import binascii
import io
from typing import Annotated, Literal

from fastapi import APIRouter, Depends
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from app.providers.base import ProviderError, ProviderSpec, check_model
from app.routes_providers import _adapter, _err
from app.security import AuthContext, require_signed_request
from app.studio.compose import (
    MAX_BASE_PIXELS,
    SIZES,
    Brand,
    ComposeError,
    Copy,
    clean_line,
    encode,
    open_image,
    render,
)

router = APIRouter(prefix="/internal/v1")

IMAGE_ADAPTERS = {"openai", "openai_compatible"}
GEN_SIZES = {"1024x1024", "1024x1536", "1536x1024"}
NO_TEXT = (
    "\n\nImportant: the picture must contain no text, letters, numbers, logos or watermarks "
    "anywhere. Leave calm, uncluttered space where a headline could later be placed."
)


class GenerateRequest(BaseModel):
    provider: ProviderSpec
    model: str = Field(max_length=100)
    prompt: str = Field(min_length=3, max_length=1500)
    size: str = Field(default="1024x1024", max_length=12)


class CopyIn(BaseModel):
    headline: str = Field(default="", max_length=200)
    subline: str = Field(default="", max_length=400)
    cta: str = Field(default="", max_length=100)
    theme: str = Field(default="dark", max_length=10)


class BrandIn(BaseModel):
    primary: str = Field(default="#1f3a8a", max_length=7)
    accent: str = Field(default="#f59e0b", max_length=7)
    name: str = Field(default="", max_length=100)
    footer: str = Field(default="", max_length=240)
    logo_b64: str | None = Field(default=None, max_length=1_000_000)


class ComposeRequest(BaseModel):
    copy_: CopyIn = Field(alias="copy")
    brand: BrandIn = BrandIn()
    base_b64: str | None = Field(default=None, max_length=11_000_000)
    sizes: list[str] = Field(min_length=1, max_length=5)
    fmt: Literal["png", "jpeg"] = "png"


def _unsupported(reason: str) -> JSONResponse:
    return JSONResponse({"status": "unsupported", "reason": reason})


def _b64(data: str | None) -> bytes | None:
    if not data:
        return None
    return base64.b64decode(data, validate=True)


@router.post("/studio/generate")
async def generate(
    body: GenerateRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> JSONResponse:
    if body.provider.adapter not in IMAGE_ADAPTERS:
        return _unsupported("adapter_no_images")
    if body.size not in GEN_SIZES:
        return _unsupported("bad_size")
    prompt = clean_line(body.prompt, 1500)
    if len(prompt) < 3:
        return _unsupported("empty_prompt")
    payload: dict[str, object] = {
        "model": check_model(body.model),
        "prompt": prompt + NO_TEXT,
        "n": 1,
        "size": body.size,
    }
    if body.model.startswith("dall-e"):
        payload["response_format"] = "b64_json"  # gpt-image-* rejects this field
    a = _adapter(body.provider)
    try:
        resp = await a._request("POST", "/images/generations", json=payload)  # noqa: SLF001
        j = a._json(resp)  # noqa: SLF001
    except ProviderError as e:
        return _err(e)
    items = j.get("data")
    first = items[0] if isinstance(items, list) and items and isinstance(items[0], dict) else {}
    raw = first.get("b64_json")
    if not isinstance(raw, str):
        return _unsupported("no_inline_image")  # a bare URL is never fetched: that would be SSRF
    try:
        data = base64.b64decode(raw, validate=True)
        im = open_image(data, MAX_BASE_PIXELS, "bad_image")
    except (binascii.Error, ValueError, ComposeError):
        return _err(ProviderError("bad_response", "provider returned no usable picture"))
    out = io.BytesIO()
    im.convert("RGB").save(out, "PNG", optimize=True)  # re-encoded: no metadata ever passes through
    return JSONResponse(
        {
            "status": "ok",
            "image_b64": base64.b64encode(out.getvalue()).decode(),
            "width": im.width,
            "height": im.height,
            "model": body.model,
            "images": 1,
        }
    )


def _compose_all(
    body: ComposeRequest, base: bytes | None, logo: bytes | None
) -> list[dict[str, object]]:
    brand = Brand(
        primary=body.brand.primary,
        accent=body.brand.accent,
        name=body.brand.name,
        logo=logo,
        footer=body.brand.footer,
    )
    copy = Copy(body.copy_.headline, body.copy_.subline, body.copy_.cta, body.copy_.theme)
    out: list[dict[str, object]] = []
    for key in dict.fromkeys(body.sizes):  # de-duplicated, order kept
        im = render(key, copy, brand, base)
        data, mime = encode(im, body.fmt)
        out.append(
            {
                "size": key,
                "width": im.width,
                "height": im.height,
                "mime": mime,
                "image_b64": base64.b64encode(data).decode(),
            }
        )
    return out


@router.post("/studio/compose")
async def compose(
    body: ComposeRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> JSONResponse:
    if any(s not in SIZES for s in body.sizes):
        return _unsupported("bad_size")
    try:
        base = _b64(body.base_b64)
        logo = _b64(body.brand.logo_b64)
    except (binascii.Error, ValueError):
        return _unsupported("bad_encoding")
    try:
        items = await run_in_threadpool(_compose_all, body, base, logo)
    except ComposeError as e:
        return _unsupported(e.code)
    return JSONResponse({"status": "ok", "items": items})
