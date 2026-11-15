"""Phase 4: the local window relays the server's streamed chat (SSE), hangs up on Stop, and never leaks the token.
The 'server' is a fake that speaks the documented SSE contract (docs/DESKTOP_API.md); the real Laravel endpoint has never run here."""
import http.client
import json
import time

import pytest

from jarvis_agent.config import Config

from test_gui import TOKEN, Win, wait


@pytest.fixture
def sw(server):
    w = Win()
    w.state.cfg = Config(server=server.url, token=server.token, device_id="d1")
    yield w
    w.close()


def events(raw: bytes):
    out = []
    for block in raw.decode().split("\n\n"):
        kind, data = None, None
        for ln in block.splitlines():
            if ln.startswith("event:"):
                kind = ln[6:].strip()
            elif ln.startswith("data:"):
                data = json.loads(ln[5:])
        if kind:
            out.append((kind, data))
    return out


def test_the_answer_is_relayed_piece_by_piece_and_noise_is_dropped(sw, server):
    server.stream_deltas = ["Hel", "lo ", "world"]
    s, h, raw = sw.call("POST", "/api/cloud/chat/stream", {"message": "hi", "source": "voice", "persona": "friendly"})
    assert s == 200 and h["Content-Type"].startswith("text/event-stream") and h["Cache-Control"] == "no-store"
    ev = events(raw)
    assert [k for k, _ in ev] == ["meta", "delta", "delta", "delta", "done"]
    assert "".join(d["text"] for k, d in ev if k == "delta") == "Hello world" and ev[0][1]["conversation_id"] == "C3" and ev[-1][1]["credits"] == 2
    assert b"comment" not in raw and b"retry" not in raw and b"id:" not in raw and server.token.encode() not in raw
    assert server.stream_calls[-1]["source"] == "voice" and server.stream_calls[-1]["persona"] == "friendly"
    assert len(server.stream_calls) == 1                                                # one request, nothing repeated


def test_errors_before_the_first_byte_are_real_http_errors(sw, server):
    server.stream_mode = "error_before"
    s, j = sw.j("POST", "/api/cloud/chat/stream", {"message": "hi"})
    assert s == 422 and j == {"error": "no_credits"}
    server.stream_mode = "old"
    s, j = sw.j("POST", "/api/cloud/chat/stream", {"message": "hi"})
    assert s == 501 and j == {"error": "server_too_old"}
    server.revoked = True
    assert sw.j("POST", "/api/cloud/chat/stream", {"message": "hi"})[0] == 401


def test_an_error_in_the_middle_arrives_as_an_event_after_the_text_so_far(sw, server):
    server.stream_mode = "error_mid"
    s, _, raw = sw.call("POST", "/api/cloud/chat/stream", {"message": "hi"})
    ev = events(raw)
    assert s == 200 and ev[-1][0] == "error" and ev[-1][1]["partial"] is True and [k for k, _ in ev].count("delta") == 3


def test_hanging_up_stops_the_server_side_stream(sw, server):
    """Stop in the window = the browser closes its request; the agent must close its request to the server so the server stops writing (and settles credits for what was made)."""
    server.stream_mode = "slow"
    import socket
    body = json.dumps({"message": "go"}).encode()
    sock = socket.create_connection(("127.0.0.1", sw.port), timeout=5)
    sock.sendall((f"POST /api/cloud/chat/stream HTTP/1.1\r\nHost: 127.0.0.1:{sw.port}\r\nX-Agent-Token: {TOKEN}\r\nContent-Type: application/json\r\nContent-Length: {len(body)}\r\n\r\n").encode() + body)
    got = b""
    while got.count(b"event: delta") < 3:
        chunk = sock.recv(4096)
        assert chunk, "stream ended early"
        got += chunk
    assert got.startswith(b"HTTP/1.0 200")
    sock.close()                                                                     # this is what Stop (AbortController) does in the browser
    assert wait(server.stream_aborted.is_set, 5), "the server was never told to stop"
    n = server.stream_sent
    time.sleep(0.4)
    assert server.stream_sent == n
    assert len(server.stream_calls) == 1


def test_guards_still_apply(sw, server):
    assert sw.call("POST", "/api/cloud/chat/stream", {"message": "x"}, token="wrong")[0] == 401
    assert sw.call("POST", "/api/cloud/chat/stream", {"message": "x"}, host="evil.example")[0] == 403
    assert sw.call("POST", "/api/cloud/chat/stream", {"message": "x"}, headers={"Origin": "http://evil.example"})[0] == 403
    assert sw.call("POST", "/api/cloud/chat/stream", raw=b"x" * 70000)[0] == 413
    assert server.stream_calls == []
    sw.state.cfg = Config()
    assert sw.j("POST", "/api/cloud/chat/stream", {"message": "x"})[0] == 409


def test_oversized_lines_are_dropped_not_forwarded(sw, server):
    server.stream_deltas = ["ok ", "x" * 70000, "end"]
    _, _, raw = sw.call("POST", "/api/cloud/chat/stream", {"message": "hi"})
    text = "".join(d["text"] for k, d in events(raw) if k == "delta")
    assert text == "ok end" and len(raw) < 2000
