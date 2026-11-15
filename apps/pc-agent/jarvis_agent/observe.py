"""Looking at the screen's state without touching it: which window is in front, and waiting for one to appear.  These are the evidence behind
"I opened Notepad" or "the page is now showing": the engine verifies a GUI step against the real foreground window, not against the step's own claim.
Titles are outside data (tainted) and a sensitive window's title is never shown."""
from __future__ import annotations

import time
from typing import Callable

from . import devices, guard, stop
from .screen import sensitive_window
from .untrusted import redact

MAX_WAIT = 30.0
sleep: Callable[[float], None] = time.sleep
clock: Callable[[], float] = time.monotonic


def _name(s: object) -> str:
    s = str(s or "").replace("\\", "/").rsplit("/", 1)[-1].lower()
    return s[:-4] if s.endswith(".exe") else s


def matches(win: dict | None, process: object = None, title_contains: object = None) -> tuple[bool, str]:
    """(ok, short evidence).  A sensitive window never matches on its title and its title is never echoed."""
    if not win:
        return False, "No window in front"
    if sensitive_window(win):
        return False, "The front window is sensitive — title not shown"
    if process is not None and _name(win.get("process")) != _name(process):
        return False, f"In front: {_name(win.get('process'))}, expected {_name(process)}"
    if title_contains is not None and str(title_contains).lower() not in str(win.get("title", "")).lower():
        return False, "The window title does not contain the expected text"
    return True, f"In front: {_name(win.get('process'))} — {redact(str(win.get('title', '')), 80)}"


def _spec(p: dict) -> tuple[object, object]:
    proc, title = p.get("process"), p.get("title_contains")
    for v in (proc, title):
        if v is not None and (not isinstance(v, str) or not v.strip() or len(v) > 120):
            raise guard.Refused("process/title_contains is empty or too large")
    if proc is None and title is None:
        raise guard.Refused("process or title_contains is required")
    return proc, title


def window_info(cfg, p: dict) -> str:
    win = devices.get().foreground()
    if not win:
        return "No window in front"
    if sensitive_window(win):
        return "The front window is sensitive — title not shown"
    return f"[external data] App: {_name(win.get('process'))}\nTitle: {redact(str(win.get('title', '')), 160)}"


def wait_window(cfg, p: dict) -> str:
    proc, title = _spec(p)
    t = p.get("timeout", 10)
    if not isinstance(t, (int, float)) or isinstance(t, bool) or not 1 <= t <= MAX_WAIT:
        raise guard.Refused(f"timeout must be 1–{int(MAX_WAIT)} seconds")
    t0, why = clock(), ""
    while True:
        if stop.is_set():
            raise guard.Refused("Emergency stop is on")
        ok, why = matches(devices.get().foreground(), proc, title)
        if ok:
            return why
        if clock() - t0 >= t:
            raise guard.Refused(f"The window did not appear within {int(t)} seconds ({why})")
        sleep(0.5)


def check_foreground(spec: dict) -> tuple[bool, str]:
    """verify.check type 'window_foreground'."""
    try:
        proc, title = _spec(spec)
    except guard.Refused as e:
        return False, str(e)
    return matches(devices.get().foreground(), proc, title)
