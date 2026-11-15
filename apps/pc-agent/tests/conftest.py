import json
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))


class FakeServer:
    """Speaks the same /api/pc/v1 protocol as the Laravel app (pair, next, report, metrics)."""

    def __init__(self):
        self.queue, self.reports, self.metrics, self.requests = [], [], [], []
        self.token = "a" * 64
        self.code = "PAIRCODE1"
        self.revoked = False
        self.metrics_status = 200
        self.next_status = 200
        self.desktop = True                               # False = an older server without /api/pc/v1/desktop/*
        self.memory = [{"id": "M1", "text": "আমি চা পছন্দ করি"}]
        self.convs = {"C1": [{"role": "user", "text": "hi"}, {"role": "assistant", "text": "Hello! `code` and **bold**\n```py\nprint(1)\n```"}]}
        self.chat_log = []                                 # every POST desktop/chat body, to check source/persona
        self.voice_calls = []
        self.rotate_lose_reply = False                      # simulate: the server switched tokens but the answer never arrived
        self.stream_mode = "ok"                            # ok | slow (keeps sending until the client hangs up) | error_before | error_mid | old (404)
        self.stream_deltas = None                          # None = echo the message back in pieces
        self.stream_calls = []                             # one entry per POST chat/stream
        self.stream_aborted = threading.Event()            # set when the client closed the connection while we were sending
        self.stream_sent = 0
        self.audit_rows = []                               # the server's stored copy of the agent's audit chain (same rules as SQL pc_audit_ingest)
        self.audit_mode = "ok"                             # ok | old (404) | down (500)
        self.audit_sync_calls = []
        self.runs = [{"id": "R1", "name": "Morning brief", "status": "done", "started": 1700000000}]
        outer = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def _send(self, status, body):
                raw = json.dumps(body).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                self.end_headers()
                self.wfile.write(raw)

            def _body(self):
                n = int(self.headers.get("Content-Length") or 0)
                return json.loads(self.rfile.read(n) or b"{}")

            def _authed(self):
                if outer.revoked or self.headers.get("Authorization") != "Bearer " + outer.token:
                    self._send(401, {"error": "unauthenticated"})
                    return False
                return True

            def _desktop(self, method, path, body):
                """The contract the Laravel app implements under /api/pc/v1/desktop/ (see docs/DESKTOP_API.md)."""
                if not outer.desktop:
                    return 404, {}
                p, _, q = path.partition("?")
                seg = p.split("/")
                if method == "GET":
                    if p == "bootstrap":
                        return 200, {"user": {"name": "Rafi"}, "models": [{"id": "M_A", "name": "Fast model"}, {"id": "M_B", "name": "Smart model"}], "credits": 120, "pending_approvals": 2}
                    if p == "tools":
                        return 200, {"tools": [{"key": "tasks.add", "label": "Add a task", "risk": "write_own"}, {"key": "chat.post", "label": "Slack message", "risk": "external"}]}
                    if p == "approvals":
                        return 200, {"items": [{"id": "A1", "title": "Write report.docx", "kind": "write_file", "risk": "medium"}]}
                    if p == "chat/conversations":
                        return 200, {"items": [{"id": k, "title": v[0]["text"]} for k, v in outer.convs.items()]}
                    if seg[:2] == ["chat", "conversations"] and len(seg) == 3:
                        return (200, {"messages": outer.convs[seg[2]]}) if seg[2] in outer.convs else (404, {})
                    if p == "agents":
                        return 200, {"items": [{"id": "AG1", "name": "Researcher", "description": "Finds facts", "status": "active"}, {"id": "AG2", "name": "Paused one", "description": "", "status": "paused"}]}
                    if p == "workflows":
                        return 200, {"items": [{"id": "W1", "name": "Morning brief", "status": "active"}, {"id": "W2", "name": "Old", "status": "paused"}]}
                    if p == "workflows/runs":
                        return 200, {"items": outer.runs}
                    if p == "memory":
                        return 200, {"items": outer.memory}
                    return 404, {}
                if p == "voice/listen":
                    outer.voice_calls.append(("listen", len(str(body.get("audio_b64", "")))))
                    return (200, {"text": "cloud heard hello"}) if len(str(body.get("audio_b64", ""))) > 100 else (422, {"error": "too_small"})
                if p == "voice/speak":
                    import base64
                    outer.voice_calls.append(("speak", len(str(body.get("text", "")))))
                    wav = b"RIFF" + (36 + 800).to_bytes(4, "little") + b"WAVEfmt " + (16).to_bytes(4, "little") + (1).to_bytes(2, "little") + (1).to_bytes(2, "little") + (8000).to_bytes(4, "little") + (8000).to_bytes(4, "little") + (1).to_bytes(2, "little") + (8).to_bytes(2, "little") + b"data" + (800).to_bytes(4, "little") + b"\x80" * 800
                    return 200, {"audio_b64": base64.b64encode(wav).decode(), "mime": "audio/wav"}
                if p == "chat":
                    outer.chat_log.append(body)
                    cid = body.get("conversation_id") or "C2"
                    outer.convs.setdefault(cid, [{"role": "user", "text": body.get("message", "")}])
                    return 200, {"conversation_id": cid, "reply": "Echo: " + str(body.get("message", ""))}
                if seg[0] == "agents" and len(seg) == 3 and seg[2] == "run":
                    return 200, {"status": "done", "output": "Agent says: " + str(body.get("input", ""))}
                if seg[0] == "workflows" and len(seg) == 3 and seg[2] == "run":
                    outer.runs.insert(0, {"id": "R2", "name": "Morning brief", "status": "running", "started": 1700000100})
                    return 200, {"run_id": "R2"}
                if p.startswith("workflows/runs/") and p.endswith("/cancel"):
                    for r in outer.runs:
                        if r["id"] == seg[2]:
                            r["status"] = "cancelled"
                    return 200, {"ok": True}
                if p == "memory":
                    if not str(body.get("text", "")).strip():
                        return 422, {"error": "empty"}
                    outer.memory.append({"id": "M%d" % (len(outer.memory) + 1), "text": body["text"]})
                    return 200, {"id": outer.memory[-1]["id"]}
                if seg[0] == "memory" and len(seg) == 3 and seg[2] == "delete":
                    outer.memory = [m for m in outer.memory if m["id"] != seg[1]]
                    return 200, {"ok": True}
                if p == "plan":
                    return (200, {"status": "ok"}) if len(str(body.get("goal", ""))) >= 3 else (422, {"error": "bad_goal"})
                return 404, {}

            def _stream(self, body):
                outer.stream_calls.append(body)
                outer.chat_log.append(body)
                mode = outer.stream_mode
                msg = str(body.get("message", ""))
                if msg.startswith("SLOW"):                       # the e2e tests pick the behaviour per message
                    mode = "slow"
                elif msg.startswith("OLDSTREAM"):
                    mode = "old"
                if mode == "old":
                    return self._send(404, {})
                if mode == "error_before":
                    return self._send(422, {"error": "no_credits"})
                self.send_response(200)
                self.send_header("Content-Type", "text/event-stream")
                self.end_headers()
                cid = body.get("conversation_id") or "C3"

                def ev(kind, data):
                    self.wfile.write(f"event: {kind}\ndata: {json.dumps(data)}\n\n".encode())
                    self.wfile.flush()
                try:
                    self.wfile.write(b": comment lines must not reach the page\nid: 7\nretry: 5\n\n")
                    ev("meta", {"conversation_id": cid, "message_id": "m1"})
                    if mode == "slow":
                        while True:
                            ev("delta", {"text": "tick "})
                            outer.stream_sent += 1
                            time.sleep(0.05)
                    for d in (outer.stream_deltas or ["Echo", ": ", msg]):
                        ev("delta", {"text": d})
                        outer.stream_sent += 1
                        time.sleep(0.02)
                    if mode == "error_mid":
                        return ev("error", {"code": "provider", "message": "The model stopped", "partial": True})
                    ev("done", {"credits": 2, "stopped": False})
                except (BrokenPipeError, ConnectionResetError, ConnectionAbortedError):
                    outer.stream_aborted.set()

            def _audit_head(self):
                rows = outer.audit_rows
                return {"status": "ok", "seq": len(rows), "h": rows[-1]["h"] if rows else "0" * 64}

            def _audit_sync(self, body):
                from jarvis_agent.taskengine import canonical
                import hashlib
                outer.audit_sync_calls.append(body)
                head = self._audit_head()
                start, rows = body.get("start"), body.get("rows")
                if not isinstance(start, int) or not isinstance(rows, list) or not 1 <= len(rows) <= 200:
                    return self._send(422, {"status": "bad_input"})
                if start != head["seq"]:
                    return self._send(409, dict(head, status="seq_mismatch"))
                want = head["h"]
                for i, r in enumerate(rows):
                    if set(r) != {"t", "task", "step", "event", "detail", "prev", "h"}:
                        return self._send(422, {"status": "bad_input", "at": start + i})
                    body_r = {k: v for k, v in r.items() if k != "h"}
                    if r["prev"] != want or hashlib.sha256(canonical(body_r).encode()).hexdigest() != r["h"]:
                        return self._send(409, dict(head, status="chain_broken", at=start + i))
                    want = r["h"]
                outer.audit_rows.extend(rows)
                return self._send(200, {"status": "ok", "seq": len(outer.audit_rows), "h": want})

            def do_POST(self):
                outer.requests.append((self.command, self.path))
                body = self._body()
                if self.path == "/api/pc/v1/pair":
                    if body.get("code") == outer.code:
                        outer.code = None
                        return self._send(201, {"device_id": "DEV00000000000000000000001".lower(), "token": outer.token})
                    return self._send(422, {"error": "invalid_code"})
                if not self._authed():
                    return
                if self.path == "/api/pc/v1/desktop/chat/stream" and outer.desktop:
                    return self._stream(body)
                if self.path.startswith("/api/pc/v1/desktop/"):
                    st, js = self._desktop("POST", self.path[len("/api/pc/v1/desktop/"):], body)
                    return self._send(st, js)
                if self.path == "/api/pc/v1/rotate":
                    if not self._authed():
                        return
                    nt = str(body.get("new_token", ""))
                    if len(nt) != 64 or any(c not in "0123456789abcdef" for c in nt) or nt == outer.token:
                        return self._send(409, {"error": "rotate_refused"})
                    outer.token = nt
                    return self._send(500, {"error": "boom"}) if outer.rotate_lose_reply else self._send(200, {"ok": True})
                if self.path == "/api/pc/v1/audit/sync":
                    return self._audit_sync(body)
                if self.path == "/api/pc/v1/metrics":
                    outer.metrics.append(body)
                    return self._send(outer.metrics_status, {"status": "ok"})
                if self.path.startswith("/api/pc/v1/actions/") and self.path.endswith("/report"):
                    outer.reports.append((self.path.split("/")[-2], body))
                    return self._send(200, {"status": "ok"})
                self._send(404, {})

            def do_GET(self):
                outer.requests.append((self.command, self.path))
                if not self._authed():
                    return
                if self.path.startswith("/api/pc/v1/desktop/"):
                    st, js = self._desktop("GET", self.path[len("/api/pc/v1/desktop/"):], {})
                    return self._send(st, js)
                if self.path == "/api/pc/v1/audit/head":
                    if outer.audit_mode == "old":
                        return self._send(404, {})
                    if outer.audit_mode == "down":
                        return self._send(500, {"error": "boom"})
                    return self._send(200, self._audit_head())
                if self.path == "/api/pc/v1/actions/next":
                    if outer.next_status != 200:
                        return self._send(outer.next_status, {"error": "x"})
                    return self._send(200, {"action": outer.queue.pop(0) if outer.queue else None})
                self._send(404, {})

        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), H)
        self.url = f"http://127.0.0.1:{self.httpd.server_address[1]}"
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def close(self):
        self.httpd.shutdown()
        self.httpd.server_close()


@pytest.fixture
def server():
    s = FakeServer()
    yield s
    s.close()


@pytest.fixture(autouse=True)
def agent_home(tmp_path, monkeypatch):
    monkeypatch.setenv("JARVIS_AGENT_HOME", str(tmp_path / "home"))
    return tmp_path / "home"


@pytest.fixture
def root(tmp_path):
    r = tmp_path / "allowed"
    r.mkdir()
    return r

# ---- REAL-DEVICE suite (tests/real): results are recorded next to the agent's settings so scripts/test-report.py can show real evidence separately from simulated evidence.
# A skipped test is never recorded.  (This lives here, not in tests/real/conftest.py, because two files named conftest.py shadow each other's imports.)
import json as _json
import os as _os
import platform as _platform
import socket as _socket
import time as _time
from pathlib import Path as _Path


def results_dir() -> _Path:
    from jarvis_agent.config import home_dir
    return _Path(_os.environ.get("JARVIS_REAL_RESULTS_DIR") or (home_dir() / "real-results"))


@pytest.hookimpl(hookwrapper=True)
def pytest_runtest_makereport(item, call):
    out = yield
    rep = out.get_result()
    if "real_device" not in item.keywords:
        return
    if rep.when != "call" and not (rep.when == "setup" and rep.skipped):
        return
    if rep.skipped:
        return                                                  # never record a skip as evidence
    area = next((m.args[0] for m in item.iter_markers("area")), None)
    row = {"test": item.nodeid, "area": area, "result": "pass" if rep.passed else "fail", "platform": _platform.platform(), "host": _socket.gethostname(),
           "when": _time.strftime("%Y-%m-%dT%H:%M:%S"), "detail": (str(rep.longrepr)[-400:] if rep.failed else "")}
    d = results_dir()
    d.mkdir(parents=True, exist_ok=True)
    f = d / f"{_socket.gethostname()}-{_time.strftime('%Y%m%d')}.json"
    rows = _json.loads(f.read_text(encoding="utf-8")) if f.exists() else []
    rows.append(row)
    f.write_text(_json.dumps(rows, indent=1, ensure_ascii=False), encoding="utf-8")
