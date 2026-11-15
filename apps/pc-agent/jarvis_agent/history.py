"""A small local record of what this computer did or refused (the server keeps its own audit)."""
from __future__ import annotations

import json
import os
import time

from . import config as cfgmod

KEEP = 200


def _file():
    return cfgmod.home_dir() / "history.jsonl"


def record(kind: str, ok: bool, text: str, when: float | None = None) -> None:
    d = cfgmod.home_dir()
    d.mkdir(parents=True, exist_ok=True)
    row = {"t": int(when if when is not None else time.time()), "kind": str(kind)[:40], "ok": bool(ok), "text": (text or "").splitlines()[0][:300] if text else ""}
    rows = _all()[-(KEEP - 1):] + [row]
    tmp = _file().with_suffix(".tmp")
    tmp.write_text("\n".join(json.dumps(r, ensure_ascii=False) for r in rows) + "\n", encoding="utf-8")
    try:
        os.chmod(tmp, 0o600)
    except OSError:
        pass
    os.replace(tmp, _file())


def _all() -> list[dict]:
    """Oldest first."""
    f = _file()
    if not f.exists():
        return []
    out = []
    for line in f.read_text(encoding="utf-8").splitlines():
        try:
            r = json.loads(line)
        except ValueError:
            continue
        if isinstance(r, dict) and "t" in r:
            out.append(r)
    return out


def recent(n: int = 50) -> list[dict]:
    """Newest first."""
    return _all()[-n:][::-1]
