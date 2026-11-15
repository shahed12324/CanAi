"""What the agent is doing right now, for the window.  Written by the engine, read by the local server; lives in memory only (the durable record is the audit log)."""
from __future__ import annotations

import threading
import time

_lock = threading.Lock()
_live: dict | None = None
_last: dict | None = None


def begin(task: str, steps: list[tuple[str, str]]) -> None:
    global _live
    with _lock:
        _live = {"task": task, "state": "running", "started": int(time.time()), "steps": [{"id": i, "kind": k, "state": "queued", "note": ""} for i, k in steps]}


def step(sid: str, state: str, note: str = "") -> None:
    with _lock:
        if _live is None:
            return
        for s in _live["steps"]:
            if s["id"] == sid:
                s["state"], s["note"] = state, note[:200]


def state(value: str) -> None:
    with _lock:
        if _live is not None:
            _live["state"] = value


def end(value: str) -> None:
    global _live, _last
    with _lock:
        if _live is not None:
            _live["state"], _live["ended"] = value, int(time.time())
            _last, _live = _live, None


def snapshot() -> dict:
    with _lock:
        cur = _live
        return {"current": dict(cur, steps=[dict(s) for s in cur["steps"]]) if cur else None,
                "last": dict(_last, steps=[dict(s) for s in _last["steps"]]) if _last else None}


def reset() -> None:
    global _live, _last
    with _lock:
        _live = _last = None
