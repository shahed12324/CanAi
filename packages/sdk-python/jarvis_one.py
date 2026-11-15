"""JARVIS ONE public API client (Python 3.9+, no dependencies).

    from jarvis_one import Client
    c = Client("jk_live_...", base_url="https://your-host/api/public/v1")
    print(c.me())
    run = c.run_workflow("<workflow id>", {"name": "value"})
    print(c.wait_for_run(run["run_id"]))

The key is only ever sent in the Authorization header over HTTPS (plain http is allowed only for localhost, for testing),
and never appears in error messages or repr().  Redirects are not followed.
"""
from __future__ import annotations

import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, Optional

__all__ = ["Client", "JarvisError", "AuthError", "ScopeError", "NotFound", "RateLimited", "RequestFailed"]

_KEY = re.compile(r"\Ajk_live_[0-9a-f]{40}\Z")
_ID = re.compile(r"\A[0-9a-z]{26}\Z")
_FINAL = {"done", "failed", "cancelled"}


class JarvisError(Exception):
    """Base class.  `.status` is the HTTP status (0 when nothing came back), `.code` the API's error code if it gave one."""

    def __init__(self, message: str, status: int = 0, code: Optional[str] = None):
        super().__init__(message)
        self.status = status
        self.code = code


class AuthError(JarvisError):
    """401: key missing, wrong, revoked, expired, or its owner was removed."""


class ScopeError(JarvisError):
    """403: the key does not have the scope this call needs."""


class NotFound(JarvisError):
    """404."""


class RateLimited(JarvisError):
    """429: too many requests, or the workflow is already running.  `.retry_after` seconds when the server said."""

    def __init__(self, message: str, status: int = 429, code: Optional[str] = None, retry_after: Optional[float] = None):
        super().__init__(message, status, code)
        self.retry_after = retry_after


class RequestFailed(JarvisError):
    """Any other refusal (409 switched off, 422 bad inputs, 5xx, network trouble)."""


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):  # a redirect could carry the key somewhere else
        return None


class Client:
    def __init__(self, key: str, base_url: str, timeout: float = 15.0):
        if not isinstance(key, str) or _KEY.match(key) is None:
            raise ValueError("key must look like jk_live_ followed by 40 hex characters")
        u = urllib.parse.urlparse(base_url)
        local = u.hostname in ("localhost", "127.0.0.1")
        if u.scheme != "https" and not (u.scheme == "http" and local):
            raise ValueError("base_url must be https (http is allowed only for localhost)")
        if not u.hostname or u.username or u.password:
            raise ValueError("base_url is not valid")
        self._key = key
        self._base = base_url.rstrip("/")
        self._timeout = timeout
        self._opener = urllib.request.build_opener(_NoRedirect)

    def __repr__(self) -> str:  # never show the key
        return f"Client(base_url={self._base!r}, key={self._key[:12]}...)"

    # ------------------------------------------------------------------ calls
    def me(self) -> Dict[str, Any]:
        return self._call("GET", "/me")["data"]

    def credits(self) -> Dict[str, str]:
        return self._call("GET", "/credits")["data"]

    def workflows(self) -> list:
        return self._call("GET", "/workflows")["data"]

    def run_workflow(self, workflow_id: str, inputs: Optional[Dict[str, str]] = None) -> Dict[str, str]:
        self._check_id(workflow_id)
        inputs = inputs or {}
        if not isinstance(inputs, dict) or not all(isinstance(k, str) and isinstance(v, (str, int, float)) for k, v in inputs.items()):
            raise ValueError("inputs must be a dict of text values")
        return self._call("POST", f"/workflows/{workflow_id}/run", {"inputs": {k: str(v) for k, v in inputs.items()}})["data"]

    def run(self, run_id: str) -> Dict[str, Any]:
        self._check_id(run_id)
        return self._call("GET", f"/runs/{run_id}")["data"]

    def wait_for_run(self, run_id: str, timeout: float = 300.0, poll: float = 2.0, _sleep=time.sleep, _now=time.monotonic) -> Dict[str, Any]:
        """Polls until the run is done / failed / cancelled.  Raises TimeoutError when it takes longer than `timeout`.
        A run that waits for the person's approval keeps waiting: the key cannot approve it."""
        end = _now() + timeout
        while True:
            r = self.run(run_id)
            if r["status"] in _FINAL:
                return r
            if _now() + poll > end:
                raise TimeoutError(f"run {run_id} still {r['status']} after {timeout} seconds")
            _sleep(poll)

    # ------------------------------------------------------------------ plumbing
    @staticmethod
    def _check_id(v: str) -> None:
        if not isinstance(v, str) or _ID.match(v) is None:
            raise ValueError("not a valid id")

    def _call(self, method: str, path: str, body: Optional[dict] = None) -> Dict[str, Any]:
        data = None if body is None else json.dumps(body).encode()
        req = urllib.request.Request(self._base + path, data=data, method=method)
        req.add_header("Authorization", "Bearer " + self._key)
        req.add_header("Accept", "application/json")
        req.add_header("User-Agent", "jarvis-one-python/1")
        if data is not None:
            req.add_header("Content-Type", "application/json")
        try:
            with self._opener.open(req, timeout=self._timeout) as resp:
                raw = resp.read(2_000_000)
        except urllib.error.HTTPError as e:
            raise self._error(e) from None
        except (urllib.error.URLError, OSError) as e:
            raise RequestFailed("could not reach the server: " + type(e).__name__) from None
        try:
            out = json.loads(raw)
        except ValueError:
            raise RequestFailed("the server did not answer with JSON", 0) from None
        if not isinstance(out, dict):
            raise RequestFailed("unexpected answer", 0)
        return out

    @staticmethod
    def _error(e: urllib.error.HTTPError) -> JarvisError:
        try:
            j = json.loads(e.read(100_000))
            code, msg = j.get("error"), j.get("message") or j.get("error") or ""
        except Exception:
            code, msg = None, ""
        msg = (str(msg) or f"HTTP {e.code}")[:200]
        if e.code == 401:
            return AuthError(msg, 401, code)
        if e.code == 403:
            return ScopeError(msg, 403, code)
        if e.code == 404:
            return NotFound(msg, 404, code)
        if e.code == 429:
            ra = e.headers.get("Retry-After") if e.headers else None
            try:
                wait = float(ra) if ra is not None else None
            except ValueError:
                wait = None
            return RateLimited(msg, 429, code, wait)
        return RequestFailed(msg, e.code, code)
