"""A local window for the agent: home, rules, history, pairing, stop.

It is an ordinary web page served ONLY on 127.0.0.1 (never a network address).  Three guards keep other web pages from driving it:
a random per-run token (sent as a header, never in a cookie), a Host check (against DNS rebinding) and a same-origin check on writes.
It changes only what the command line may change (same policy module); it cannot approve anything - approval stays on the server/phone.
"""
from __future__ import annotations

import hmac
import json
import platform
import re
import secrets
import sys
import threading
import time
import urllib.parse
import webbrowser
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from pathlib import Path

from . import __version__, auditsync, config as cfgmod, crashreport, history, localview, monitor, pause as pausemod, policy, prefs as prefmod, progress, rotation, stop as stopmod, taskengine
from . import guard
from .client import AuthError, Client, ServerError, normalise
from .config import ALL_KINDS, Config
from .runner import run

KIND_BN = {"read_file": "Read a file", "list_dir": "List a folder", "write_file": "Write a file", "delete_file": "Delete a file",
           "open_app": "Open an app", "browser_navigate": "Open a site in the browser", "run_command": "Run a command",
           "copy_file": "Copy a file", "move_file": "Move/rename a file", "make_dir": "Create a folder", "trash_file": "Move a file to trash (restorable)",
           "create_xlsx": "Create an Excel file", "create_docx": "Create a Word file",
           "window_info": "Look at the front window", "wait_window": "Wait for a window", "screen_read": "Read the screen", "screen_find": "Find on screen", "ui_click": "Click what is on screen", "screen_describe": "Describe the screen", "browser_run": "Automate a web page", "make_report": "Make an Excel report from a CSV", "screenshot": "Take a screenshot", "input_control": "Mouse and keyboard control", "browser_extract": "Read a web page"}
RISK = {"read_file": "low", "list_dir": "low", "write_file": "medium", "open_app": "medium", "browser_navigate": "medium", "delete_file": "high", "run_command": "high",
        "copy_file": "medium", "move_file": "high", "make_dir": "medium", "trash_file": "high", "create_xlsx": "medium", "create_docx": "medium",
        "screenshot": "high", "input_control": "high", "browser_extract": "medium", "window_info": "medium", "wait_window": "low",
        "screen_read": "high", "screen_find": "high", "ui_click": "high", "screen_describe": "high", "browser_run": "high", "make_report": "medium"}
MAX_BODY = 8192
MAX_CLOUD_BODY = 65536
MAX_SSE_LINE = 65536
MAX_SSE_TOTAL = 2 * 1024 * 1024
MAX_SSE_SECONDS = 180
MAX_VOICE_BODY = 2_500_000                               # ~30 s of recorded speech, base64
CLOUD_QUERY_KEYS = {"q", "limit"}
UI_DIR = Path(__file__).parent / "ui"
UI_FILES = {"app.css": "text/css; charset=utf-8", "app.js": "text/javascript; charset=utf-8", "character.js": "text/javascript; charset=utf-8",
            "voice.js": "text/javascript; charset=utf-8", "voice-session.js": "text/javascript; charset=utf-8", "logo.svg": "image/svg+xml"}
CLOUD_PATH = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_/\-]{0,79}$")
CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'"


class State:
    def __init__(self, allow_http: bool = False, client_factory=Client, runner=run):
        self.cfg: Config = cfgmod.load()
        self.lock = threading.Lock()
        self.allow_http = allow_http
        self.client_factory = client_factory
        self.runner = runner
        self.stop = threading.Event()
        self.thread: threading.Thread | None = None
        self.last_error = ""

    def running(self) -> bool:
        return self.thread is not None and self.thread.is_alive()

    def start(self) -> str | None:
        with self.lock:
            if not self.cfg.paired:
                return "Pair this computer first"
            if self.running():
                return None
            self.stop.clear()
            self.last_error = ""
            rotation.adopt_pending(self.cfg, self.client_factory, self.allow_http)      # finish a rotation interrupted earlier, if any
            client = self.client_factory(self.cfg.server, self.cfg.token, self.allow_http)

            def loop():
                code = self.runner(self.cfg, client, interval=3.0, log=lambda m: None, on_job=history.record, should_stop=self.stop.is_set)
                if code == 2:
                    self.last_error = "The server no longer accepts this device — pair again"

            self.thread = threading.Thread(target=loop, daemon=True)
            self.thread.start()
        return None

    def halt(self) -> None:
        self.stop.set()

    def _metrics(self) -> dict | None:
        """CPU/memory/disk for the window.  Cached for 3 s and non-blocking: the window polls this often, and a blocking 0.2 s CPU measurement made every poll
        (and so the whole window) 200 ms slower (found by the Phase 14 performance test).  The heartbeat to the server still measures over 0.2 s."""
        now = time.monotonic()
        cached = getattr(self, "_metrics_cache", None)
        if cached and now - cached[0] < 3.0:
            return cached[1]
        m = monitor.sample(interval=None)
        self._metrics_cache = (now, m)
        return m

    def view(self) -> dict:
        c = self.cfg
        return {
            "version": __version__, "os": platform.system(), "paired": c.paired, "server": c.server, "device_id": c.device_id,
            "running": self.running(), "error": self.last_error, "mode": c.mode,
            "roots": c.roots, "apps": c.apps, "commands": c.commands, "hosts": c.hosts,
            "kinds": [{"id": k, "label": KIND_BN[k], "risk": RISK[k], "on": k in c.kinds} for k in ALL_KINDS],
            "unsupported": ["Power (shutdown/restart)", "System settings"],
            "metrics": self._metrics(), "history": history.recent(50),
            "prefs": prefmod.merged(c.prefs), "emergency": stopmod.is_set(),
        }


def make_handler(state: State, token: str, port_holder: list[int]):
    class H(BaseHTTPRequestHandler):
        server_version = "jarvis-agent"

        def log_message(self, *a):
            pass

        def _host_ok(self) -> bool:
            return self.headers.get("Host", "") in (f"127.0.0.1:{port_holder[0]}", f"localhost:{port_holder[0]}")

        def _json(self, status: int, body: dict) -> None:
            raw = json.dumps(body, ensure_ascii=False).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(raw)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(raw)

        def _authed(self) -> bool:
            return hmac.compare_digest(self.headers.get("X-Agent-Token", ""), token)

        def _cloud(self, method: str, sub: str, body: dict | None) -> None:
            """Forward to /api/pc/v1/desktop/<sub> with this device's token.  Only a closed path alphabet; the token never reaches the page."""
            if not CLOUD_PATH.match(sub) or ".." in sub or "//" in sub:
                return self._json(404, {"error": "not found"})
            qs = self.path.partition("?")[2]
            if qs:
                try:
                    q = urllib.parse.parse_qs(qs, keep_blank_values=True, max_num_fields=3, errors="strict")
                except ValueError:
                    return self._json(400, {"error": "bad query"})
                if len(qs) > 300 or set(q) - CLOUD_QUERY_KEYS or any(len(v) != 1 or len(v[0]) > 120 or any(ord(c) < 32 for c in v[0]) for v in q.values()):
                    return self._json(400, {"error": "bad query"})
                sub += "?" + urllib.parse.urlencode({k: v[0] for k, v in q.items()})
            if not state.cfg.paired:
                return self._json(409, {"error": "not_paired"})
            try:
                client = state.client_factory(state.cfg.server, state.cfg.token, state.allow_http)
                return self._json(200, client.desktop(method, sub, body))
            except AuthError:
                return self._json(401, {"error": "device_revoked"})
            except ServerError as e:
                if e.status == 404:
                    return self._json(501, {"error": "server_too_old"})
                if e.status == 0:
                    return self._json(502, {"error": "offline"})
                return self._json(e.status if 400 <= e.status < 600 else 502, {"error": e.code or "server_error"})
            except ValueError:
                return self._json(422, {"error": "bad_server"})

        def _cloud_stream(self, body: dict) -> None:
            """Relay the chat answer as it is written.  Only well-formed SSE lines pass; at most 2 MB and 3 minutes; if the window goes away (Stop, closed tab) the upstream
            connection is closed at once, which tells the server to stop and to charge only what was produced."""
            if not state.cfg.paired:
                return self._json(409, {"error": "not_paired"})
            try:
                up = state.client_factory(state.cfg.server, state.cfg.token, state.allow_http).desktop_stream("chat/stream", body)
            except AuthError:
                return self._json(401, {"error": "device_revoked"})
            except ServerError as e:
                if e.status in (404, 405):
                    return self._json(501, {"error": "server_too_old"})
                if e.status == 0:
                    return self._json(502, {"error": "offline"})
                return self._json(e.status if 400 <= e.status < 600 else 502, {"error": e.code or "server_error"})
            except (ValueError, AttributeError):
                return self._json(501, {"error": "server_too_old"})            # a client without streaming
            sent, t0 = 0, time.monotonic()
            try:
                self.send_response(200)
                self.send_header("Content-Type", "text/event-stream; charset=utf-8")
                self.send_header("Cache-Control", "no-store")
                self.send_header("X-Accel-Buffering", "no")
                self.send_header("Connection", "close")
                self.end_headers()
                self.close_connection = True
                block: list[bytes] = []
                bad = False
                while True:
                    line = up.readline(MAX_SSE_LINE + 1)
                    if not line:
                        break
                    if len(line) > MAX_SSE_LINE:                                   # too long: swallow the rest of that line and drop the whole event
                        bad = True
                        while line and not line.endswith(b"\n"):
                            line = up.readline(MAX_SSE_LINE)
                        continue
                    if line in (b"\n", b"\r\n"):                                    # end of one event
                        if block and not bad:
                            chunk = b"".join(block) + line
                            sent += len(chunk)
                            if sent > MAX_SSE_TOTAL or time.monotonic() - t0 > MAX_SSE_SECONDS:
                                break
                            self.wfile.write(chunk)
                            self.wfile.flush()
                        block, bad = [], False
                    elif line.startswith((b"event:", b"data:")):
                        block.append(line)
                    # comments, ids, retry hints, anything else: dropped
            except (BrokenPipeError, ConnectionResetError, ConnectionAbortedError, OSError):
                pass                                                              # the window left: fall through and hang up on the server too
            finally:
                try:
                    up.close()
                except Exception:
                    pass

        def do_GET(self):
            if not self._host_ok():
                return self._json(403, {"error": "host"})
            path = self.path.split("?", 1)[0]
            if path in ("/", "/index.html") or path.startswith("/ui/"):
                name = "index.html" if path in ("/", "/index.html") else path[4:]
                ctype = "text/html; charset=utf-8" if name == "index.html" else UI_FILES.get(name)
                f = UI_DIR / name
                if ctype is None or not f.is_file():
                    return self._json(404, {"error": "not found"})
                raw = f.read_bytes()
                self.send_response(200)
                self.send_header("Content-Type", ctype)
                self.send_header("Content-Length", str(len(raw)))
                self.send_header("Cache-Control", "no-store")
                self.send_header("Content-Security-Policy", CSP)
                self.send_header("X-Frame-Options", "DENY")
                self.send_header("X-Content-Type-Options", "nosniff")
                self.send_header("Referrer-Policy", "no-referrer")
                self.end_headers()
                self.wfile.write(raw)
                return
            if path == "/api/files":
                if not self._authed():
                    return self._json(401, {"error": "token"})
                q = urllib.parse.parse_qs(self.path.partition("?")[2])
                try:
                    return self._json(200, localview.list_folder(state.cfg, (q.get("path") or [""])[0] or None))
                except guard.Refused as e:
                    return self._json(422, {"error": str(e)})
            if path == "/api/live":
                if not self._authed():
                    return self._json(401, {"error": "token"})
                return self._json(200, dict(progress.snapshot(), paused=pausemod.is_set(), emergency=stopmod.is_set()))
            if path == "/api/tasks":
                if not self._authed():
                    return self._json(401, {"error": "token"})
                ok, n = taskengine.Audit().verify_chain()
                return self._json(200, {"tasks": localview.task_summaries(taskengine.Audit().rows()), "audit_ok": ok, "audit_rows": n, "audit_sync": dict(auditsync.LAST)})
            if path.startswith("/api/cloud/"):
                if not self._authed():
                    return self._json(401, {"error": "token"})
                return self._cloud("GET", path[len("/api/cloud/"):], None)
            if path == "/api/crashes":
                if not self._authed():
                    return self._json(401, {"error": "token"})
                q = urllib.parse.parse_qs(self.path.partition("?")[2])
                if q.get("name"):
                    r = crashreport.read(q["name"][0])
                    return self._json(200, r) if r is not None else self._json(404, {"error": "not found"})
                return self._json(200, {"reports": crashreport.list_reports()})
            if path == "/api/state":
                if not self._authed():
                    return self._json(401, {"error": "token"})
                return self._json(200, state.view())
            self._json(404, {"error": "not found"})

        def _reject(self, status: int, body: dict) -> None:
            """Answer an early refusal.  A small request body is read and thrown away first: on Windows, closing a socket that still holds
            unread data makes the client see a connection reset instead of our answer."""
            try:
                n = int(self.headers.get("Content-Length") or 0)
                if 0 < n <= 65536:
                    self.rfile.read(n)
            except (ValueError, OSError):
                pass
            self._json(status, body)

        def do_POST(self):
            if not self._host_ok():
                return self._reject(403, {"error": "host"})
            if not self._authed():
                return self._reject(401, {"error": "token"})
            origin = self.headers.get("Origin")
            if origin is not None and origin not in (f"http://127.0.0.1:{port_holder[0]}", f"http://localhost:{port_holder[0]}"):
                return self._reject(403, {"error": "origin"})
            n = int(self.headers.get("Content-Length") or 0)
            if n > (MAX_VOICE_BODY if self.path.startswith("/api/cloud/voice/") else MAX_CLOUD_BODY if self.path.startswith("/api/cloud/") else MAX_BODY):
                return self._json(413, {"error": "too large"})
            try:
                body = json.loads(self.rfile.read(n) or b"{}")
                if not isinstance(body, dict):
                    raise ValueError
            except ValueError:
                return self._json(400, {"error": "Bad request"})
            path = self.path.split("?", 1)[0]
            if path == "/api/policy":
                with state.lock:
                    try:
                        policy.apply(state.cfg, str(body.get("action", "")), body.get("value"))
                    except ValueError as e:
                        return self._json(422, {"error": str(e)})
                    cfgmod.save(state.cfg)
                return self._json(200, {"ok": True})
            if path == "/api/crashes/clear":
                return self._json(200, {"deleted": crashreport.clear()})
            if path == "/api/audit/sync":
                if not state.cfg.paired:
                    return self._json(409, {"error": "not_paired"})
                try:
                    return self._json(200, auditsync.sync(state.client_factory(state.cfg.server, state.cfg.token, state.allow_http)))
                except AuthError:
                    return self._json(401, {"error": "device_revoked"})
            if path == "/api/cloud/chat/stream":
                return self._cloud_stream(body)
            if path.startswith("/api/cloud/"):
                return self._cloud("POST", path[len("/api/cloud/"):], body)
            if path == "/api/prefs":
                try:
                    v = prefmod.clean(body.get("key"), body.get("value"))
                except ValueError as e:
                    return self._json(422, {"error": str(e)})
                with state.lock:
                    stored = {k: x for k, x in (state.cfg.prefs or {}).items() if k in prefmod.SCHEMA}
                    stored[str(body["key"])] = v                        # only what the person changed is stored; defaults stay defaults
                    state.cfg.prefs = stored
                    cfgmod.save(state.cfg)
                return self._json(200, {"ok": True, "prefs": prefmod.merged(state.cfg.prefs)})
            if path == "/api/unpair":
                with state.lock:
                    state.halt()
                    state.cfg.server, state.cfg.token, state.cfg.device_id = "", "", ""
                    cfgmod.save(state.cfg)
                return self._json(200, {"ok": True})
            if path == "/api/stop":
                state.halt()
                stopmod.request("desktop window")
                return self._json(200, {"ok": True, "emergency": True})
            if path == "/api/rotate":
                try:
                    with state.lock:
                        how = rotation.rotate(state.cfg, state.client_factory, state.allow_http)
                except rotation.RotationFailed as e:
                    return self._json(422, {"error": str(e)})
                return self._json(200, {"ok": True, "how": how})
            if path == "/api/pause":
                pausemod.request("desktop window")
                return self._json(200, {"ok": True, "paused": True})
            if path == "/api/continue":
                pausemod.clear()
                return self._json(200, {"ok": True, "paused": False})
            if path == "/api/resume":
                stopmod.clear()
                return self._json(200, {"ok": True, "emergency": False})
            if path == "/api/mode":
                m = body.get("mode")
                if m not in cfgmod.MODES:
                    return self._json(422, {"error": "Mode must be agent or companion"})
                with state.lock:
                    state.cfg.mode = m
                    cfgmod.save(state.cfg)
                return self._json(200, {"ok": True, "mode": m})
            if path == "/api/run":
                if body.get("on"):
                    err = state.start()
                    if err:
                        return self._json(422, {"error": err})
                else:
                    state.halt()
                return self._json(200, {"ok": True})
            if path == "/api/pair":
                try:
                    server = normalise(str(body.get("server", "")), state.allow_http)
                    r = Client(server, allow_http=state.allow_http).pair(str(body.get("code", "")).strip())
                except ValueError as e:
                    return self._json(422, {"error": str(e)})
                except ServerError as e:
                    return self._json(422, {"error": "The code is wrong or expired (10 minutes)" if e.status == 422 else f"Could not reach the server ({e})"})
                with state.lock:
                    state.cfg.server, state.cfg.token, state.cfg.device_id = server, str(r["token"]), str(r["device_id"])
                    cfgmod.save(state.cfg)
                return self._json(200, {"ok": True})
            self._json(404, {"error": "not found"})

    return H


BIND = "127.0.0.1"                                       # never a network address


def bind(state: State, token: str, port: int = 0) -> ThreadingHTTPServer:
    holder = [0]
    srv = ThreadingHTTPServer((BIND, port), make_handler(state, token, holder))
    holder[0] = srv.server_address[1]
    return srv


def serve(port: int = 0, open_browser: bool = True, allow_http: bool = False, exit_with_parent: bool = False) -> int:
    state = State(allow_http=allow_http)
    token = secrets.token_urlsafe(24)
    srv = bind(state, token, port)
    holder = [srv.server_address[1]]
    url = f"http://127.0.0.1:{holder[0]}/#{token}"
    print("Window running:", url, "\nPress Ctrl+C to stop", flush=True)
    if exit_with_parent:
        # the desktop shell keeps our stdin open; when it dies (even by a crash/kill) the pipe closes and we stop too
        def _watch() -> None:
            try:
                while sys.stdin.buffer.read(4096):
                    pass
            except Exception:
                pass
            srv.shutdown()

        threading.Thread(target=_watch, daemon=True).start()
    if open_browser:
        try:
            webbrowser.open(url)
        except Exception:
            pass
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        state.halt()
        srv.server_close()
    return 0
