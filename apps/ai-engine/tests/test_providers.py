"""Adapter tests against httpx.MockTransport (no network). The mock plays the provider."""

import json

import httpx
import pytest

from app import routes_providers
from app.providers.base import (
    ChatMessage,
    ChatRequest,
    ProviderError,
    ProviderSpec,
    redact,
    validate_base_url,
)
from app.providers.registry import build_adapter
from tests.conftest import make_headers

KEY = "sk-live-SUPERSECRETKEY1234567890"
MSGS = [ChatMessage(role="system", content="be brief"), ChatMessage(role="user", content="hi")]
REQ = ChatRequest(model="m-1", messages=MSGS, max_tokens=50, temperature=0.2)


def spec(adapter, **kw):
    return ProviderSpec(adapter=adapter, api_key=KEY, **kw)


def mock(handler):
    return httpx.MockTransport(handler)


def sse(*events: dict | str) -> bytes:
    out = ""
    for e in events:
        out += f"data: {e if isinstance(e, str) else json.dumps(e)}\n\n"
    return out.encode()


# ───────── OpenAI-compatible family ─────────


@pytest.mark.parametrize(
    "adapter,host",
    [("openai", "api.openai.com"), ("groq", "api.groq.com"), ("openrouter", "openrouter.ai")],
)
async def test_openai_family_complete(adapter, host):
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["auth"] = request.headers["authorization"]
        seen["body"] = json.loads(request.content)
        return httpx.Response(
            200,
            json={
                "model": "m-1",
                "choices": [{"message": {"content": "hello"}, "finish_reason": "stop"}],
                "usage": {"prompt_tokens": 7, "completion_tokens": 3},
            },
        )

    r = await build_adapter(spec(adapter), mock(handler)).complete(REQ)
    assert (r.text, r.input_tokens, r.output_tokens, r.finish_reason) == ("hello", 7, 3, "stop")
    assert host in seen["url"] and seen["url"].endswith("/chat/completions")
    assert seen["auth"] == f"Bearer {KEY}"
    assert seen["body"]["messages"][0] == {"role": "system", "content": "be brief"}
    assert seen["body"]["max_tokens"] == 50


async def test_openai_stream_yields_deltas_and_usage():
    def handler(_):
        return httpx.Response(
            200,
            content=sse(
                {"choices": [{"delta": {"content": "Hel"}}]},
                {"choices": [{"delta": {"content": "lo"}, "finish_reason": "stop"}]},
                {"choices": [], "usage": {"prompt_tokens": 5, "completion_tokens": 2}},
                "[DONE]",
            ),
        )

    events = [e async for e in build_adapter(spec("openai"), mock(handler)).stream(REQ)]
    assert "".join(e.text for e in events) == "Hello"
    assert events[-1].input_tokens == 5 and events[-1].output_tokens == 2
    assert any(e.finish_reason == "stop" for e in events)


async def test_ollama_needs_allow_private_and_has_no_auth_header_without_key():
    with pytest.raises(ProviderError) as ei:
        build_adapter(ProviderSpec(adapter="ollama"))
    assert ei.value.code == "invalid_base_url"

    seen = {}

    def handler(request):
        seen["h"] = dict(request.headers)
        return httpx.Response(200, json={"choices": [{"message": {"content": "x"}}]})

    a = build_adapter(ProviderSpec(adapter="ollama", allow_private=True), mock(handler))
    await a.complete(REQ)
    assert "authorization" not in seen["h"]


async def test_generic_compatible_requires_base_url():
    with pytest.raises(ProviderError):
        build_adapter(spec("openai_compatible"))
    a = build_adapter(spec("openai_compatible", base_url="https://llm.example.com/v1"))
    assert a.base_url == "https://llm.example.com/v1"


# ───────── Anthropic ─────────


async def test_anthropic_complete_maps_system_and_usage():
    seen = {}

    def handler(request):
        seen["h"] = request.headers
        seen["body"] = json.loads(request.content)
        seen["path"] = request.url.path
        return httpx.Response(
            200,
            json={
                "model": "m-1",
                "content": [{"type": "text", "text": "hi "}, {"type": "text", "text": "there"}],
                "stop_reason": "end_turn",
                "usage": {"input_tokens": 9, "output_tokens": 4},
            },
        )

    r = await build_adapter(spec("anthropic"), mock(handler)).complete(REQ)
    assert (r.text, r.input_tokens, r.output_tokens, r.finish_reason) == (
        "hi there",
        9,
        4,
        "end_turn",
    )
    assert seen["path"] == "/v1/messages"
    assert seen["h"]["x-api-key"] == KEY and "anthropic-version" in seen["h"]
    assert seen["body"]["system"] == "be brief"
    assert all(m["role"] != "system" for m in seen["body"]["messages"])


async def test_anthropic_stream():
    def handler(_):
        return httpx.Response(
            200,
            content=sse(
                {"type": "message_start", "message": {"usage": {"input_tokens": 11}}},
                {"type": "content_block_delta", "delta": {"text": "A"}},
                {"type": "content_block_delta", "delta": {"text": "B"}},
                {
                    "type": "message_delta",
                    "delta": {"stop_reason": "end_turn"},
                    "usage": {"output_tokens": 2},
                },
            ),
        )

    ev = [e async for e in build_adapter(spec("anthropic"), mock(handler)).stream(REQ)]
    assert "".join(e.text for e in ev) == "AB"
    assert sum(e.input_tokens or 0 for e in ev) == 11 and sum(e.output_tokens or 0 for e in ev) == 2


# ───────── Gemini ─────────


async def test_gemini_key_in_header_not_url_and_roles_mapped():
    seen = {}

    def handler(request):
        seen["url"] = str(request.url)
        seen["h"] = request.headers
        seen["body"] = json.loads(request.content)
        return httpx.Response(
            200,
            json={
                "candidates": [{"content": {"parts": [{"text": "yo"}]}, "finishReason": "STOP"}],
                "usageMetadata": {"promptTokenCount": 6, "candidatesTokenCount": 1},
            },
        )

    req = ChatRequest(
        model="gemini-x",
        messages=[
            *MSGS,
            ChatMessage(role="assistant", content="ok"),
            ChatMessage(role="user", content="more"),
        ],
    )
    r = await build_adapter(spec("gemini"), mock(handler)).complete(req)
    assert (r.text, r.input_tokens, r.output_tokens, r.finish_reason) == ("yo", 6, 1, "STOP")
    assert KEY not in seen["url"] and seen["h"]["x-goog-api-key"] == KEY
    assert ":generateContent" in seen["url"]
    assert seen["body"]["systemInstruction"]["parts"][0]["text"] == "be brief"
    assert [c["role"] for c in seen["body"]["contents"]] == ["user", "model", "user"]


async def test_gemini_rejects_path_injection_in_model():
    a = build_adapter(spec("gemini"), mock(lambda r: httpx.Response(200, json={})))
    for bad in ("../x", "a/b", "m?x=1", "m#f", ""):
        with pytest.raises(ProviderError):
            await a.complete(ChatRequest(model=bad, messages=MSGS))


# ───────── error mapping, redaction, timeouts ─────────


@pytest.mark.parametrize(
    "status,code",
    [
        (401, "auth_failed"),
        (403, "auth_failed"),
        (429, "rate_limited"),
        (400, "bad_request"),
        (404, "bad_request"),
        (500, "upstream_error"),
        (503, "upstream_error"),
    ],
)
async def test_status_mapping(status, code):
    a = build_adapter(
        spec("openai"), mock(lambda r: httpx.Response(status, json={"error": {"message": "x"}}))
    )
    with pytest.raises(ProviderError) as ei:
        await a.complete(REQ)
    assert ei.value.code == code and ei.value.status == status


async def test_rate_limit_carries_retry_after():
    a = build_adapter(
        spec("openai"), mock(lambda r: httpx.Response(429, headers={"retry-after": "7"}, json={}))
    )
    with pytest.raises(ProviderError) as ei:
        await a.complete(REQ)
    assert ei.value.retry_after == 7


async def test_upstream_error_text_echoing_the_key_is_redacted():
    def handler(_):
        return httpx.Response(
            401, json={"error": {"message": f"Incorrect API key provided: {KEY}."}}
        )

    with pytest.raises(ProviderError) as ei:
        await build_adapter(spec("openai"), mock(handler)).complete(REQ)
    assert KEY not in ei.value.message and "SUPERSECRET" not in ei.value.message


async def test_timeout_and_connect_errors_are_sanitised():
    def boom(request):
        raise httpx.ConnectError(f"cannot connect with {KEY}", request=request)

    def slow(request):
        raise httpx.ReadTimeout("t", request=request)

    with pytest.raises(ProviderError) as e1:
        await build_adapter(spec("openai"), mock(boom)).complete(REQ)
    assert e1.value.code == "unreachable" and KEY not in e1.value.message
    with pytest.raises(ProviderError) as e2:
        await build_adapter(spec("openai"), mock(slow)).complete(REQ)
    assert e2.value.code == "timeout"


async def test_malformed_success_body_is_bad_response():
    a = build_adapter(spec("openai"), mock(lambda r: httpx.Response(200, json={"nope": 1})))
    with pytest.raises(ProviderError) as ei:
        await a.complete(REQ)
    assert ei.value.code == "bad_response"


async def test_redirects_are_not_followed():
    calls = []

    def handler(request):
        calls.append(str(request.url))
        if "evil" in str(request.url):
            return httpx.Response(200, json={})
        return httpx.Response(302, headers={"location": "https://evil.example/steal"})

    with pytest.raises(ProviderError):
        await build_adapter(spec("openai"), mock(handler)).complete(REQ)
    assert len(calls) == 1


def test_redact_helper():
    assert "sk-abcdefgh12345" not in redact("key sk-abcdefgh12345 bad")
    assert "tok123" not in redact("Authorization: Bearer tok123")
    assert "mysecretvalue" not in redact("x mysecretvalue y", ["mysecretvalue"])


# ───────── SSRF guard ─────────


@pytest.mark.parametrize(
    "url",
    [
        "http://127.0.0.1:8000",
        "http://localhost/v1",
        "http://10.0.0.5",
        "http://192.168.1.2",
        "http://[::1]/",
        "http://169.254.169.254/latest",
        "http://metadata.google.internal/",
        "http://svc.internal/",
        "ftp://example.com",
        "http://user:pw@example.com",
        "http://example.com",
        "https://",
        "file:///etc/passwd",
    ],
)
def test_ssrf_blocked(url):
    with pytest.raises(ProviderError):
        validate_base_url(url, allow_private=False)


def test_metadata_blocked_even_when_private_allowed():
    for u in ("http://169.254.169.254/", "http://metadata.google.internal/"):
        with pytest.raises(ProviderError):
            validate_base_url(u, allow_private=True)
    assert (
        validate_base_url("http://localhost:11434/v1/", allow_private=True)
        == "http://localhost:11434/v1"
    )
    assert (
        validate_base_url("https://api.example.com/v1/", allow_private=False)
        == "https://api.example.com/v1"
    )


# ───────── HTTP endpoints: auth, no leakage ─────────


@pytest.fixture
def engine(client):
    yield client
    routes_providers.set_transport(None)


def post(client, path, payload):
    body = json.dumps(payload).encode()
    h = make_headers(method="POST", path=path, body=body)
    h["Content-Type"] = "application/json"
    return client.post(path, content=body, headers=h)


def test_endpoints_require_signature(engine):
    for p in ("/internal/v1/providers/test", "/internal/v1/ai/complete", "/internal/v1/ai/stream"):
        assert engine.post(p, json={}).status_code == 401


def test_connection_test_ok_and_failure_never_leak_key(engine):
    routes_providers.set_transport(mock(lambda r: httpx.Response(200, json={"data": []})))
    r = post(
        engine, "/internal/v1/providers/test", {"provider": {"adapter": "openai", "api_key": KEY}}
    )
    assert (
        r.status_code == 200 and r.json()["ok"] is True and isinstance(r.json()["latency_ms"], int)
    )
    assert KEY not in r.text

    routes_providers.set_transport(
        mock(lambda r: httpx.Response(401, json={"error": {"message": f"bad key {KEY}"}}))
    )
    r = post(
        engine, "/internal/v1/providers/test", {"provider": {"adapter": "openai", "api_key": KEY}}
    )
    assert r.json()["ok"] is False and r.json()["error_code"] == "auth_failed"
    assert KEY not in r.text


def test_validation_error_does_not_echo_the_credential(engine):
    # model missing/invalid -> FastAPI 422; the default body would include the submitted api_key.
    r = post(
        engine,
        "/internal/v1/ai/complete",
        {"provider": {"adapter": "openai", "api_key": KEY, "timeout_s": 9999}, "request": {}},
    )
    assert r.status_code == 422 and KEY not in r.text
    assert r.json()["detail"] == "invalid_request"


def test_complete_endpoint_response_has_no_credential(engine):
    routes_providers.set_transport(
        mock(
            lambda r: httpx.Response(
                200,
                json={
                    "choices": [{"message": {"content": "ok"}, "finish_reason": "stop"}],
                    "usage": {"prompt_tokens": 1, "completion_tokens": 1},
                },
            )
        )
    )
    r = post(
        engine,
        "/internal/v1/ai/complete",
        {
            "provider": {"adapter": "openai", "api_key": KEY},
            "request": {"model": "m-1", "messages": [{"role": "user", "content": "hi"}]},
        },
    )
    assert r.status_code == 200 and r.json()["text"] == "ok" and KEY not in r.text


def test_complete_endpoint_maps_errors_to_http_status(engine):
    routes_providers.set_transport(
        mock(lambda r: httpx.Response(429, headers={"retry-after": "3"}, json={}))
    )
    r = post(
        engine,
        "/internal/v1/ai/complete",
        {
            "provider": {"adapter": "openai", "api_key": KEY},
            "request": {"model": "m-1", "messages": [{"role": "user", "content": "hi"}]},
        },
    )
    assert r.status_code == 429 and r.headers["retry-after"] == "3"
    assert r.json()["error"]["code"] == "rate_limited"


def test_ssrf_url_rejected_via_endpoint(engine):
    r = post(
        engine,
        "/internal/v1/providers/test",
        {
            "provider": {
                "adapter": "openai_compatible",
                "api_key": KEY,
                "base_url": "http://169.254.169.254",
            }
        },
    )
    assert r.json()["ok"] is False and r.json()["error_code"] == "invalid_base_url"


def test_stream_endpoint_sse_and_upfront_error(engine):
    routes_providers.set_transport(
        mock(
            lambda r: httpx.Response(
                200, content=sse({"choices": [{"delta": {"content": "Hi"}}]}, "[DONE]")
            )
        )
    )
    payload = {
        "provider": {"adapter": "openai", "api_key": KEY},
        "request": {"model": "m-1", "messages": [{"role": "user", "content": "hi"}]},
    }
    r = post(engine, "/internal/v1/ai/stream", payload)
    assert r.status_code == 200 and "event: delta" in r.text and "event: done" in r.text
    assert KEY not in r.text

    routes_providers.set_transport(
        mock(lambda r: httpx.Response(401, json={"error": {"message": KEY}}))
    )
    r = post(engine, "/internal/v1/ai/stream", payload)
    assert r.status_code == 502 and KEY not in r.text


def test_logs_do_not_contain_credential(engine, caplog):
    caplog.set_level("DEBUG")
    routes_providers.set_transport(
        mock(lambda r: httpx.Response(401, json={"error": {"message": KEY}}))
    )
    post(engine, "/internal/v1/providers/test", {"provider": {"adapter": "openai", "api_key": KEY}})
    assert KEY not in caplog.text


async def test_non_json_success_body_is_bad_response_not_crash():
    a = build_adapter(
        spec("openai"), mock(lambda r: httpx.Response(200, content=b"<html>oops</html>"))
    )
    with pytest.raises(ProviderError) as ei:
        await a.complete(REQ)
    assert ei.value.code == "bad_response"
