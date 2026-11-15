"""Clip editor: trim a video, cut out the silent parts, fit it to a social-media size.

One FFmpeg call with an argument LIST (never a shell), inside a private temp folder that is always
removed.  The planning (which parts to keep) is pure so it can be tested without running FFmpeg."""

from __future__ import annotations

import asyncio
import contextlib
import json
import re
import shutil
import tempfile
from dataclasses import dataclass
from pathlib import Path

from app.studio.compose import SIZES
from app.video.render import FPS, MAX_OUT_BYTES, TIMEOUT_S, VIDEO_SIZES, VideoError

MAX_IN_BYTES = 25_000_000
MAX_IN_SECONDS = 120.0
MAX_SEGMENTS = 60
SILENCE_DB = -35
SILENCE_MIN = 0.7  # a pause shorter than this is kept
PAD = 0.12  # breathing room kept around speech
MIN_KEEP = 0.3

_gate = asyncio.Semaphore(1)
_SIL_START = re.compile(r"silence_start:\s*(-?[0-9.]+)")
_SIL_END = re.compile(r"silence_end:\s*(-?[0-9.]+)")


@dataclass(frozen=True)
class Info:
    duration: float
    has_audio: bool
    width: int
    height: int


def parse_silences(log: str, duration: float) -> list[tuple[float, float]]:
    """Silent stretches from the silencedetect log (one running to the end has no end line)."""
    out: list[tuple[float, float]] = []
    start: float | None = None
    for line in log.splitlines():
        m = _SIL_START.search(line)
        if m:
            start = max(0.0, float(m.group(1)))
            continue
        m = _SIL_END.search(line)
        if m and start is not None:
            out.append((start, min(duration, float(m.group(1)))))
            start = None
    if start is not None:
        out.append((start, duration))
    return [(a, b) for a, b in out if b > a]


def keep_segments(
    duration: float,
    silences: list[tuple[float, float]],
    trim_start: float = 0.0,
    trim_end: float | None = None,
    *,
    pad: float = PAD,
    min_keep: float = MIN_KEEP,
    max_segments: int = MAX_SEGMENTS,
) -> list[tuple[float, float]]:
    """Parts to keep: inside the trim window, minus the silences (with a little padding)."""
    lo = max(0.0, trim_start)
    hi = min(duration, trim_end if trim_end is not None else duration)
    if hi - lo < 0.5:
        raise VideoError("bad_trim")
    keep: list[tuple[float, float]] = []
    cur = lo
    for a, b in sorted(silences):
        a, b = a + pad, b - pad  # leave the padding on the speech side
        if b <= a or b <= lo or a >= hi:
            continue
        a, b = max(a, lo), min(b, hi)
        if a - cur >= min_keep:
            keep.append((cur, a))
        cur = max(cur, b)
    if hi - cur >= min_keep:
        keep.append((cur, hi))
    if not keep:
        raise VideoError("all_silent")
    if len(keep) > max_segments:  # too many tiny cuts: merge the closest neighbours
        while len(keep) > max_segments:
            gaps = [keep[i + 1][0] - keep[i][1] for i in range(len(keep) - 1)]
            i = gaps.index(min(gaps))
            keep[i : i + 2] = [(keep[i][0], keep[i + 1][1])]
    return [(round(a, 3), round(b, 3)) for a, b in keep]


def build_edit_args(
    src: str,
    segments: list[tuple[float, float]],
    has_audio: bool,
    out: str,
    size: tuple[int, int] | None = None,
) -> list[str]:
    """The FFmpeg argument list.  Pure."""
    n = len(segments)
    parts: list[str] = []
    for i, (a, b) in enumerate(segments):
        parts.append(f"[0:v]trim=start={a:.3f}:end={b:.3f},setpts=PTS-STARTPTS[v{i}]")
        if has_audio:
            parts.append(f"[0:a]atrim=start={a:.3f}:end={b:.3f},asetpts=PTS-STARTPTS[a{i}]")
    if has_audio:
        cat = "".join(f"[v{i}][a{i}]" for i in range(n)) + f"concat=n={n}:v=1:a=1[vc][ac]"
    else:
        cat = "".join(f"[v{i}]" for i in range(n)) + f"concat=n={n}:v=1:a=0[vc]"
    parts.append(cat)
    tail = "format=yuv420p,setsar=1"
    if size:
        w, h = size
        tail = f"scale={w}:{h}:force_original_aspect_ratio=increase,crop={w}:{h},fps={FPS},{tail}"
    else:
        tail = f"fps={FPS},scale=trunc(iw/2)*2:trunc(ih/2)*2,{tail}"
    parts.append(f"[vc]{tail}[vout]")
    args = ["ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y", "-i", src]
    args += ["-filter_complex", ";".join(parts), "-map", "[vout]"]
    if has_audio:
        args += ["-map", "[ac]", "-c:a", "aac", "-b:a", "128k"]
    else:
        args += ["-an"]
    args += ["-c:v", "libx264", "-preset", "veryfast", "-crf", "23", "-pix_fmt", "yuv420p"]
    args += ["-movflags", "+faststart", out]
    return args


async def _run(args: list[str], *, capture_stderr: bool = False) -> tuple[int, str]:
    proc = await asyncio.create_subprocess_exec(
        *args,
        stdout=asyncio.subprocess.DEVNULL,
        stderr=asyncio.subprocess.PIPE if capture_stderr else asyncio.subprocess.DEVNULL,
    )
    try:
        _, err = await asyncio.wait_for(proc.communicate(), TIMEOUT_S)
    except TimeoutError:
        with contextlib.suppress(ProcessLookupError):
            proc.kill()
        await proc.wait()
        raise VideoError("timeout") from None
    return proc.returncode or 0, (err or b"").decode("utf-8", "replace")


async def probe(path: str) -> Info:
    if shutil.which("ffprobe") is None:
        raise VideoError("no_ffmpeg")
    proc = await asyncio.create_subprocess_exec(
        "ffprobe", "-v", "error", "-show_entries",
        "format=duration:stream=codec_type,width,height", "-of", "json", path,
        stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.DEVNULL,
    )  # fmt: skip
    try:
        out, _ = await asyncio.wait_for(proc.communicate(), 30)
    except TimeoutError:
        with contextlib.suppress(ProcessLookupError):
            proc.kill()
        raise VideoError("timeout") from None
    try:
        j = json.loads(out.decode())
        dur = float(j["format"]["duration"])
        streams = j.get("streams", [])
        vid = next(s for s in streams if s.get("codec_type") == "video")
        w, h = int(vid["width"]), int(vid["height"])
    except (ValueError, KeyError, StopIteration, TypeError):
        raise VideoError("bad_video") from None
    return Info(dur, any(s.get("codec_type") == "audio" for s in streams), w, h)


def _check_input(data: bytes) -> None:
    if not data or len(data) > MAX_IN_BYTES:
        raise VideoError("bad_video")


async def probe_bytes(data: bytes) -> Info:
    _check_input(data)
    if shutil.which("ffmpeg") is None:
        raise VideoError("no_ffmpeg")
    tmp = Path(tempfile.mkdtemp(prefix="jarvis-clip-"))
    try:
        p = tmp / "in.bin"
        p.write_bytes(data)
        info = await probe(str(p))
        if info.duration > MAX_IN_SECONDS:
            raise VideoError("too_long")
        return info
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


async def edit_video(
    data: bytes,
    *,
    trim_start: float = 0.0,
    trim_end: float | None = None,
    remove_silence: bool = False,
    size_key: str | None = None,
) -> tuple[bytes, dict[str, float | int | bool]]:
    if size_key is not None and size_key not in VIDEO_SIZES:
        raise VideoError("bad_size")
    _check_input(data)
    if shutil.which("ffmpeg") is None:
        raise VideoError("no_ffmpeg")
    tmp = Path(tempfile.mkdtemp(prefix="jarvis-clip-"))
    try:
        src = tmp / "in.bin"
        src.write_bytes(data)
        info = await probe(str(src))
        if info.duration > MAX_IN_SECONDS:
            raise VideoError("too_long")
        silences: list[tuple[float, float]] = []
        silence_used = remove_silence and info.has_audio
        if silence_used:
            _, log = await _run(
                ["ffmpeg", "-nostdin", "-hide_banner", "-i", str(src), "-vn",
                 "-af", f"silencedetect=noise={SILENCE_DB}dB:d={SILENCE_MIN}",
                 "-f", "null", "-"],
                capture_stderr=True,
            )  # fmt: skip
            silences = parse_silences(log, info.duration)
        segs = keep_segments(info.duration, silences, trim_start, trim_end)
        out = tmp / "out.mp4"
        args = build_edit_args(
            str(src), segs, info.has_audio, str(out), SIZES[size_key] if size_key else None
        )
        async with _gate:
            code, _ = await _run(args)
        if code != 0 or not out.exists():
            raise VideoError("ffmpeg_failed")
        if out.stat().st_size > MAX_OUT_BYTES:
            raise VideoError("too_big")
        kept = sum(b - a for a, b in segs)
        meta = {
            "seconds_in": round(info.duration, 2),
            "seconds_out": round(kept, 2),
            "segments": len(segs),
            "silence_used": silence_used,
            "has_audio": info.has_audio,
        }
        return out.read_bytes(), meta
    finally:
        shutil.rmtree(tmp, ignore_errors=True)
