"""Slide videos: the FFmpeg plan (pure), a real render checked with ffprobe, and the signed route."""

import base64
import json
import shutil
import subprocess
import tempfile
from pathlib import Path

import pytest

from app.studio.compose import Brand, Copy
from app.video import render as R
from tests.conftest import make_headers

pytestmark = pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg not installed")

BRAND = Brand("#7c2d12", "#fbbf24", "GV", None, "০১৭০০")


def sc(sec=2.0, head="ঈদ অফার", **kw):
    return R.Scene(Copy(head, "সব কাজুতে ৩০% ছাড়", "অর্ডার করুন", **kw), sec)


def probe(data: bytes) -> dict:
    with tempfile.NamedTemporaryFile(suffix=".mp4") as f:
        f.write(data)
        f.flush()
        out = subprocess.run(
            [
                "ffprobe",
                "-v",
                "error",
                "-show_entries",
                "format=duration:stream=codec_type,codec_name,width,height,r_frame_rate",
                "-of",
                "json",
                f.name,
            ],
            capture_output=True,
            text=True,
            check=True,
        ).stdout
    j = json.loads(out)
    return {
        "duration": float(j["format"]["duration"]),
        "streams": {s["codec_type"]: s for s in j["streams"]},
    }


def mp3(seconds: float) -> bytes:
    with tempfile.TemporaryDirectory() as d:
        p = Path(d) / "a.mp3"
        subprocess.run(
            [
                "ffmpeg",
                "-v",
                "error",
                "-f",
                "lavfi",
                "-i",
                f"sine=frequency=440:duration={seconds}",
                "-c:a",
                "libmp3lame",
                str(p),
            ],
            check=True,
        )
        return p.read_bytes()


# ───────────────────────────────────────────────────────────────────────────── plan (pure)
def test_checks_on_scene_count_length_and_total():
    with pytest.raises(R.VideoError) as e:
        R.check_scenes([])
    assert e.value.code == "bad_scenes"
    with pytest.raises(R.VideoError):
        R.check_scenes([sc()] * 11)
    for bad in (1.9, 8.1):
        with pytest.raises(R.VideoError) as e:
            R.check_scenes([sc(bad)])
        assert e.value.code == "bad_seconds"
    assert R.check_scenes([sc(2.0), sc(8.0)]) == 10.0
    with pytest.raises(R.VideoError) as e:
        R.check_scenes([sc(7.0)] * 9)  # 63 s
    assert e.value.code == "too_long"
    assert R.check_scenes([sc(6.0)] * 10) == 60.0


def test_mp3_is_recognised_by_its_first_bytes():
    assert R.is_mp3(b"ID3" + b"\x00" * 10)
    assert R.is_mp3(b"\xff\xfb\x90\x00")
    assert not R.is_mp3(b"RIFF....WAVE")
    assert not R.is_mp3(b"\x89PNG")
    assert not R.is_mp3(b"")
    assert not R.is_mp3(b"\xff")


def test_crossfade_offsets_are_the_running_sum_of_scene_lengths():
    a = R.build_args(["a", "b", "c"], [3.0, 2.0, 4.0], (1080, 1080), None, "o.mp4")
    g = a[a.index("-filter_complex") + 1]
    assert "xfade=transition=fade:duration=0.5:offset=3.000[x1]" in g
    assert "[x1][v2]xfade=transition=fade:duration=0.5:offset=5.000[x2]" in g
    assert a[a.index("-map") + 1] == "[x2]"
    assert a[a.index("-t") + 1] == "9.000"  # 3 + 2 + 4: the fades overlap, they do not add time


def test_every_scene_but_the_last_is_held_for_the_fade():
    g = R.build_args(["a", "b"], [2.0, 2.0], (1080, 1920), None, "o.mp4")
    g = g[g.index("-filter_complex") + 1]
    assert ":d=62:" in g.split(";")[0]  # (2 + 0.5) s * 25 fps = 62.5, rounded
    assert ":d=50:" in g.split(";")[1]
    assert "s=1080x1920" in g


def test_without_fade_scenes_are_concatenated_and_zoom_can_be_off():
    a = R.build_args(["a", "b"], [2.0, 3.0], (1080, 1080), None, "o.mp4", fade=False, motion=False)
    g = a[a.index("-filter_complex") + 1]
    assert "concat=n=2:v=1:a=0[cat]" in g and "xfade" not in g
    assert "z='1'" in g and "zoom+" not in g
    assert a[a.index("-map") + 1] == "[cat]"
    assert ":d=50:" in g.split(";")[0] and ":d=75:" in g.split(";")[1]


def test_a_single_scene_needs_no_join_and_silence_is_generated_when_there_is_no_voice():
    a = R.build_args(["a"], [4.0], (1080, 1080), None, "o.mp4")
    g = a[a.index("-filter_complex") + 1]
    assert "xfade" not in g and "concat" not in g and a[a.index("-map") + 1] == "[v0]"
    assert "anullsrc" in " ".join(a)
    b = R.build_args(["a"], [4.0], (1080, 1080), "voice.mp3", "o.mp4")
    assert "anullsrc" not in " ".join(b) and "voice.mp3" in b
    assert (
        "[1:a]aresample=44100,apad,atrim=duration=4.000[aout]" in b[b.index("-filter_complex") + 1]
    )


def test_the_command_is_a_list_with_safe_defaults():
    a = R.build_args(["a", "b"], [2.0, 2.0], (1080, 1080), None, "o.mp4")
    assert isinstance(a, list) and a[0] == "ffmpeg" and "-nostdin" in a
    assert a[-1] == "o.mp4" and "+faststart" in a and "libx264" in a and "yuv420p" in a


# ───────────────────────────────────────────────────────────────────────────── real renders
async def test_a_real_video_has_the_right_size_length_and_streams():
    data = await R.render_video("post", [sc(2.0), sc(2.0, "দ্বিতীয়", theme="light")], BRAND, None)
    p = probe(data)
    assert abs(p["duration"] - 4.0) < 0.1
    v = p["streams"]["video"]
    assert (v["codec_name"], v["width"], v["height"], v["r_frame_rate"]) == (
        "h264",
        1080,
        1080,
        "25/1",
    )
    assert p["streams"]["audio"]["codec_name"] == "aac"


async def test_story_and_ad_shapes():
    for key, wh in (("story", (1080, 1920)), ("ad", (1200, 628))):
        p = probe(await R.render_video(key, [sc(2.0)], BRAND, None, fade=False))
        assert (p["streams"]["video"]["width"], p["streams"]["video"]["height"]) == wh


async def test_a_short_voice_over_is_padded_and_a_long_one_is_cut_to_the_picture():
    for secs in (1.0, 9.0):
        p = probe(await R.render_video("post", [sc(2.0), sc(2.0)], BRAND, mp3(secs)))
        assert abs(p["duration"] - 4.0) < 0.1


async def test_a_photo_background_is_used():
    import io

    from PIL import Image

    out = io.BytesIO()
    Image.new("RGB", (400, 400), (0, 90, 200)).save(out, "PNG")
    s = R.Scene(Copy("ছবি"), 2.0, out.getvalue())
    assert (
        probe(await R.render_video("post", [s], BRAND, None))["streams"]["video"]["width"] == 1080
    )


async def test_the_temp_folder_is_always_removed(monkeypatch):
    made = []
    real = tempfile.mkdtemp

    def spy(*a, **k):
        d = real(*a, **k)
        made.append(d)
        return d

    monkeypatch.setattr(R.tempfile, "mkdtemp", spy)
    await R.render_video("post", [sc(2.0)], BRAND, None)
    with pytest.raises(R.VideoError):
        await R.render_video("post", [sc(2.0)], BRAND, b"not an mp3")
    monkeypatch.setattr(R, "TIMEOUT_S", 0.001)
    with pytest.raises(R.VideoError) as e:
        await R.render_video("post", [sc(2.0)], BRAND, None)
    assert e.value.code == "timeout"
    assert made and not any(Path(d).exists() for d in made)


async def test_refusals_happen_before_any_work():
    for kw, code in (({"size_key": "cover"}, "bad_size"), ({"size_key": "huge"}, "bad_size")):
        with pytest.raises(R.VideoError) as e:
            await R.render_video(
                **({"size_key": "post", "scenes": [sc()], "brand": BRAND, "audio": None} | kw)
            )
        assert e.value.code == code
    with pytest.raises(R.VideoError) as e:
        await R.render_video("post", [sc()], BRAND, b"\x00" * 10)
    assert e.value.code == "bad_audio"
    with pytest.raises(R.VideoError) as e:
        await R.render_video("post", [sc()], BRAND, b"ID3" + b"\x00" * R.MAX_AUDIO_BYTES)
    assert e.value.code == "bad_audio"
    with pytest.raises(R.VideoError) as e:
        await R.render_video("post", [sc()], BRAND, b"")
    assert e.value.code == "bad_audio"


async def test_missing_ffmpeg_is_reported(monkeypatch):
    monkeypatch.setattr(R.shutil, "which", lambda _: None)
    with pytest.raises(R.VideoError) as e:
        await R.render_video("post", [sc()], BRAND, None)
    assert e.value.code == "no_ffmpeg"


# ───────────────────────────────────────────────────────────────────────────── route
PATH = "/internal/v1/video/render"


def post(c, payload):
    body = json.dumps(payload).encode()
    h = make_headers(method="POST", path=PATH, body=body) | {"content-type": "application/json"}
    return c.post(PATH, content=body, headers=h)


def payload(**kw):
    return {
        "size": "post",
        "scenes": [{"copy": {"headline": "ঈদ", "subline": "অফার"}, "seconds": 2}],
        "brand": {"primary": "#7c2d12", "accent": "#fbbf24", "name": "GV"},
    } | kw


def test_route_needs_a_signature(client):
    assert client.post(PATH, json=payload()).status_code in (401, 403)


def test_route_returns_an_mp4(client):
    r = post(client, payload(audio_b64=base64.b64encode(mp3(1.0)).decode()))
    assert r.status_code == 200 and r.headers["content-type"] == "video/mp4"
    assert r.headers["cache-control"] == "no-store"
    assert abs(probe(r.content)["duration"] - 2.0) < 0.1


@pytest.mark.parametrize(
    "patch,reason",
    [
        ({"size": "cover"}, "bad_size"),
        ({"scenes": [{"copy": {}, "seconds": 1}]}, "bad_seconds"),
        ({"scenes": [{"copy": {}, "seconds": 8}] * 8}, "too_long"),
        ({"audio_b64": base64.b64encode(b"junk").decode()}, "bad_audio"),
        ({"audio_b64": "not base64 !!"}, "bad_encoding"),
        ({"scenes": [{"copy": {}, "seconds": 2, "base_b64": "!!"}]}, "bad_encoding"),
        (
            {
                "scenes": [
                    {"copy": {}, "seconds": 2, "base_b64": base64.b64encode(b"junk").decode()}
                ]
            },
            "bad_base",
        ),
        ({"brand": {"primary": "red"}}, "bad_color"),
    ],
)
def test_route_rejects_bad_requests_with_a_reason(client, patch, reason):
    r = post(client, payload(**patch))
    assert r.status_code == 200 and r.json() == {"status": "unsupported", "reason": reason}


def test_route_limits_the_shape_of_the_request(client):
    assert post(client, payload(scenes=[])).status_code == 422
    assert post(client, payload(scenes=[{"copy": {}, "seconds": 2}] * 11)).status_code == 422


def test_route_says_503_when_ffmpeg_is_missing_or_too_slow(client, monkeypatch):
    monkeypatch.setattr(R.shutil, "which", lambda _: None)
    r = post(client, payload())
    assert r.status_code == 503 and r.json()["reason"] == "no_ffmpeg"


# ───────────────────────────────────────────────────────────────────────────── subtitles
def test_caption_plan_spreads_time_in_proportion_and_covers_the_span():
    plan = R.plan_captions(["ab", "abcd", "  ", "abcdef"], 12.0)
    assert [c for c, _, _ in plan] == ["ab", "abcd", "abcdef"]
    assert plan[0][1] == 0.0 and plan[-1][2] == 12.0
    assert [round(b - a, 3) for _, a, b in plan] == [2.0, 4.0, 6.0]
    assert plan[0][2] == plan[1][1] and plan[1][2] == plan[2][1]
    assert R.plan_captions([], 5.0) == [] and R.plan_captions(["  "], 5.0) == []


def test_caption_plan_limits():
    with pytest.raises(R.VideoError) as e:
        R.plan_captions(["x"] * 41, 10.0)
    assert e.value.code == "bad_captions"
    with pytest.raises(R.VideoError):
        R.plan_captions(["x" * 121], 10.0)
    assert len(R.plan_captions(["x"] * 40, 10.0)) == 40
    assert len(R.plan_captions(["x" * 120], 10.0)) == 1


def test_args_overlay_each_caption_in_its_own_window_after_the_audio_input():
    args = R.build_args(
        ["a.png", "b.png"],
        [3.0, 3.0],
        (1080, 1920),
        "v.mp3",
        "o.mp4",
        captions=[("x", 0.0, 2.5), ("y", 2.5, 6.0)],
        caption_files=["c0.png", "c1.png"],
    )
    ins = [args[i + 1] for i, a in enumerate(args) if a == "-i"]
    assert ins == ["a.png", "b.png", "v.mp3", "c0.png", "c1.png"]
    g = args[args.index("-filter_complex") + 1]
    assert "[x1][3:v]overlay=enable='between(t,0.000,2.500)'" in g
    assert "[o0][4:v]overlay=enable='between(t,2.500,6.000)'" in g
    assert args[args.index("-map") + 1] == "[o1]"


def test_args_without_captions_are_unchanged():
    a = R.build_args(["a.png"], [3.0], (1080, 1080), None, "o.mp4")
    assert "overlay" not in a[a.index("-filter_complex") + 1]
    assert a[a.index("-map") + 1] == "[v0]"


def test_caption_picture_is_transparent_with_a_box_near_the_bottom():
    from app.studio.compose import SIZES, render_caption

    im = render_caption("story", "আমাদের কাজুবাদাম একদম খাঁটি")
    assert im.size == SIZES["story"] and im.mode == "RGBA"
    box = im.getchannel("A").getbbox()
    assert box is not None
    w, h = im.size
    assert box[1] > h * 0.6 and box[3] < h and box[0] > 0 and box[2] < w
    assert im.getpixel((2, 2))[3] == 0
    assert render_caption("post", "").getchannel("A").getbbox() is None


def test_caption_longer_text_makes_a_taller_or_wider_box():
    from app.studio.compose import render_caption

    short = render_caption("post", "ছোট").getchannel("A").getbbox()
    long = (
        render_caption("post", "এটি একটি অনেক লম্বা বাক্য যা কয়েক লাইনে ভাঙতে হবে " * 2)
        .getchannel("A")
        .getbbox()
    )
    assert (long[2] - long[0]) > (short[2] - short[0]) and (long[3] - long[1]) > (
        short[3] - short[1]
    )


def test_caption_refuses_a_bad_size():
    from app.studio.compose import ComposeError, render_caption

    with pytest.raises(ComposeError) as e:
        render_caption("nope", "x")
    assert e.value.code == "bad_size"


def frame_at(data: bytes, t: float):
    from PIL import Image

    with tempfile.TemporaryDirectory() as d:
        mp4 = Path(d) / "v.mp4"
        mp4.write_bytes(data)
        png = Path(d) / "f.png"
        subprocess.run(
            ["ffmpeg", "-v", "error", "-ss", str(t), "-i", str(mp4), "-frames:v", "1", str(png)],
            check=True,
        )
        return Image.open(png).convert("L").copy()


@pytest.mark.asyncio
async def test_real_render_shows_the_caption_only_in_its_window():
    scenes = [sc(4.0, theme="dark")]
    plain = await R.render_video("post", scenes, BRAND, None, motion=False, fade=False)
    capt = await R.render_video(
        "post", scenes, BRAND, None, motion=False, fade=False, captions=["প্রথম", "দ্বিতীয়"]
    )
    assert abs(probe(capt)["duration"] - 4.0) < 0.2
    h = 1080
    band = (0, int(h * 0.62), 1080, int(h * 0.92))

    def bright(im):
        px = im.crop(band).getdata()
        return sum(1 for v in px if v > 235)

    assert bright(frame_at(capt, 1.0)) > bright(frame_at(plain, 1.0)) + 200
    assert bright(frame_at(capt, 3.0)) > bright(frame_at(plain, 3.0)) + 200
    # the two windows show different text
    a, b = frame_at(capt, 1.0).crop(band), frame_at(capt, 3.0).crop(band)
    assert list(a.getdata()) != list(b.getdata())


@pytest.mark.asyncio
async def test_captions_fit_the_voice_length_not_the_video_length():
    scenes = [sc(6.0)]
    out = await R.render_video(
        "post", scenes, BRAND, mp3(2.0), motion=False, fade=False, captions=["এক", "দুই"]
    )
    band = (0, int(1080 * 0.62), 1080, int(1080 * 0.92))

    def bright(im):
        return sum(1 for v in im.crop(band).getdata() if v > 235)

    assert bright(frame_at(out, 1.5)) > 0
    # after the 2 s voice ends the last caption box is gone
    after = frame_at(out, 4.0)
    during = frame_at(out, 1.5)
    assert bright(during) > bright(after) + 200


def test_route_accepts_captions_and_rejects_too_many(client):
    r = post(client, payload(captions=["এক", "দুই"]))
    assert r.status_code == 200 and r.content[4:8] == b"ftyp"
    assert post(client, payload(captions=["x"] * 41)).status_code == 422
    r = post(client, payload(captions=["x" * 121]))
    assert r.status_code == 200 and r.json()["reason"] == "bad_captions"


# ───────────────────────────────────────────────────────────────────────────── music
def test_music_presets_are_defined_with_sane_values():
    assert set(R.MUSIC) == {"calm", "warm", "upbeat", "cinematic"}
    for freqs, hz, depth, vol in R.MUSIC.values():
        assert 3 <= len(freqs) <= 4 and all(80 < f < 1000 for f in freqs)
        assert 0 < hz <= 4 and 0 < depth <= 1 and 0 < vol <= 0.5


def test_music_graph_is_quieter_under_a_voice_and_fades_at_both_ends():
    srcs, chain = R.music_graph("calm", 10.0, 5, under_voice=False)
    assert len(srcs) == 3 and all(s.startswith("sine=f=") and ":d=10.000" in s for s in srcs)
    assert chain.startswith("[5:a][6:a][7:a]amix=inputs=3") and chain.endswith("[mus]")
    assert "afade=t=in" in chain and "afade=t=out:st=8.000" in chain and "volume=0.300" in chain
    _, quiet = R.music_graph("calm", 10.0, 5, under_voice=True)
    assert "volume=0.135" in quiet
    _, short = R.music_graph("calm", 1.5, 0, under_voice=False)
    assert "afade=t=out:st=0.000" in short


def test_args_add_music_inputs_after_the_captions_and_mix_with_the_base_audio():
    args = R.build_args(
        ["a.png"], [4.0], (1080, 1080), "v.mp3", "o.mp4",
        captions=[("x", 0.0, 2.0)], caption_files=["c0.png"], music="warm", has_voice=True,
    )  # fmt: skip
    ins = [args[i + 1] for i, a in enumerate(args) if a == "-i"]
    assert ins[:3] == ["a.png", "v.mp3", "c0.png"] and len(ins) == 6
    assert all(x.startswith("sine=") for x in ins[3:])
    g = args[args.index("-filter_complex") + 1]
    assert "[3:a][4:a][5:a]amix=inputs=3" in g
    assert "[1:a]aresample=44100,apad,atrim=duration=4.000[base]" in g
    assert "[base][mus]amix=inputs=2:duration=first:normalize=0[aout]" in g


def test_args_without_music_are_unchanged():
    a = R.build_args(["a.png"], [4.0], (1080, 1080), None, "o.mp4")
    g = a[a.index("-filter_complex") + 1]
    assert "mus" not in g and "[aout]" in g and "sine" not in " ".join(a)


@pytest.mark.asyncio
async def test_unknown_music_is_refused():
    with pytest.raises(R.VideoError) as e:
        await R.render_video("post", [sc(2.0)], BRAND, None, music="disco")
    assert e.value.code == "bad_music"


def loudness(data: bytes) -> float:
    """Mean volume in dB of the first audio stream."""
    with tempfile.NamedTemporaryFile(suffix=".mp4") as f:
        f.write(data)
        f.flush()
        r = subprocess.run(
            ["ffmpeg", "-nostdin", "-i", f.name, "-vn", "-af", "volumedetect", "-f", "null", "-"],
            capture_output=True, text=True,
        )  # fmt: skip
    for line in r.stderr.splitlines():
        if "mean_volume" in line:
            return float(line.split("mean_volume:")[1].split("dB")[0])
    raise AssertionError("no volume")


@pytest.mark.asyncio
@pytest.mark.parametrize("name", ["calm", "warm", "upbeat", "cinematic"])
async def test_every_preset_renders_audible_music_of_the_right_length(name):
    silent = await R.render_video("post", [sc(3.0)], BRAND, None, motion=False)
    out = await R.render_video("post", [sc(3.0)], BRAND, None, motion=False, music=name)
    info = probe(out)
    assert abs(info["duration"] - 3.0) < 0.3 and "audio" in info["streams"]
    assert loudness(out) > loudness(silent) + 15 and loudness(out) < -12


@pytest.mark.asyncio
async def test_music_sits_under_the_voice():
    voice = mp3(3.0)
    plain = await R.render_video("post", [sc(3.0)], BRAND, voice, motion=False)
    mixed = await R.render_video("post", [sc(3.0)], BRAND, voice, motion=False, music="calm")
    assert abs(probe(mixed)["duration"] - 3.0) < 0.3
    assert abs(loudness(mixed) - loudness(plain)) < 6  # the voice is still the main sound


def test_route_accepts_music_and_rejects_unknown(client):
    r = post(client, payload(music="calm"))
    assert r.status_code == 200 and r.content[4:8] == b"ftyp"
    bad = post(client, payload(music="disco"))
    assert bad.status_code == 200 and bad.json()["reason"] == "bad_music"
    assert post(client, payload(music="x" * 21)).status_code == 422
