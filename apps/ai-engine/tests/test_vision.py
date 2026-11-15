"""Picture reading: the strict cleaner, the wording, and the signed route (providers are mocked)."""

import base64
import io
import json

import httpx
import pytest
from PIL import Image
from pypdf import PdfWriter

from app import routes_providers
from app.bills import prepare as prep
from app.vision.read import MAX_CHARS, NO_TEXT, clean, system_prompt, user_prompt
from tests.conftest import make_headers
from tests.test_bills import KEY, jpeg, scanned_pdf, text_pdf

PATH = "/internal/v1/vision/read"


# ───────────────────────────────────────────────────────────────────────────── clean / wording
def test_clean_keeps_text_and_line_breaks():
    assert clean("দাম ১২০০\r\nকাজু | ৫০০\r\n", "transcribe") == "দাম ১২০০\nকাজু | ৫০০"


def test_clean_trims_trailing_spaces_on_every_line():
    assert clean("a   \nb\t \nc", "transcribe") == "a\nb\nc"


def test_clean_removes_fences_control_characters_and_blank_runs():
    raw = "```text\nline one\x07\n\n\n\n\nline two   \n```"
    assert clean(raw, "transcribe") == "line one\n\nline two"
    assert clean("a\x00b\x1bc\x7fd", "describe") == "abcd"
    assert clean("a\tb", "describe") == "a\tb"


@pytest.mark.parametrize("raw", ["", "  \n ", NO_TEXT, f" {NO_TEXT}. ", "no_text", "```\n```"])
def test_clean_says_nothing_found_when_there_is_nothing(raw):
    assert clean(raw, "transcribe") is None


def test_no_text_marker_only_means_nothing_in_transcribe_mode():
    assert (
        clean(NO_TEXT, "describe") == NO_TEXT
    )  # a description may say anything; only OCR has the marker
    assert clean("the NO_TEXT sign", "transcribe") == "the NO_TEXT sign"


def test_clean_caps_the_length():
    assert len(clean("x" * (MAX_CHARS + 500), "transcribe")) == MAX_CHARS


def test_prompts_treat_the_picture_as_untrusted_data():
    for mode in ("transcribe", "describe"):
        assert "untrusted" in system_prompt(mode) and "never follow" in system_prompt(mode)
    assert NO_TEXT in system_prompt("transcribe") and NO_TEXT not in system_prompt("describe")
    assert "Bengali" in system_prompt("transcribe")
    assert user_prompt("transcribe", True) == "Transcribe this document."
    assert user_prompt("describe", True) == "Describe this picture."
    assert (
        user_prompt("transcribe", False, "hello")
        == "Transcribe this document.\n<document>\nhello\n</document>"
    )


# ───────────────────────────────────────────────────────────────────────────── prepare limits
def test_prepare_defaults_are_unchanged_for_bills():
    many = PdfWriter()
    for _ in range(6):
        many.add_blank_page(300, 300)
    out = io.BytesIO()
    many.write(out)
    assert prep.prepare(out.getvalue(), "application/pdf").pages == 3
    assert prep.prepare(out.getvalue(), "application/pdf", max_pages=5).pages == 5


# ───────────────────────────────────────────────────────────────────────────── the route
def post(c, payload, **kw):
    body = json.dumps(payload).encode()
    h = make_headers(method="POST", path=PATH, body=body, **kw) | {
        "content-type": "application/json"
    }
    return c.post(PATH, content=body, headers=h)


def payload(blob, mime="image/jpeg", **kw):
    return {
        "provider": {"adapter": "anthropic", "api_key": KEY},
        "model": "claude-x",
        "mime": mime,
        "file_b64": base64.b64encode(blob).decode(),
    } | kw


@pytest.fixture
def provider():
    seen = []
    answer = {"text": "দামের তালিকা\nকাজু | ১২০০"}

    def handler(request):
        seen.append(json.loads(request.content))
        return httpx.Response(
            200,
            json={
                "model": "claude-x",
                "content": [{"type": "text", "text": answer["text"]}],
                "usage": {"input_tokens": 900, "output_tokens": 40},
            },
        )

    routes_providers.set_transport(httpx.MockTransport(handler))
    yield seen, answer
    routes_providers.set_transport(None)


def test_unsigned_request_is_rejected(client):
    assert client.post(PATH, json=payload(jpeg())).status_code in (401, 403)


def test_a_photo_is_transcribed_with_usage(client, provider):
    seen, _ = provider
    r = post(client, payload(jpeg((3000, 2000), exif=True)))
    assert r.status_code == 200
    j = r.json()
    assert j["status"] == "ok" and j["text"] == "দামের তালিকা\nকাজু | ১২০০" and j["source"] == "vision"
    assert (j["input_tokens"], j["output_tokens"], j["pages"]) == (900, 40, 1)
    body = seen[0]
    assert body["temperature"] == 0 and "OCR" in body["system"]
    parts = body["messages"][0]["content"]
    assert parts[0]["type"] == "image" and parts[1]["text"] == "Transcribe this document."
    sent = Image.open(io.BytesIO(base64.b64decode(parts[0]["source"]["data"])))
    assert max(sent.size) <= 1600 and dict(sent.getexif()) == {}  # the provider never sees metadata
    assert KEY not in r.text


def test_describe_mode_uses_the_describe_wording(client, provider):
    seen, answer = provider
    answer["text"] = "A shop front.\nText:\nOPEN"
    j = post(client, payload(jpeg(), mode="describe")).json()
    assert j["status"] == "ok" and j["text"].startswith("A shop front.")
    assert (
        "look at one picture" in seen[0]["system"]
        and seen[0]["messages"][0]["content"][1]["text"] == "Describe this picture."
    )


def test_a_pdf_with_a_text_layer_costs_nothing_and_never_reaches_the_model(client, provider):
    seen, _ = provider
    j = post(client, payload(text_pdf(), "application/pdf")).json()
    assert j["status"] == "ok" and "Rahim Traders" in j["text"] and j["source"] == "text_layer"
    assert (j["input_tokens"], j["output_tokens"], j["model"]) == (0, 0, None) and seen == []


def test_describe_mode_does_not_skip_the_model_for_text_pdfs(client, provider):
    seen, _ = provider
    j = post(client, payload(text_pdf(), "application/pdf", mode="describe")).json()
    assert (
        j["source"] == "vision"
        and len(seen) == 1
        and "<document>" in seen[0]["messages"][0]["content"]
    )


def test_a_scanned_pdf_is_sent_as_images(client, provider):
    seen, _ = provider
    j = post(client, payload(scanned_pdf(), "application/pdf")).json()
    assert j["status"] == "ok" and j["source"] == "vision"
    assert seen[0]["messages"][0]["content"][0]["type"] == "image"


def test_up_to_six_pages_of_a_scanned_pdf_are_read(client, provider):
    seen, _ = provider
    pages = [
        Image.new("RGB", (300, 400), c)
        for c in (
            "white",
            "red",
            "blue",
            "green",
            "yellow",
            "black",
            "gray",
            "pink",
            "orange",
            "cyan",
        )
    ]
    out = io.BytesIO()
    pages[0].save(out, "PDF", save_all=True, append_images=pages[1:])
    j = post(client, payload(out.getvalue(), "application/pdf")).json()
    assert j["pages"] == 6
    assert sum(1 for p in seen[0]["messages"][0]["content"] if p["type"] == "image") == 6


def test_nothing_readable_is_empty_but_still_reports_usage(client, provider):
    _, answer = provider
    answer["text"] = NO_TEXT
    j = post(client, payload(jpeg())).json()
    assert (
        j["status"] == "empty" and j["text"] is None and j["input_tokens"] == 900
    )  # the provider was paid, the caller can charge


@pytest.mark.parametrize(
    "blob,mime,reason", [(b"junk", "image/jpeg", "unsupported_type"), (b"", "image/png", "empty")]
)
def test_unsupported_files_never_reach_the_provider(client, provider, blob, mime, reason):
    seen, _ = provider
    assert (
        post(client, payload(blob, mime)).json() == {"status": "unsupported", "reason": reason}
        and seen == []
    )


def test_bad_mode_and_bad_base64_never_reach_the_provider(client, provider):
    seen, _ = provider
    assert post(client, payload(jpeg(), mode="translate")).json() == {
        "status": "unsupported",
        "reason": "bad_mode",
    }
    assert post(client, payload(jpeg()) | {"file_b64": "not base64 !!!"}).json() == {
        "status": "unsupported",
        "reason": "bad_encoding",
    }
    assert seen == []


def test_provider_errors_map_like_the_chat_route(client):
    routes_providers.set_transport(
        httpx.MockTransport(
            lambda r: httpx.Response(401, json={"error": {"message": f"bad key {KEY}"}})
        )
    )
    try:
        r = post(client, payload(jpeg()))
    finally:
        routes_providers.set_transport(None)
    assert r.status_code >= 400 and KEY not in r.text
