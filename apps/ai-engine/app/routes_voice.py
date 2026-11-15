"""Internal speech endpoints (signed requests only): audio -> text, and text -> audio.

Stateless: nothing is saved here. Laravel decides who may call and charges the credits."""

from __future__ import annotations

import base64
import binascii
from typing import Annotated

import httpx
from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

from app.providers.base import ProviderError, ProviderSpec, check_model
from app.routes_providers import _adapter, _err
from app.security import AuthContext, require_signed_request
from app.voice.audio import (
    AUDIO_TYPES,
    LANG_RE,
    MAX_AUDIO_BYTES,
    MAX_TTS_AUDIO_BYTES,
    MAX_TTS_CHARS,
    SPEECH_ADAPTERS,
    VOICE_RE,
    base_mime,
    clean_text,
)

router = APIRouter(prefix="/internal/v1")


class TranscribeRequest(BaseModel):
    provider: ProviderSpec
    model: str = Field(max_length=100)
    mime: str = Field(max_length=60)
    audio_b64: str = Field(max_length=9_000_000)
    language: str | None = Field(default=None, max_length=10)


class SpeakRequest(BaseModel):
    provider: ProviderSpec
    model: str = Field(max_length=100)
    text: str = Field(max_length=MAX_TTS_CHARS)
    voice: str = Field(default="alloy", max_length=40)


def _unsupported(reason: str) -> JSONResponse:
    return JSONResponse({"status": "unsupported", "reason": reason})


async def _call(spec: ProviderSpec, path: str, **kw: object) -> httpx.Response:
    a = _adapter(spec)
    headers = {k: v for k, v in a.headers().items() if k.lower() != "content-type"}
    try:
        async with a._client() as c:  # noqa: SLF001 - same package family, reuses the safe client
            resp = await c.post(path, headers=headers, **kw)  # type: ignore[arg-type]
    except httpx.TimeoutException:
        raise ProviderError("timeout", "provider timed out") from None
    except httpx.HTTPError:
        raise ProviderError("unreachable", "provider unreachable") from None
    a._raise_for(resp)  # noqa: SLF001
    return resp


@router.post("/voice/transcribe")
async def transcribe(
    body: TranscribeRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> JSONResponse:
    if body.provider.adapter not in SPEECH_ADAPTERS:
        return _unsupported("adapter_no_speech")
    ext = AUDIO_TYPES.get(base_mime(body.mime))
    if ext is None:
        return _unsupported("bad_audio_type")
    if body.language and not LANG_RE.match(body.language):
        return _unsupported("bad_language")
    try:
        data = base64.b64decode(body.audio_b64, validate=True)
    except (binascii.Error, ValueError):
        return _unsupported("bad_encoding")
    if not data or len(data) > MAX_AUDIO_BYTES:
        return _unsupported("bad_size")
    form = {"model": check_model(body.model), "response_format": "json"}
    if body.language:
        form["language"] = body.language.split("-")[0]
    try:
        resp = await _call(
            body.provider,
            "/audio/transcriptions",
            data=form,
            files={"file": (f"audio.{ext}", data, base_mime(body.mime))},
        )
        j = resp.json()
    except ProviderError as e:
        return _err(e)
    except ValueError:
        return _err(ProviderError("bad_response", "provider returned a non-JSON body"))
    text = clean_text(str(j.get("text", ""))) if isinstance(j, dict) else ""
    return JSONResponse(
        {
            "status": "ok" if text else "empty",
            "text": text,
            "model": body.model,
            "bytes": len(data),
        }
    )


@router.post("/voice/speak")
async def speak(
    body: SpeakRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> JSONResponse:
    if body.provider.adapter not in SPEECH_ADAPTERS:
        return _unsupported("adapter_no_speech")
    if not VOICE_RE.match(body.voice):
        return _unsupported("bad_voice")
    text = clean_text(body.text)
    if not text:
        return _unsupported("empty_text")
    try:
        resp = await _call(
            body.provider,
            "/audio/speech",
            json={
                "model": check_model(body.model),
                "input": text,
                "voice": body.voice,
                "response_format": "mp3",
            },
        )
    except ProviderError as e:
        return _err(e)
    audio = resp.content
    if not audio or len(audio) > MAX_TTS_AUDIO_BYTES:
        return _err(ProviderError("bad_response", "provider returned no usable audio"))
    return JSONResponse(
        {
            "status": "ok",
            "mime": "audio/mpeg",
            "audio_b64": base64.b64encode(audio).decode(),
            "chars": len(text),
            "model": body.model,
        }
    )
