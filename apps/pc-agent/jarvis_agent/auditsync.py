"""Copies this computer's hash-chained audit log (audit.jsonl) to the server and compares the two (Phase 10).

What it proves: the server recomputes every hash and checks every link, then stores the rows append-only.  Later, `sync` asks the server where its copy ends and checks that
OUR file still has the very same row there - so rewriting, truncating or replacing the local file after an upload is detected ("diverged").
What it does not prove: what an agent that was already compromised before an upload chose to write.  Nothing is uploaded from a local file whose own chain is broken."""
from __future__ import annotations

import os
import time
from typing import Callable

from . import taskengine
from .client import AuthError, Client, ServerError

BATCH = 200
MAX_BATCHES = 25                                   # at most 5000 rows per run; the rest follows next time
KEYS = ("t", "task", "step", "event", "detail", "prev", "h")

# status values: synced | local_tampered | diverged | offline | server_too_old | unauthorized | error
LAST: dict = {}                                    # the most recent result, for the window (memory only)


def _rows_for_upload(rows: list[dict]) -> list[dict]:
    return [{k: r[k] for k in KEYS} for r in rows]


def sync(client: Client, audit: taskengine.Audit | None = None) -> dict:
    """Never raises (except AuthError, which the caller treats as 'this device was removed')."""
    audit = audit or taskengine.Audit()
    out: dict = {"status": "error", "uploaded": 0, "local_rows": 0, "server_rows": None}
    try:
        ok, good = audit.verify_chain()
        rows = audit.rows()
    except (ValueError, OSError, KeyError):
        ok, good, rows = False, 0, []
    out["local_rows"] = len(rows)
    if not ok:
        out.update(status="local_tampered", good_rows=good)                       # never copy a broken chain to the server
        return _remember(out)
    try:
        head = client.audit_head()
        seq, h = int(head.get("seq", -1)), str(head.get("h", ""))
        if seq < 0 or len(h) != 64:
            return _remember(dict(out, status="error"))
        for _ in range(MAX_BATCHES + 1):
            out["server_rows"] = seq
            if seq > len(rows) or (seq > 0 and rows[seq - 1]["h"] != h):
                return _remember(dict(out, status="diverged", detail="the server's copy and this computer's file no longer agree"))
            if seq == len(rows):
                return _remember(dict(out, status="synced"))
            if out["uploaded"] >= MAX_BATCHES * BATCH:
                return _remember(dict(out, status="synced", partial=True))
            batch = rows[seq:seq + BATCH]
            try:
                r = client.audit_sync(seq, _rows_for_upload(batch))
            except ServerError as e:
                if e.status == 409 and isinstance(e.body.get("seq"), int) and len(str(e.body.get("h", ""))) == 64:
                    if e.body.get("status") == "chain_broken":
                        return _remember(dict(out, status="diverged", server_rows=e.body["seq"], detail="the server refused: the chain does not continue the stored one"))
                    seq, h = e.body["seq"], str(e.body["h"])                       # seq_mismatch: someone uploaded meanwhile; compare again from the real head
                    continue
                raise
            before = seq
            seq, h = int(r.get("seq", -1)), str(r.get("h", ""))
            out["uploaded"] += len(batch)
            if seq != before + len(batch) or h != batch[-1]["h"]:
                return _remember(dict(out, status="error", detail="the server's answer does not match what was sent"))
        return _remember(dict(out, status="synced", partial=True))
    except AuthError:
        raise
    except ServerError as e:
        st = "server_too_old" if e.status in (404, 405) else "offline" if e.status == 0 else "error"
        return _remember(dict(out, status=st, detail=f"{e.status} {e.code}".strip()))


def _remember(res: dict) -> dict:
    LAST.clear()
    LAST.update(res, at=int(time.time()))
    return res


class Periodic:
    """For the run loop: sync every `every` seconds, but only when the file changed since the last successful look."""

    def __init__(self, every: float = 300.0, audit: taskengine.Audit | None = None, now: Callable[[], float] = time.monotonic):
        self.every, self.audit, self.now = every, audit, now
        self._next = 0.0
        self._seen: tuple[float, int] | None = None

    def _stamp(self) -> tuple[float, int] | None:
        try:
            st = os.stat((self.audit or taskengine.Audit()).path)
            return st.st_mtime, st.st_size
        except OSError:
            return None

    def maybe(self, client) -> dict | None:
        if not hasattr(client, "audit_head"):
            return None
        stamp = self._stamp()                                  # a stat() is cheap, so look every loop; only the upload is rate-limited
        if stamp is None or stamp == self._seen or self.now() < self._next:
            return None
        self._next = self.now() + self.every
        res = sync(client, self.audit)
        if res["status"] == "synced":
            self._seen = stamp
        return res
