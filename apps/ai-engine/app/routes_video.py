"""Internal slide-video endpoint (signed requests only).  Stateless: scenes in, an MP4 out.

Laravel owns the queue, the credits and the stored files; nothing is kept here."""

from __future__ import annotations

import base64
import binascii
from typing import Annotated

from fastapi import APIRouter, Depends
from fastapi.responses import JSONResponse, Response
from pydantic import BaseModel, Field

from app.routes_studio import BrandIn, CopyIn
from app.security import AuthContext, require_signed_request
from app.studio.compose import Brand, ComposeError, Copy
from app.video.edit import edit_video, probe_bytes
from app.video.render import Scene, VideoError, render_video

router = APIRouter(prefix="/internal/v1")


class SceneIn(BaseModel):
    copy_: CopyIn = Field(alias="copy")
    seconds: float = Field(ge=0, le=60)
    base_b64: str | None = Field(default=None, max_length=5_600_000)


class VideoRequest(BaseModel):
    size: str = Field(max_length=10)
    scenes: list[SceneIn] = Field(min_length=1, max_length=10)
    brand: BrandIn = BrandIn()
    audio_b64: str | None = Field(default=None, max_length=8_400_000)
    fade: bool = True
    motion: bool = True
    captions: list[str] = Field(default=[], max_length=40)
    music: str | None = Field(default=None, max_length=20)


def _bad(reason: str, status: int = 200) -> JSONResponse:
    return JSONResponse({"status": "unsupported", "reason": reason}, status_code=status)


@router.post("/video/render")
async def render(
    body: VideoRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> Response:
    try:
        scenes = [
            Scene(
                Copy(s.copy_.headline, s.copy_.subline, s.copy_.cta, s.copy_.theme),
                s.seconds,
                base64.b64decode(s.base_b64, validate=True) if s.base_b64 else None,
            )
            for s in body.scenes
        ]
        audio = base64.b64decode(body.audio_b64, validate=True) if body.audio_b64 else None
        logo = base64.b64decode(body.brand.logo_b64, validate=True) if body.brand.logo_b64 else None
    except (binascii.Error, ValueError):
        return _bad("bad_encoding")
    brand = Brand(body.brand.primary, body.brand.accent, body.brand.name, logo, body.brand.footer)
    try:
        mp4 = await render_video(
            body.size,
            scenes,
            brand,
            audio,
            fade=body.fade,
            motion=body.motion,
            captions=body.captions,
            music=body.music,
        )
    except (VideoError, ComposeError) as e:
        return _bad(e.code, 503 if e.code in ("no_ffmpeg", "timeout") else 200)
    return Response(mp4, media_type="video/mp4", headers={"Cache-Control": "no-store"})


class ClipRequest(BaseModel):
    video_b64: str = Field(max_length=34_000_000)
    trim_start: float = Field(default=0.0, ge=0, le=600)
    trim_end: float | None = Field(default=None, ge=0, le=600)
    remove_silence: bool = False
    size: str | None = Field(default=None, max_length=10)


def _decode(b64: str) -> bytes | None:
    try:
        return base64.b64decode(b64, validate=True)
    except (binascii.Error, ValueError):
        return None


@router.post("/video/probe")
async def probe(
    body: ClipRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> Response:
    """Length and shape of an uploaded clip (so Laravel can price and check it before queuing)."""
    data = _decode(body.video_b64)
    if data is None:
        return _bad("bad_encoding")
    try:
        info = await probe_bytes(data)
    except VideoError as e:
        return _bad(e.code, 503 if e.code in ("no_ffmpeg", "timeout") else 200)
    return JSONResponse(
        {
            "status": "ok",
            "duration": round(info.duration, 2),
            "has_audio": info.has_audio,
            "width": info.width,
            "height": info.height,
        }
    )


@router.post("/video/edit")
async def edit(
    body: ClipRequest, _: Annotated[AuthContext, Depends(require_signed_request)]
) -> Response:
    data = _decode(body.video_b64)
    if data is None:
        return _bad("bad_encoding")
    try:
        mp4, meta = await edit_video(
            data,
            trim_start=body.trim_start,
            trim_end=body.trim_end,
            remove_silence=body.remove_silence,
            size_key=body.size,
        )
    except VideoError as e:
        return _bad(e.code, 503 if e.code in ("no_ffmpeg", "timeout") else 200)
    return Response(
        mp4,
        media_type="video/mp4",
        headers={
            "Cache-Control": "no-store",
            "X-Seconds-Out": str(meta["seconds_out"]),
            "X-Segments": str(meta["segments"]),
        },
    )
