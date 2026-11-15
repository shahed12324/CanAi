"""Clip editor: pure planning, real FFmpeg runs checked with ffprobe, and the signed routes."""

import base64
import json
import shutil
import subprocess
import tempfile
from pathlib import Path

import pytest

from app.video import edit as E
from app.video.render import VideoError
from tests.conftest import make_headers

needs_ffmpeg = pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg not installed")


# ───────────────────────────────────────────────────────────────────── pure planning
def test_parse_silences_reads_pairs_and_a_trailing_silence():
    log = (
        "[silencedetect @ 0x1] silence_start: 2.5\n"
        "[silencedetect @ 0x1] silence_end: 4.1 | silence_duration: 1.6\n"
        "[silencedetect @ 0x1] silence_start: 8.0\n"
    )
    assert E.parse_silences(log, 10.0) == [(2.5, 4.1), (8.0, 10.0)]
    assert E.parse_silences("nothing here", 10.0) == []
    assert E.parse_silences("silence_start: -0.02\nsilence_end: 1.0", 5.0) == [(0.0, 1.0)]
    assert E.parse_silences("silence_start: 1\nsilence_end: 99", 5.0) == [(1.0, 5.0)]


def test_keep_everything_without_silences_or_trim():
    assert E.keep_segments(10.0, []) == [(0.0, 10.0)]


def test_trim_window_is_respected_and_clamped():
    assert E.keep_segments(10.0, [], 2.0, 6.0) == [(2.0, 6.0)]
    assert E.keep_segments(10.0, [], -5.0, 99.0) == [(0.0, 10.0)]
    with pytest.raises(VideoError) as e:
        E.keep_segments(10.0, [], 5.0, 5.3)
    assert e.value.code == "bad_trim"
    with pytest.raises(VideoError):
        E.keep_segments(10.0, [], 20.0, None)


def test_silences_are_cut_with_padding_on_the_speech_side():
    segs = E.keep_segments(10.0, [(3.0, 5.0)])
    assert segs == [(0.0, 3.12), (4.88, 10.0)]
    # speech before and after a long pause stays; the pause shrinks by 2 x pad
    kept = sum(b - a for a, b in segs)
    assert abs(kept - (10.0 - 2.0 + 0.24)) < 1e-6


def test_short_silences_under_twice_the_padding_are_not_cut():
    assert E.keep_segments(10.0, [(3.0, 3.2)]) == [(0.0, 10.0)]


def test_silence_at_the_start_and_end():
    segs = E.keep_segments(10.0, [(0.0, 2.0), (8.0, 10.0)])
    assert segs == [(1.88, 8.12)]


def test_silence_outside_the_trim_window_is_ignored_and_one_straddling_is_clipped():
    assert E.keep_segments(20.0, [(1.0, 3.0), (15.0, 18.0)], 5.0, 12.0) == [(5.0, 12.0)]
    assert E.keep_segments(20.0, [(4.0, 8.0)], 5.0, 12.0) == [(7.88, 12.0)]


def test_all_silent_is_refused():
    with pytest.raises(VideoError) as e:
        E.keep_segments(10.0, [(0.0, 10.0)])
    assert e.value.code == "all_silent"


def test_tiny_pieces_are_dropped_and_the_rest_kept():
    # 0.12 s of speech at each end is below MIN_KEEP and dropped; the 0.74 s in the middle stays
    assert E.keep_segments(10.0, [(0.0, 3.0), (3.5, 10.0)]) == [(2.88, 3.62)]


def test_too_many_cuts_are_merged_down():
    sil = [(i * 1.0 + 0.5, i * 1.0 + 1.0) for i in range(0, 100)]
    segs = E.keep_segments(120.0, sil, pad=0.0, min_keep=0.1)
    assert len(segs) <= E.MAX_SEGMENTS
    assert segs[0][0] == 0.0 and segs[-1][1] == 120.0
    assert all(segs[i][1] <= segs[i + 1][0] for i in range(len(segs) - 1))


def test_args_trim_each_segment_and_concat_with_audio():
    a = E.build_edit_args("in.bin", [(0.0, 3.0), (5.0, 9.0)], True, "o.mp4", (1080, 1920))
    g = a[a.index("-filter_complex") + 1]
    assert "[0:v]trim=start=0.000:end=3.000,setpts=PTS-STARTPTS[v0]" in g
    assert "[0:a]atrim=start=5.000:end=9.000,asetpts=PTS-STARTPTS[a1]" in g
    assert "[v0][a0][v1][a1]concat=n=2:v=1:a=1[vc][ac]" in g
    assert "scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920" in g
    assert "[ac]" in a and "-an" not in a


def test_args_without_audio_and_without_size():
    a = E.build_edit_args("in.bin", [(0.0, 3.0)], False, "o.mp4")
    g = a[a.index("-filter_complex") + 1]
    assert "atrim" not in g and "concat=n=1:v=1:a=0[vc]" in g and "crop=" not in g
    assert "-an" in a and "-c:a" not in a
    assert "trunc(iw/2)*2" in g  # even dimensions for yuv420p


# ───────────────────────────────────────────────────────────────────── real FFmpeg
def make_clip(*, tone: str = "1-3,5-7", seconds: int = 8, audio: bool = True) -> bytes:
    """A test clip: coloured video, with a 440 Hz tone during the given windows and silence elsewhere."""
    with tempfile.TemporaryDirectory() as d:
        out = Path(d) / "c.mp4"
        args = [
            "ffmpeg",
            "-v",
            "error",
            "-f",
            "lavfi",
            "-i",
            f"color=c=blue:s=320x240:d={seconds}:r=25",
        ]
        if audio:
            expr = "+".join(
                f"between(t,{a},{b})" for a, b in (w.split("-") for w in tone.split(","))
            )
            args += ["-f", "lavfi", "-i", f"sine=f=440:d={seconds}:r=44100"]
            args += ["-af", f"volume='if({expr},1,0)':eval=frame", "-c:a", "aac"]
        args += ["-c:v", "libx264", "-pix_fmt", "yuv420p", "-shortest", str(out)]
        subprocess.run(args, check=True)
        return out.read_bytes()


def duration_of(data: bytes) -> tuple[float, dict]:
    with tempfile.NamedTemporaryFile(suffix=".mp4") as f:
        f.write(data)
        f.flush()
        j = json.loads(
            subprocess.run(
                ["ffprobe", "-v", "error", "-show_entries",
                 "format=duration:stream=codec_type,width,height", "-of", "json", f.name],
                capture_output=True, text=True, check=True,
            ).stdout
        )  # fmt: skip
    return float(j["format"]["duration"]), {s["codec_type"]: s for s in j["streams"]}


@needs_ffmpeg
@pytest.mark.asyncio
async def test_probe_reports_length_audio_and_size():
    info = await E.probe_bytes(make_clip())
    assert (
        abs(info.duration - 8.0) < 0.3
        and info.has_audio
        and (info.width, info.height) == (320, 240)
    )
    silent = await E.probe_bytes(make_clip(audio=False))
    assert not silent.has_audio


@needs_ffmpeg
@pytest.mark.asyncio
async def test_probe_refuses_junk_and_oversize():
    with pytest.raises(VideoError) as e:
        await E.probe_bytes(b"this is not a video")
    assert e.value.code == "bad_video"
    with pytest.raises(VideoError):
        await E.probe_bytes(b"")
    with pytest.raises(VideoError):
        await E.probe_bytes(b"x" * (E.MAX_IN_BYTES + 1))


@needs_ffmpeg
@pytest.mark.asyncio
async def test_trim_and_resize_a_real_clip():
    out, meta = await E.edit_video(make_clip(), trim_start=1.0, trim_end=5.0, size_key="story")
    dur, st = duration_of(out)
    assert abs(dur - 4.0) < 0.3 and abs(meta["seconds_out"] - 4.0) < 0.01
    assert (st["video"]["width"], st["video"]["height"]) == (1080, 1920) and "audio" in st
    assert meta["silence_used"] is False


@needs_ffmpeg
@pytest.mark.asyncio
async def test_silence_is_really_removed():
    out, meta = await E.edit_video(make_clip(tone="1-3,5-7"), remove_silence=True)
    dur, _ = duration_of(out)
    assert meta["silence_used"] is True and meta["segments"] >= 2
    assert dur < 8.0 - 1.0 and abs(dur - meta["seconds_out"]) < 0.4


@needs_ffmpeg
@pytest.mark.asyncio
async def test_silence_removal_is_skipped_for_a_clip_without_audio():
    out, meta = await E.edit_video(make_clip(audio=False), remove_silence=True)
    dur, st = duration_of(out)
    assert meta["silence_used"] is False and abs(dur - 8.0) < 0.3 and "audio" not in st


@needs_ffmpeg
@pytest.mark.asyncio
async def test_bad_size_is_refused_and_temp_dirs_are_removed():
    before = set(Path(tempfile.gettempdir()).glob("jarvis-clip-*"))
    with pytest.raises(VideoError) as e:
        await E.edit_video(make_clip(), size_key="cover")
    assert e.value.code == "bad_size"
    with pytest.raises(VideoError):
        await E.edit_video(make_clip(), trim_start=7.9, trim_end=8.0)
    assert set(Path(tempfile.gettempdir()).glob("jarvis-clip-*")) == before


# ───────────────────────────────────────────────────────────────────── routes
def post(c, path, payload):
    body = json.dumps(payload).encode()
    h = make_headers(method="POST", path=path, body=body) | {"content-type": "application/json"}
    return c.post(path, content=body, headers=h)


def b64(data: bytes) -> str:
    return base64.b64encode(data).decode()


def test_routes_need_a_signature(client):
    for p in ("/internal/v1/video/probe", "/internal/v1/video/edit"):
        assert client.post(p, json={"video_b64": "AA=="}).status_code in (401, 403)


@needs_ffmpeg
def test_probe_route(client):
    r = post(client, "/internal/v1/video/probe", {"video_b64": b64(make_clip())})
    j = r.json()
    assert r.status_code == 200 and j["status"] == "ok" and j["has_audio"] and j["width"] == 320
    bad = post(client, "/internal/v1/video/probe", {"video_b64": b64(b"junk")})
    assert bad.json() == {"status": "unsupported", "reason": "bad_video"}
    enc = post(client, "/internal/v1/video/probe", {"video_b64": "!!!"})
    assert enc.json()["reason"] == "bad_encoding"


@needs_ffmpeg
def test_edit_route_returns_an_mp4_with_headers(client):
    r = post(
        client,
        "/internal/v1/video/edit",
        {"video_b64": b64(make_clip()), "trim_start": 1, "trim_end": 4, "size": "post"},
    )
    assert r.status_code == 200 and r.content[4:8] == b"ftyp"
    assert (
        abs(float(r.headers["x-seconds-out"]) - 3.0) < 0.01
        and r.headers["cache-control"] == "no-store"
    )
    bad = post(client, "/internal/v1/video/edit", {"video_b64": b64(make_clip()), "size": "cover"})
    assert bad.json()["reason"] == "bad_size"
    trim = post(
        client,
        "/internal/v1/video/edit",
        {"video_b64": b64(make_clip()), "trim_start": 7.9, "trim_end": 8},
    )
    assert trim.json()["reason"] == "bad_trim"


def test_edit_route_limits_the_shape_of_the_request(client):
    assert (
        post(client, "/internal/v1/video/edit", {"video_b64": "AA==", "trim_start": -1}).status_code
        == 422
    )
    assert post(client, "/internal/v1/video/edit", {}).status_code == 422
