"""Slide-video renderer: designed scenes (the same composer as the picture studio) -> one MP4.

Each scene is drawn as a still with real Bengali text, then given a slow zoom and joined with
cross-fades; an optional voice-over is laid underneath (padded or cut to the picture length).
Everything runs through one FFmpeg call with an argument LIST (never a shell), inside a private
temp folder that is always removed."""

from __future__ import annotations

import asyncio
import contextlib
import shutil
import tempfile
from dataclasses import dataclass
from pathlib import Path

from app.studio.compose import Brand, Copy, encode, render, render_caption

VIDEO_SIZES = ("post", "portrait", "story", "ad")
FPS = 25
MAX_SCENES = 10
MIN_SECONDS = 2.0
MAX_SCENE_SECONDS = 8.0
MAX_TOTAL_SECONDS = 60.0
FADE = 0.5
MAX_AUDIO_BYTES = 6_000_000
MAX_OUT_BYTES = 60_000_000
TIMEOUT_S = 280
MAX_CAPTIONS = 40

# Background music is synthesized here from plain sine tones (a soft chord pad), so it is original
# and royalty-free by construction.  name -> (frequencies in Hz, tremolo Hz, tremolo depth, volume)
MUSIC: dict[str, tuple[tuple[float, ...], float, float, float]] = {
    "calm": ((261.63, 329.63, 392.00), 0.25, 0.35, 0.30),
    "warm": ((220.00, 261.63, 329.63), 0.35, 0.30, 0.30),
    "upbeat": ((261.63, 329.63, 392.00, 523.25), 2.0, 0.55, 0.28),
    "cinematic": ((146.83, 220.00, 293.66, 440.00), 0.15, 0.40, 0.32),
}
MUSIC_UNDER_VOICE = 0.45  # music is turned down to this share while a voice-over plays
MAX_CAPTION_CHARS = 120

_gate = asyncio.Semaphore(1)  # one render at a time per engine process: FFmpeg is greedy


class VideoError(Exception):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


@dataclass(frozen=True)
class Scene:
    copy: Copy
    seconds: float
    base: bytes | None = None


def is_mp3(data: bytes) -> bool:
    return data[:3] == b"ID3" or (len(data) > 2 and data[0] == 0xFF and (data[1] & 0xE0) == 0xE0)


def check_scenes(scenes: list[Scene]) -> float:
    if not 1 <= len(scenes) <= MAX_SCENES:
        raise VideoError("bad_scenes")
    for s in scenes:
        if not MIN_SECONDS <= s.seconds <= MAX_SCENE_SECONDS:
            raise VideoError("bad_seconds")
    total = sum(s.seconds for s in scenes)
    if total > MAX_TOTAL_SECONDS:
        raise VideoError("too_long")
    return total


def plan_captions(chunks: list[str], span: float) -> list[tuple[str, float, float]]:
    """Spreads the subtitle chunks over `span` seconds, each in proportion to its length.  Pure."""
    chunks = [c.strip() for c in chunks if c and c.strip()]
    if not chunks:
        return []
    if len(chunks) > MAX_CAPTIONS or any(len(c) > MAX_CAPTION_CHARS for c in chunks):
        raise VideoError("bad_captions")
    weights = [max(1, len(c)) for c in chunks]
    whole = sum(weights)
    out: list[tuple[str, float, float]] = []
    t = 0.0
    for c, wgt in zip(chunks, weights, strict=True):
        end = t + span * wgt / whole
        out.append((c, round(t, 3), round(end, 3)))
        t = end
    return out


async def probe_duration(path: str) -> float | None:
    """Length of an audio file in seconds (None when ffprobe is missing or cannot tell)."""
    if shutil.which("ffprobe") is None:
        return None
    proc = await asyncio.create_subprocess_exec(
        "ffprobe", "-v", "error", "-show_entries", "format=duration",
        "-of", "default=nw=1:nk=1", path,
        stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.DEVNULL,
    )  # fmt: skip
    try:
        out, _ = await asyncio.wait_for(proc.communicate(), 20)
        v = float(out.decode().strip())
    except (TimeoutError, ValueError):
        with contextlib.suppress(ProcessLookupError):
            proc.kill()
        return None
    return v if v > 0 else None


def music_graph(
    name: str, total: float, first_input: int, *, under_voice: bool
) -> tuple[list[str], str]:
    """(lavfi sources to add as inputs, filter chain ending in [mus]).  Pure."""
    freqs, trem_hz, trem_depth, vol = MUSIC[name]
    srcs = [f"sine=f={f}:r=44100:d={total:.3f}" for f in freqs]
    ins = "".join(f"[{first_input + i}:a]" for i in range(len(srcs)))
    fade_out = max(0.0, total - 2.0)
    gain = vol * (MUSIC_UNDER_VOICE if under_voice else 1.0)
    chain = (
        f"{ins}amix=inputs={len(srcs)}:normalize=0,tremolo=f={trem_hz}:d={trem_depth},"
        f"lowpass=f=1800,aecho=0.8:0.7:60:0.3,afade=t=in:d=1.5,"
        f"afade=t=out:st={fade_out:.3f}:d=2,volume={gain:.3f}[mus]"
    )
    return srcs, chain


def build_args(
    frames: list[str],
    seconds: list[float],
    size: tuple[int, int],
    audio: str | None,
    out: str,
    *,
    fade: bool = True,
    motion: bool = True,
    captions: list[tuple[str, float, float]] | None = None,
    caption_files: list[str] | None = None,
    music: str | None = None,
    has_voice: bool = False,
) -> list[str]:
    """The FFmpeg argument list.  Pure: offsets and filters can be tested without running it."""
    w, h = size
    n = len(frames)
    t = FADE if fade and n > 1 else 0.0
    total = sum(seconds)
    args = ["ffmpeg", "-nostdin", "-hide_banner", "-loglevel", "error", "-y"]
    for f in frames:
        args += ["-i", f]
    if audio:
        args += ["-i", audio]
    else:
        args += ["-f", "lavfi", "-t", f"{total:.3f}", "-i", "anullsrc=r=44100:cl=stereo"]
    cap_files = caption_files or []
    for cf in cap_files:
        args += ["-i", cf]
    music_chain = ""
    if music:
        first = n + 1 + len(cap_files)
        srcs, music_chain = music_graph(music, total, first, under_voice=has_voice)
        for src in srcs:
            args += ["-f", "lavfi", "-t", f"{total:.3f}", "-i", src]
    parts: list[str] = []
    for i in range(n):
        length = seconds[i] + (t if i < n - 1 else 0.0)
        frames_n = max(2, round(length * FPS))
        z = "min(zoom+0.0006,1.10)" if motion else "1"
        parts.append(
            f"[{i}:v]scale={w * 2}:{h * 2},"
            f"zoompan=z='{z}':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'"
            f":d={frames_n}:s={w}x{h}:fps={FPS},format=yuv420p,setsar=1[v{i}]"
        )
    if n == 1:
        last = "v0"
    elif t > 0:
        cur, acc = "v0", 0.0
        for i in range(1, n):
            acc += seconds[i - 1]
            parts.append(f"[{cur}][v{i}]xfade=transition=fade:duration={t}:offset={acc:.3f}[x{i}]")
            cur = f"x{i}"
        last = cur
    else:
        parts.append("".join(f"[v{i}]" for i in range(n)) + f"concat=n={n}:v=1:a=0[cat]")
        last = "cat"
    for k, (_, a, b) in enumerate(captions or []):
        parts.append(
            f"[{last}][{n + 1 + k}:v]overlay=enable='between(t,{a:.3f},{b:.3f})':format=auto[o{k}]"
        )
        last = f"o{k}"
    if music:
        parts.append(f"[{n}:a]aresample=44100,apad,atrim=duration={total:.3f}[base]")
        parts.append(music_chain)
        parts.append("[base][mus]amix=inputs=2:duration=first:normalize=0[aout]")
    else:
        parts.append(f"[{n}:a]aresample=44100,apad,atrim=duration={total:.3f}[aout]")
    args += [
        "-filter_complex",
        ";".join(parts),
        "-map",
        f"[{last}]",
        "-map",
        "[aout]",
        "-c:v",
        "libx264",
        "-preset",
        "veryfast",
        "-crf",
        "23",
        "-pix_fmt",
        "yuv420p",
        "-r",
        str(FPS),
        "-c:a",
        "aac",
        "-b:a",
        "128k",
        "-t",
        f"{total:.3f}",
        "-movflags",
        "+faststart",
        out,
    ]
    return args


async def render_video(
    size_key: str,
    scenes: list[Scene],
    brand: Brand,
    audio: bytes | None,
    *,
    fade: bool = True,
    motion: bool = True,
    captions: list[str] | None = None,
    music: str | None = None,
) -> bytes:
    from app.studio.compose import SIZES

    if size_key not in VIDEO_SIZES:
        raise VideoError("bad_size")
    check_scenes(scenes)
    if music is not None and music not in MUSIC:
        raise VideoError("bad_music")
    if audio is not None and (not audio or len(audio) > MAX_AUDIO_BYTES or not is_mp3(audio)):
        raise VideoError("bad_audio")
    if shutil.which("ffmpeg") is None:
        raise VideoError("no_ffmpeg")
    tmp = Path(tempfile.mkdtemp(prefix="jarvis-video-"))
    try:
        frames: list[str] = []
        for i, s in enumerate(scenes):
            data, _ = encode(
                render(size_key, s.copy, brand, s.base), "png"
            )  # ComposeError bubbles up
            p = tmp / f"s{i}.png"
            p.write_bytes(data)
            frames.append(str(p))
        apath = None
        if audio:
            ap = tmp / "voice.mp3"
            ap.write_bytes(audio)
            apath = str(ap)
        cues: list[tuple[str, float, float]] = []
        cap_files: list[str] = []
        if captions:
            total = sum(s.seconds for s in scenes)
            span = total
            if apath:
                span = min(total, await probe_duration(apath) or total)
            cues = plan_captions(captions, span)
            for k, (text, _, _) in enumerate(cues):
                cp = tmp / f"c{k}.png"
                cdata, _ = encode(render_caption(size_key, text), "png")
                cp.write_bytes(cdata)
                cap_files.append(str(cp))
        out = tmp / "out.mp4"
        args = build_args(
            frames,
            [s.seconds for s in scenes],
            SIZES[size_key],
            apath,
            str(out),
            fade=fade,
            motion=motion,
            captions=cues,
            caption_files=cap_files,
            music=music,
            has_voice=apath is not None,
        )
        async with _gate:
            proc = await asyncio.create_subprocess_exec(
                *args, stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL
            )
            try:
                await asyncio.wait_for(proc.wait(), TIMEOUT_S)
            except TimeoutError:
                with contextlib.suppress(ProcessLookupError):
                    proc.kill()
                await proc.wait()
                raise VideoError("timeout") from None
        if proc.returncode != 0 or not out.exists():
            raise VideoError("ffmpeg_failed")
        if out.stat().st_size > MAX_OUT_BYTES:
            raise VideoError("too_big")
        return out.read_bytes()
    finally:
        shutil.rmtree(tmp, ignore_errors=True)
