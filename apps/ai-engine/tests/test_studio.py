"""Design studio: the composer (real fonts, sizes, safety) and the two signed routes."""

import base64
import io
import json

import httpx
import pytest
from PIL import Image

from app import routes_providers
from app.studio import compose as C
from tests.conftest import make_headers
from tests.test_bills import KEY

BRAND = C.Brand(primary="#7c2d12", accent="#fbbf24", name="GoldVest", footer="০১৭০০-০০০০০০")
COPY = C.Copy("ঈদ অফার: সব কাজুতে ৩০% ছাড়", "প্রিমিয়াম কাজু, কিসমিস ও বাদাম। Free delivery", "অর্ডার করুন")


def png(size=(400, 300), color=(10, 120, 200), fmt="PNG"):
    out = io.BytesIO()
    Image.new("RGB", size, color).save(out, fmt)
    return out.getvalue()


# ───────────────────────────────────────────────────────────────────────────── text shaping
def test_runs_send_bengali_to_the_bengali_font_and_the_rest_to_latin():
    assert C.runs("ঈদ 30%") == [("ঈদ", "bengali"), (" 30%", "latin")]
    assert C.runs("অফার: সব") == [("অফার", "bengali"), (": ", "latin"), ("সব", "bengali")]
    assert C.runs("বাদাম।") == [("বাদাম।", "bengali")]  # the danda is Bengali
    assert C.runs("") == []


def test_bengali_conjuncts_are_shaped_not_spelled_out():
    f = C._font("bengali", False, 100)
    parts = f.getlength("ক") + f.getlength("্") + f.getlength("ষ")
    assert f.getlength("ক্ষ") < parts  # ক্ষ is one joined glyph


def test_wrap_and_fit_keep_every_line_inside_the_box():
    text = "সব কাজুতে ৩০% ছাড় এই সপ্তাহে শুধুমাত্র আমাদের অনলাইন শপে"
    size, lines = C.fit(text, True, 500, 600, 100, 30, 4)
    assert 1 < len(lines) <= 4 and all(C.text_width(x, size, True) <= 500 for x in lines)
    assert " ".join(lines) == text  # nothing is lost when it fits
    assert C.wrap("", 40, False, 300) == []


def test_clean_line_removes_control_and_bidi_characters():
    assert C.clean_line("a\x00b‮c\n\n d\t e", 50) == "a b c d e"
    assert len(C.clean_line("x" * 500, 90)) == 90


# ───────────────────────────────────────────────────────────────────────────── colours
def test_text_colour_is_always_readable_on_the_background():
    for hexc in ("#000000", "#ffffff", "#fbbf24", "#1f3a8a", "#777777"):
        bg = C.rgb(hexc)
        assert C.contrast(C.readable_on(bg), bg) >= 4.4  # 4.48 is the mathematical floor (mid grey)


# ───────────────────────────────────────────────────────────────────────────── render
@pytest.mark.parametrize("key,size", list(C.SIZES.items()))
def test_every_size_renders_at_its_exact_dimensions(key, size):
    im = C.render(key, COPY, BRAND, None)
    assert im.size == size and im.mode == "RGB"


def test_text_really_is_drawn_and_differs_with_the_words():
    a = C.render("post", COPY, BRAND, None)
    blank = C.render("post", C.Copy(), BRAND, None)
    other = C.render("post", C.Copy("অন্য কথা"), BRAND, None)
    assert a.tobytes() != blank.tobytes() and a.tobytes() != other.tobytes()


def test_photo_background_logo_and_light_theme_all_render():
    base = png((900, 500))
    logo = png((300, 120), (200, 30, 30))
    b = C.Brand("#1f3a8a", "#f59e0b", "X", logo, "foot")
    for theme in C.THEMES:
        im = C.render("story", C.Copy("Sale", "now", "Buy", theme), b, base)
        assert im.size == (1080, 1920)
    top_left = C.render("post", C.Copy("Hi"), b, None).getpixel((100, 100))
    assert top_left[0] > 150  # the red logo sits top-left, not under the headline


def test_nothing_overlaps_the_logo_on_short_sizes():
    logo = png((300, 120), (255, 0, 0))
    words = "এক দুই তিন চার পাঁচ ছয় সাত আট নয় দশ"
    im = C.render("ad", C.Copy(words, "x", "go"), C.Brand(logo=logo), None)
    inside = [im.getpixel((x, y)) for x in range(60, 330, 6) for y in range(60, 150, 6)]
    assert all(p == (255, 0, 0) for p in inside)  # the logo is untouched: text starts below it


def test_bad_inputs_are_rejected_with_a_code():
    for kwargs, code in [
        (dict(size_key="nope"), "bad_size"),
        (dict(copy=C.Copy(theme="neon")), "bad_theme"),
        (dict(brand=C.Brand(primary="red")), "bad_color"),
        (dict(brand=C.Brand(accent="#12345")), "bad_color"),
        (dict(base=b"not a picture"), "bad_base"),
        (dict(brand=C.Brand(logo=b"junk")), "bad_logo"),
    ]:
        args = dict(size_key="post", copy=COPY, brand=BRAND, base=None) | kwargs
        with pytest.raises(C.ComposeError) as e:
            C.render(**args)
        assert e.value.code == code


def test_pictures_that_are_not_png_jpeg_or_webp_or_too_large_are_refused():
    with pytest.raises(C.ComposeError):
        C.open_image(png(fmt="GIF"), 10_000_000, "bad_base")
    with pytest.raises(C.ComposeError):
        C.open_image(png((200, 200)), 1000, "bad_base")
    assert C.open_image(png(fmt="JPEG"), 10_000_000, "x").mode == "RGBA"


def test_encode_formats():
    im = C.render("post", COPY, BRAND, None)
    p, m = C.encode(im, "png")
    j, jm = C.encode(im, "jpeg")
    assert p[:8] == b"\x89PNG\r\n\x1a\n" and m == "image/png"
    assert j[:3] == b"\xff\xd8\xff" and jm == "image/jpeg"


# ───────────────────────────────────────────────────────────────────────────── routes
def post(c, path, payload):
    body = json.dumps(payload).encode()
    h = make_headers(method="POST", path=path, body=body) | {"content-type": "application/json"}
    return c.post(path, content=body, headers=h)


def b64(b):
    return base64.b64encode(b).decode()


def compose_payload(**kw):
    return {
        "copy": {"headline": "ঈদ অফার", "subline": "কাজু", "cta": "কিনুন"},
        "brand": {"primary": "#7c2d12", "accent": "#fbbf24", "name": "GV"},
        "sizes": ["post", "story"],
    } | kw


def test_routes_require_a_signature(client):
    assert client.post("/internal/v1/studio/compose", json=compose_payload()).status_code in (
        401,
        403,
    )
    assert client.post("/internal/v1/studio/generate", json={}).status_code in (401, 403)


def test_compose_returns_every_size(client):
    r = post(
        client, "/internal/v1/studio/compose", compose_payload(sizes=["post", "story", "post"])
    )
    j = r.json()
    assert j["status"] == "ok" and [i["size"] for i in j["items"]] == [
        "post",
        "story",
    ]  # de-duplicated
    first = Image.open(io.BytesIO(base64.b64decode(j["items"][0]["image_b64"])))
    assert first.size == (1080, 1080) and j["items"][0]["mime"] == "image/png"


def test_compose_with_photo_logo_and_jpeg(client):
    p = compose_payload(base_b64=b64(png((600, 600))), fmt="jpeg")
    p["brand"]["logo_b64"] = b64(png((200, 80)))
    j = post(client, "/internal/v1/studio/compose", p).json()
    assert j["status"] == "ok" and j["items"][0]["mime"] == "image/jpeg"


@pytest.mark.parametrize(
    "patch,reason",
    [
        ({"sizes": ["huge"]}, "bad_size"),
        ({"base_b64": "not base64 !!"}, "bad_encoding"),
        ({"base_b64": b64(b"junk")}, "bad_base"),
        ({"copy": {"headline": "x", "theme": "neon"}}, "bad_theme"),
    ],
)
def test_compose_rejects_bad_input(client, patch, reason):
    j = post(client, "/internal/v1/studio/compose", compose_payload(**patch)).json()
    assert j == {"status": "unsupported", "reason": reason}


def test_compose_rejects_more_than_five_sizes_and_bad_format(client):
    assert (
        post(client, "/internal/v1/studio/compose", compose_payload(sizes=["post"] * 6)).status_code
        == 422
    )
    assert (
        post(client, "/internal/v1/studio/compose", compose_payload(fmt="gif")).status_code == 422
    )


@pytest.fixture
def images():
    seen = []
    answer = {"json": None}

    def handler(request):
        seen.append((request.url.path, json.loads(request.content)))
        return httpx.Response(
            200, json=answer["json"] or {"data": [{"b64_json": b64(png((300, 300)))}]}
        )

    routes_providers.set_transport(httpx.MockTransport(handler))
    yield seen, answer
    routes_providers.set_transport(None)


def gen(**kw):
    return {
        "provider": {"adapter": "openai", "api_key": KEY},
        "model": "gpt-image-1",
        "prompt": "a bowl of cashew nuts on a wooden table",
    } | kw


def test_generate_returns_a_clean_png_and_forbids_text_in_the_picture(client, images):
    seen, _ = images
    j = post(client, "/internal/v1/studio/generate", gen()).json()
    assert j["status"] == "ok" and j["images"] == 1 and (j["width"], j["height"]) == (300, 300)
    assert base64.b64decode(j["image_b64"])[:8] == b"\x89PNG\r\n\x1a\n"
    path, body = seen[0]
    assert path.endswith("/images/generations") and body["n"] == 1 and body["size"] == "1024x1024"
    assert "no text" in body["prompt"] and "cashew" in body["prompt"]
    assert "response_format" not in body  # gpt-image rejects it


def test_dalle_models_ask_for_inline_data(client, images):
    seen, _ = images
    post(client, "/internal/v1/studio/generate", gen(model="dall-e-3"))
    assert seen[0][1]["response_format"] == "b64_json"


def test_a_url_only_answer_is_never_fetched(client, images):
    _, answer = images
    answer["json"] = {"data": [{"url": "http://169.254.169.254/latest"}]}
    j = post(client, "/internal/v1/studio/generate", gen()).json()
    assert j == {"status": "unsupported", "reason": "no_inline_image"}


def test_generate_refuses_garbage_pictures_and_odd_requests(client, images):
    seen, answer = images
    answer["json"] = {"data": [{"b64_json": b64(b"junk")}]}
    assert post(client, "/internal/v1/studio/generate", gen()).status_code >= 400
    assert (
        post(client, "/internal/v1/studio/generate", gen(size="9999x9999")).json()["reason"]
        == "bad_size"
    )
    bad = gen(provider={"adapter": "anthropic", "api_key": KEY})
    assert post(client, "/internal/v1/studio/generate", bad).json()["reason"] == "adapter_no_images"
    assert (
        post(client, "/internal/v1/studio/generate", gen(prompt="  \x00 ab ")).json()["reason"]
        == "empty_prompt"
    )
    assert len(seen) == 1  # only the first (garbage) answer ever reached the provider


def test_generate_maps_provider_errors_and_hides_the_key(client):
    routes_providers.set_transport(
        httpx.MockTransport(
            lambda r: httpx.Response(401, json={"error": {"message": f"bad {KEY}"}})
        )
    )
    try:
        r = post(client, "/internal/v1/studio/generate", gen())
    finally:
        routes_providers.set_transport(None)
    assert r.status_code >= 400 and KEY not in r.text


def test_bengali_is_refused_when_the_shaping_engine_is_missing(monkeypatch):
    monkeypatch.setattr(C, "shaping_ok", lambda: False)
    with pytest.raises(C.ComposeError) as e:
        C.render("post", COPY, BRAND, None)
    assert e.value.code == "no_raqm"
    assert C.render("post", C.Copy("Eid Offer 30% off"), C.Brand(), None).size == (
        1080,
        1080,
    )  # Latin needs no shaping
