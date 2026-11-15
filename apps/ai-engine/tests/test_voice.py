"""Speech: audio in -> text, text in -> audio (providers are mocked)."""

import base64
import json

import httpx
import pytest

from app import routes_providers
from app.voice.audio import MAX_AUDIO_BYTES, base_mime, clean_text
from tests.conftest import make_headers
from tests.test_bills import KEY

STT = "/internal/v1/voice/transcribe"
TTS = "/internal/v1/voice/speak"
AUDIO = b"\x1aE\xdf\xa3" + b"x" * 500


def post(c, path, payload, **kw):
    body = json.dumps(payload).encode()
    h = make_headers(method="POST", path=path, body=body, **kw) | {
        "content-type": "application/json"
    }
    return c.post(path, content=body, headers=h)


def stt(blob=AUDIO, **kw):
    return {
        "provider": {"adapter": "openai", "api_key": KEY},
        "model": "whisper-1",
        "mime": "audio/webm;codecs=opus",
        "audio_b64": base64.b64encode(blob).decode(),
    } | kw


def tts(**kw):
    return {
        "provider": {"adapter": "openai", "api_key": KEY},
        "model": "tts-1",
        "text": "হ্যালো",
    } | kw


@pytest.fixture
def provider():
    seen = []
    state = {"status": 200, "text": " আজকের দাম কত? ", "audio": b"ID3" + b"a" * 100}

    def handler(request):
        seen.append(request)
        if request.url.path.endswith("/audio/transcriptions"):
            return httpx.Response(state["status"], json={"text": state["text"]})
        return httpx.Response(state["status"], content=state["audio"])

    routes_providers.set_transport(httpx.MockTransport(handler))
    yield seen, state
    routes_providers.set_transport(None)


def test_helpers():
    assert base_mime("Audio/WebM;codecs=opus") == "audio/webm"
    assert clean_text("a\x00b \n c\x07 ") == "ab\n c"


def test_unsigned_requests_are_rejected(client):
    assert client.post(STT, json=stt()).status_code in (401, 403)
    assert client.post(TTS, json=tts()).status_code in (401, 403)


def test_audio_becomes_text_as_a_multipart_upload(client, provider):
    seen, _ = provider
    r = post(client, STT, stt(language="bn-BD"))
    j = r.json()
    assert r.status_code == 200 and j["status"] == "ok" and j["text"] == "আজকের দাম কত?"
    assert j["bytes"] == len(AUDIO) and KEY not in r.text
    req = seen[0]
    assert req.url.path.endswith("/audio/transcriptions")
    assert req.headers["authorization"] == f"Bearer {KEY}"
    assert req.headers["content-type"].startswith("multipart/form-data")
    body = req.content
    assert b'filename="audio.webm"' in body and b"whisper-1" in body and b'name="language"' in body
    assert b"\r\n\r\nbn\r\n" in body  # region part dropped
    assert AUDIO in body


def test_empty_transcript_is_reported_as_empty(client, provider):
    _, state = provider
    state["text"] = "   "
    assert post(client, STT, stt()).json()["status"] == "empty"


@pytest.mark.parametrize(
    ("kw", "reason"),
    [
        ({"mime": "image/png"}, "bad_audio_type"),
        ({"language": "xx_yy!"}, "bad_language"),
        ({"audio_b64": "!!!"}, "bad_encoding"),
        ({"audio_b64": ""}, "bad_size"),
        ({"provider": {"adapter": "anthropic", "api_key": KEY}}, "adapter_no_speech"),
        ({"provider": {"adapter": "gemini", "api_key": KEY}}, "adapter_no_speech"),
    ],
)
def test_unsupported_inputs_never_reach_the_provider(client, provider, kw, reason):
    seen, _ = provider
    j = post(client, STT, stt(**kw)).json()
    assert j == {"status": "unsupported", "reason": reason} and seen == []


def test_too_big_audio_is_refused(client, provider):
    j = post(client, STT, stt(b"x" * (MAX_AUDIO_BYTES + 1))).json()
    assert j["reason"] == "bad_size"


def test_provider_errors_are_mapped_without_leaking_the_key(client, provider):
    _, state = provider
    state["status"] = 401
    r = post(client, STT, stt())
    assert r.status_code == 502 and r.json()["error"]["code"] == "auth_failed" and KEY not in r.text
    state["status"] = 429
    assert post(client, TTS, tts()).status_code == 429


def test_text_becomes_mp3_audio(client, provider):
    seen, state = provider
    r = post(client, TTS, tts(voice="nova", text="  হ্যালো \x00 জগৎ "))
    j = r.json()
    assert j["status"] == "ok" and j["mime"] == "audio/mpeg" and j["chars"] == len("হ্যালো  জগৎ")
    assert base64.b64decode(j["audio_b64"]) == state["audio"]
    sent = json.loads(seen[0].content)
    assert sent == {
        "model": "tts-1",
        "input": "হ্যালো  জগৎ",
        "voice": "nova",
        "response_format": "mp3",
    }
    assert seen[0].url.path.endswith("/audio/speech")


@pytest.mark.parametrize(
    ("kw", "reason"),
    [
        ({"voice": "../x"}, "bad_voice"),
        ({"text": " \x00 "}, "empty_text"),
        ({"provider": {"adapter": "ollama"}}, "adapter_no_speech"),
    ],
)
def test_speak_refuses_bad_input(client, provider, kw, reason):
    seen, _ = provider
    assert post(client, TTS, tts(**kw)).json()["reason"] == reason and seen == []


def test_speak_rejects_overlong_text_and_empty_audio(client, provider):
    assert post(client, TTS, tts(text="ক" * 4001)).status_code == 422
    _, state = provider
    state["audio"] = b""
    assert post(client, TTS, tts()).status_code == 502
