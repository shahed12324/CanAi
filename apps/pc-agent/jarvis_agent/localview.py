"""Read-only views the desktop window needs: a folder browser limited to the allowed folders, and the local task list."""
from __future__ import annotations

import os

from . import guard
from .config import Config

MAX_ENTRIES = 500


def list_folder(cfg: Config, path: str | None) -> dict:
    """No path -> the allowed folders.  A path -> its entries, only if it is inside an allowed folder (symlinks resolved)."""
    if not path:
        roots = []
        for r in cfg.roots:
            rr = os.path.realpath(r)
            if os.path.dirname(rr) != rr and os.path.isdir(rr):
                roots.append({"path": rr, "name": os.path.basename(rr) or rr})
        return {"path": "", "parent": "", "entries": roots, "roots": True}
    real = guard.path_in_roots(path, cfg.roots)
    if not os.path.isdir(real):
        raise guard.Refused("Not a folder")
    entries = []
    try:
        names = sorted(os.listdir(real), key=lambda n: (not os.path.isdir(os.path.join(real, n)), n.lower()))
    except OSError:
        raise guard.Refused("The folder could not be read") from None
    for n in names[:MAX_ENTRIES]:
        full = os.path.join(real, n)
        try:
            st = os.stat(full)
        except OSError:
            continue
        entries.append({"name": n, "path": full, "dir": os.path.isdir(full), "size": st.st_size, "mtime": int(st.st_mtime)})
    parent = os.path.dirname(real)
    try:
        guard.path_in_roots(parent, cfg.roots)
    except guard.Refused:
        parent = ""                                           # at the top of an allowed folder: "up" goes back to the list of folders
    return {"path": real, "parent": parent, "entries": entries, "roots": False, "truncated": len(names) > MAX_ENTRIES}


def task_summaries(rows: list[dict], limit: int = 20) -> list[dict]:
    """Group audit rows by task, using the engine's real events: task_started, act/verify per step, then task_completed | task_failed | task_cancelled | plan_refused."""
    order: list[str] = []
    by: dict[str, dict] = {}
    for r in rows:
        t = str(r.get("task", ""))
        if not t:
            continue
        if t not in by:
            by[t] = {"task": t, "started": r.get("t", 0), "last": r.get("t", 0), "event": "", "steps": 0, "state": "running", "detail": "", "_s": set()}
            order.append(t)
        s = by[t]
        s["last"] = r.get("t", s["last"])
        ev = str(r.get("event", ""))
        s["event"] = ev
        if ev == "act" and r.get("step"):
            s["_s"].add(r["step"])
        if ev == "task_completed":
            s["state"] = "done"
        elif ev in ("task_failed", "plan_refused"):
            s["state"] = "failed"
        elif ev == "task_cancelled":
            s["state"] = "stopped"
        s["detail"] = str(r.get("detail", ""))[:200]
    out = []
    for t in order[-limit:][::-1]:
        s = by[t]
        s["steps"] = len(s.pop("_s"))
        out.append(s)
    return out
