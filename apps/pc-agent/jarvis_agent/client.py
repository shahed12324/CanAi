"""Talks to /api/pc/v1 (standard library only)."""
from __future__ import annotations

import json
import urllib.error
import urllib.request
from urllib.parse import urlsplit

TIMEOUT = 20


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """The server API never redirects.  Following a redirect would send the device token (an Authorization header) to wherever the answer points - another host, or plain http."""
    def redirect_request(self, *a, **k):
        return None


_OPENER = urllib.request.build_opener(_NoRedirect)


class ServerError(Exception):
    def __init__(self, status: int, code: str = "", body: dict | None = None):
        super().__init__(f"{status} {code}".strip())
        self.status, self.code, self.body = status, code, body or {}


class AuthError(ServerError):
    """The token no longer works (device removed on the website).  Retrying will not help."""


def is_local(url: str) -> bool:
    return (urlsplit(url).hostname or "") in ("localhost", "127.0.0.1", "::1")


def normalise(server: str, allow_http: bool = False) -> str:
    s = server.strip().rstrip("/")
    u = urlsplit(s)
    if u.scheme not in ("http", "https") or not u.hostname:
        raise ValueError("Give the server address as https://…")
    if u.scheme == "http" and not (allow_http or is_local(s)):
        raise ValueError("https is required to send a password-like token safely (--allow-http only for your own testing)")
    return s


class Client:
    def __init__(self, server: str, token: str = "", allow_http: bool = False):
        self.base = normalise(server, allow_http) + "/api/pc/v1"
        self.token = token

    def _call(self, method: str, path: str, body: dict | None = None) -> dict:
        data = None if body is None else json.dumps(body, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(self.base + path, data=data, method=method)
        req.add_header("Accept", "application/json")
        req.add_header("User-Agent", "jarvis-agent/1")
        if data is not None:
            req.add_header("Content-Type", "application/json")
        if self.token:
            req.add_header("Authorization", "Bearer " + self.token)
        try:
            with _OPENER.open(req, timeout=TIMEOUT) as r:
                raw = r.read(1_000_000)
        except urllib.error.HTTPError as e:
            code, body = "", {}
            try:
                parsed = json.loads(e.read(10_000).decode("utf-8"))
                if isinstance(parsed, dict):
                    body = parsed
                    code = str(parsed.get("error", "") or parsed.get("status", ""))
            except Exception:
                pass
            raise (AuthError if e.code in (401, 403) else ServerError)(e.code, code, body) from None
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            raise ServerError(0, type(e).__name__) from None
        try:
            return json.loads(raw.decode("utf-8")) if raw else {}
        except ValueError:
            raise ServerError(0, "bad_json") from None

    def desktop(self, method: str, path: str, body: dict | None = None) -> dict:
        """Calls the desktop endpoints the website offers under /api/pc/v1/desktop/… (chat, agents, workflows, memory, plans).  404 means an older server."""
        return self._call(method, "/desktop/" + path.lstrip("/"), body)

    def desktop_stream(self, path: str, body: dict, idle_timeout: float = 60.0):
        """POST to a desktop endpoint that answers with server-sent events and return the OPEN response (the caller reads lines and MUST close it: closing is how a
        Stop reaches the server).  Same error mapping as the other calls; an HTTP error before the first byte is raised, never streamed."""
        data = json.dumps(body, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(self.base + "/desktop/" + path.lstrip("/"), data=data, method="POST")
        req.add_header("Accept", "text/event-stream")
        req.add_header("Content-Type", "application/json")
        req.add_header("User-Agent", "jarvis-agent/1")
        if self.token:
            req.add_header("Authorization", "Bearer " + self.token)
        try:
            return _OPENER.open(req, timeout=idle_timeout)
        except urllib.error.HTTPError as e:
            code = ""
            try:
                code = str(json.loads(e.read(10_000).decode("utf-8")).get("error", ""))
            except Exception:
                pass
            raise (AuthError if e.code in (401, 403) else ServerError)(e.code, code) from None
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            raise ServerError(0, type(e).__name__) from None

    def pair(self, code: str) -> dict:
        return self._call("POST", "/pair", {"code": code.strip()})

    def rotate(self, new_token: str) -> None:
        self._call("POST", "/rotate", {"new_token": new_token})

    def next_action(self) -> dict | None:
        a = self._call("GET", "/actions/next").get("action")
        return a if isinstance(a, dict) else None

    def report(self, action_id: str, ok: bool, result: str) -> None:
        self._call("POST", f"/actions/{action_id}/report", {"ok": ok, "result": result[:20000]})

    def next_plan(self) -> dict | None:
        try:
            p = self._call("GET", "/plans/next").get("plan")
        except ServerError as e:
            if e.status == 404:                                  # an older server without plans: nothing to do
                return None
            raise
        return p if isinstance(p, dict) else None

    def report_plan(self, plan_id: str, state: str, result: str) -> None:
        self._call("POST", f"/plans/{plan_id}/report", {"state": state, "result": result[:8000]})

    def emergency(self) -> bool:
        try:
            return self._call("GET", "/emergency").get("active") is True
        except ServerError as e:
            if e.status == 404:                                  # an older server: nothing to ask
                return False
            raise

    def audit_head(self) -> dict:
        """Where the server's stored copy of this computer's audit chain ends: {status, seq, h}."""
        return self._call("GET", "/audit/head")

    def audit_sync(self, start: int, rows: list[dict]) -> dict:
        return self._call("POST", "/audit/sync", {"start": start, "rows": rows})

    def metrics(self, m: dict) -> None:
        self._call("POST", "/metrics", m)
