"""Mouse and keyboard, behind every guard we could think of.  Always high risk: a person approves it on the phone (PIN) every time.

Guards (all tested with a fake backend): the emergency stop; the FOREGROUND window must belong to an app the owner listed (cfg.apps) and must not look sensitive;
clicks must land inside that window; bounded text length; key combos are a short allowlist with dangerous ones refused; at most N actions per minute;
typed text is never echoed into results or logs (it may be a secret)."""
from __future__ import annotations

import re
import time
from collections import deque
from typing import Callable

from . import devices, guard, stop
from .screen import sensitive_window

MAX_TEXT = 200
MAX_PER_MINUTE = 30
KEY_TOKENS = {*[chr(c) for c in range(ord("a"), ord("z") + 1)], *"0123456789", *[f"f{i}" for i in range(1, 13)],
              "enter", "tab", "esc", "space", "backspace", "delete", "up", "down", "left", "right", "home", "end", "pageup", "pagedown", "ctrl", "shift", "alt"}
MODIFIERS = {"ctrl", "shift", "alt"}
BLOCKED_COMBOS = {frozenset(x) for x in (("alt", "f4"), ("ctrl", "alt", "delete"), ("ctrl", "shift", "esc"), ("alt", "tab"), ("ctrl", "alt", "esc"))}
_stamps: deque[float] = deque()
clock: Callable[[], float] = time.monotonic


def _rate() -> None:
    now = clock()
    while _stamps and now - _stamps[0] > 60:
        _stamps.popleft()
    if len(_stamps) >= MAX_PER_MINUTE:
        raise guard.Refused(f"At most {MAX_PER_MINUTE} inputs per minute — slow down")
    _stamps.append(now)


def reset_rate() -> None:
    _stamps.clear()


def _app_name(s: str) -> str:
    s = s.replace("\\", "/").rsplit("/", 1)[-1].lower()
    return s[:-4] if s.endswith(".exe") else s


def _foreground_ok(cfg, win: dict | None) -> dict:
    if not win:
        raise guard.Refused("No window in front")
    allowed = {_app_name(a) for a in cfg.apps}
    if not allowed:
        raise guard.Refused("No app is allowed — add an app to the policy first")
    if _app_name(str(win.get("process", ""))) not in allowed:
        raise guard.Refused("The front window does not belong to an allowed app")
    if sensitive_window(win):
        raise guard.Refused("The front window is sensitive — no input was sent")
    return win


def parse_keys(spec: object) -> list[str]:
    if not isinstance(spec, str) or not spec.strip() or len(spec) > 40:
        raise guard.Refused("Invalid keys")
    toks = [t.strip().lower() for t in spec.split("+")]
    if not 1 <= len(toks) <= 4 or any(t not in KEY_TOKENS for t in toks) or len(set(toks)) != len(toks):
        raise guard.Refused("This key combination is not allowed")
    if frozenset(toks) in BLOCKED_COMBOS:
        raise guard.Refused("This key combination is dangerous — forbidden")
    if len(toks) > 1 and not any(t in MODIFIERS for t in toks[:-1]):
        raise guard.Refused("With several keys, ctrl/shift/alt must come first")
    return toks


def _int(v: object, lo: int, hi: int, what: str) -> int:
    if not isinstance(v, int) or isinstance(v, bool) or not lo <= v <= hi:
        raise guard.Refused(f"Invalid {what}")
    return v


def input_control(cfg, p: dict) -> str:
    action = p.get("action")
    if action not in ("type", "click", "key", "move", "scroll"):
        raise guard.Refused("action must be type/click/key/move/scroll")
    if stop.is_set():
        raise guard.Refused("Emergency stop is on")
    be = devices.get()
    win = _foreground_ok(cfg, be.foreground())
    if action == "type":
        text = p.get("text")
        if not isinstance(text, str) or not text or len(text) > MAX_TEXT or re.search(r"[\x00-\x08\x0b-\x1f\x7f]", text):
            raise guard.Refused(f"text must be 1–{MAX_TEXT} characters, without control characters")
        _rate()
        be.type_text(text)
        return f"Typed {len(text)} characters"
    if action == "key":
        keys = parse_keys(p.get("keys"))
        _rate()
        be.press(keys)
        return "Keys pressed: " + "+".join(keys)
    if action == "scroll":
        dy = _int(p.get("dy"), -20, 20, "dy")
        _rate()
        be.scroll(dy)
        return f"Scroll {dy}"
    x, y = _int(p.get("x"), -20000, 20000, "x"), _int(p.get("y"), -20000, 20000, "y")
    l, t, r, b = win["rect"]
    vl, vt, vw, vh = be.virtual_screen()                                           # all monitors together; left/top can be negative
    if not (l <= x < r and t <= y < b and vl <= x < vl + vw and vt <= y < vt + vh):
        raise guard.Refused("The point is outside the front window")
    _rate()
    if action == "move":
        be.move(x, y)
        return f"Mouse moved ({x},{y})"
    button = p.get("button", "left")
    if button not in ("left", "right"):
        raise guard.Refused("button must be left/right")
    be.click(x, y, button, p.get("double") is True)
    return f"Click ({x},{y}) {button}"
