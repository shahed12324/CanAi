"""Local crash reports (Phase 12).  Nothing is ever sent anywhere by this module.

When the agent dies from an unexpected exception it writes ONE small JSON file into `<agent home>/crashes/` so the person can look at it, delete it, or copy it to support.
What a report holds: agent version, OS, Python version, time, where it happened (file basename, line, function - no source code, no variables), the exception type and a short
message with secrets redacted and the home folder replaced by "~".  What it never holds: config, tokens, task parameters, file contents, window titles, chat text.
At most KEEP reports are kept (newest)."""
from __future__ import annotations

import json
import os
import platform
import re
import secrets
import sys
import threading
import time
import traceback
from pathlib import Path

from . import __version__
from .config import home_dir
from .untrusted import redact

KEEP = 10
MAX_MSG = 300
MAX_FRAMES = 25
_NAME = re.compile(r"^crash-[0-9]{10}-[0-9a-f]{6}\.json$")
_URL_TOKEN = re.compile(r"(127\.0\.0\.1:\d+/?#?)[A-Za-z0-9_-]{8,}")


def crash_dir() -> Path:
    return home_dir() / "crashes"


def _scrub(text: object, limit: int = MAX_MSG) -> str:
    s = redact(str(text), limit * 2)
    s = _URL_TOKEN.sub(r"\1[token]", s)
    for h in {str(Path.home()), os.path.expanduser("~")}:
        if h and h != "/":
            s = s.replace(h, "~")
    s = "".join(c for c in s if c == "\n" or c.isprintable())
    return s if len(s) <= limit else s[:limit] + "…"


def build(exc_type, exc, tb, where: str = "agent") -> dict:
    frames = []
    for fs in traceback.extract_tb(tb)[-MAX_FRAMES:]:
        frames.append({"file": os.path.basename(fs.filename), "line": fs.lineno, "func": fs.name})
    return {
        "kind": "agent", "version": __version__, "os": platform.platform()[:80], "python": platform.python_version(),
        "time": int(time.time()), "where": _scrub(where, 60),
        "type": getattr(exc_type, "__name__", "Exception"), "message": _scrub(exc), "frames": frames,
    }


def record(exc_type, exc, tb, where: str = "agent") -> str | None:
    """Write a report; returns its file name.  Never raises (a crash reporter must not crash)."""
    try:
        d = crash_dir()
        d.mkdir(parents=True, exist_ok=True)
        name = f"crash-{int(time.time()):010d}-{secrets.token_hex(3)}.json"
        tmp = d / (name + ".tmp")
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(build(exc_type, exc, tb, where), f, ensure_ascii=False, indent=1)
        os.replace(tmp, d / name)
        _prune(d)
        return name
    except Exception:  # noqa: BLE001
        return None


def _prune(d: Path) -> None:
    files = sorted(p for p in d.iterdir() if _NAME.match(p.name))
    for p in files[:-KEEP]:
        try:
            p.unlink()
        except OSError:
            pass


def list_reports() -> list[dict]:
    d = crash_dir()
    out = []
    if not d.is_dir():
        return out
    for p in sorted((p for p in d.iterdir() if _NAME.match(p.name)), reverse=True):
        try:
            r = json.loads(p.read_text(encoding="utf-8"))
            out.append({"name": p.name, "time": int(r.get("time", 0)), "where": str(r.get("where", ""))[:60], "type": str(r.get("type", ""))[:80], "message": str(r.get("message", ""))[:MAX_MSG], "kind": str(r.get("kind", "agent"))})
        except (OSError, ValueError):
            out.append({"name": p.name, "time": 0, "where": "", "type": "unreadable", "message": "", "kind": "agent"})
    # shell (Tauri) reports use another file name; show them too
    for p in sorted(d.glob("crash-shell-*.json"), reverse=True)[:KEEP]:
        try:
            r = json.loads(p.read_text(encoding="utf-8"))
            out.append({"name": p.name, "time": int(r.get("time", 0)), "where": str(r.get("where", ""))[:60], "type": "panic", "message": str(r.get("message", ""))[:MAX_MSG], "kind": "shell"})
        except (OSError, ValueError):
            pass
    return out


def read(name: str) -> dict | None:
    if not (_NAME.match(name or "") or re.match(r"^crash-shell-[0-9]+\.json$", name or "")):
        return None
    try:
        return json.loads((crash_dir() / name).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return None


def clear() -> int:
    d = crash_dir()
    n = 0
    if d.is_dir():
        for p in list(d.iterdir()):
            if p.name.startswith("crash-") and p.suffix in (".json", ".tmp"):
                try:
                    p.unlink()
                    n += 1
                except OSError:
                    pass
    return n


def bundle() -> str:
    """All reports as one text the person can paste into a support message (they decide whether to send it)."""
    rows = [r for r in (read(x["name"]) for x in list_reports()) if r]
    return json.dumps({"agent_version": __version__, "reports": rows}, ensure_ascii=False, indent=1)


_installed = False


def install() -> None:
    """Route uncaught exceptions (main thread and other threads) into `record`, then let the normal handler run."""
    global _installed
    if _installed:
        return
    _installed = True
    prev = sys.excepthook

    def hook(t, e, tb):
        if not issubclass(t, KeyboardInterrupt):
            record(t, e, tb, "main")
        prev(t, e, tb)

    sys.excepthook = hook
    prev_t = threading.excepthook

    def thook(a):
        if a.exc_type is not SystemExit and not issubclass(a.exc_type, KeyboardInterrupt):
            record(a.exc_type, a.exc_value, a.exc_traceback, "thread")
        prev_t(a)

    threading.excepthook = thook
