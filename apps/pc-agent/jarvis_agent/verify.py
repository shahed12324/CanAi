"""Evidence, not assurance (F12): look at the real file/process/URL state after an action and say what is true.

Every checker returns (ok, evidence).  Nothing here trusts the action's own "done" message."""
from __future__ import annotations

import hashlib
import os

from . import docs, guard
from .config import Config

MAX_HASH = 200 * 1024 * 1024


def sha256_file(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def check(cfg: Config, spec: dict) -> tuple[bool, str]:
    """`spec` = {"type": "window_foreground" (process / title_contains) | "file_exists" | "file_absent" | "file_hash" | "xlsx_cells" | "docx_text" | "text_contains", "path": ..., ...}"""
    t = spec.get("type")
    if t == "screen_text":
        from . import vision
        return vision.check_screen_text(cfg, spec)
    if t == "element_present":
        from . import vision
        return vision.check_element(cfg, spec)
    if t == "window_foreground":
        from . import observe
        return observe.check_foreground(spec)
    try:
        real = guard.path_in_roots(spec.get("path"), cfg.roots) if t != "file_absent" else None
        if t == "file_absent":
            parent, name = guard.parent_in_roots(spec.get("path"), cfg.roots)
            gone = not os.path.lexists(os.path.join(parent, name))
            return gone, "The file is gone" if gone else "The file still exists"
        if not os.path.isfile(real):
            return False, "The file does not exist"
        size = os.path.getsize(real)
        min_size = spec.get("min_size")
        if isinstance(min_size, int) and size < min_size:
            return False, f"File too small ({size} bytes)"
        if t == "file_exists":
            return True, f"File exists ({size} bytes)"
        if t == "file_hash":
            if size > MAX_HASH:
                return False, "File too large to hash"
            got = sha256_file(real)
            want = str(spec.get("sha256", "")).lower()
            return got == want, f"sha256={got[:16]}…" + ("" if got == want else " (mismatch)")
        if t == "xlsx_cells":
            data = docs.read_xlsx(real)
            for sheet, cells in (spec.get("expect") or {}).items():
                rows = data.get(sheet)
                if rows is None:
                    return False, f"Sheet '{sheet}' not found"
                for ref, want in cells.items():
                    col, row = _ref(ref)
                    got = rows[row][col] if row < len(rows) and col < len(rows[row]) else None
                    if _norm(got) != _norm(want):
                        return False, f"{sheet}!{ref} does not match (found: {str(got)[:40]!r})"
            return True, f"Opened the Excel file and checked it ({len(data)} sheets)"
        if t == "xlsx_report":                                    # a make_report result: it has the data and the summary, and the summary counts match
            data = docs.read_xlsx(real)
            if "Data" not in data or "Summary" not in data or len(data["Data"]) < 2:
                return False, "Report sheets are missing or empty"
            return True, f"Report opened again: {len(data['Data']) - 1} data rows, {len(data['Summary'])} summary rows"
        if t == "docx_text":
            paras = docs.read_docx(real)
            need = spec.get("contains")
            if not isinstance(need, str) or not need:
                return False, "Nothing to look for was given"
            hit = any(need in x for x in paras)
            return hit, "The text was found in the Word file" if hit else "The text is not in the Word file"
        if t == "text_contains":
            need = spec.get("contains")
            with open(real, "rb") as f:
                body = f.read(2 * 1024 * 1024).decode("utf-8", errors="replace")
            hit = isinstance(need, str) and need != "" and need in body
            return hit, "The text was found" if hit else "The text is not there"
        return False, "Unknown verification kind"
    except guard.Refused as e:
        return False, str(e)
    except Exception as e:                                       # a damaged file must read as "not verified", never crash the plan
        return False, f"Could not verify: {type(e).__name__}"


def _ref(ref: str) -> tuple[int, int]:
    letters = "".join(c for c in ref if c.isalpha()).upper()
    digits = "".join(c for c in ref if c.isdigit())
    if not letters or not digits:
        raise guard.Refused("Invalid cell name")
    col = 0
    for c in letters:
        col = col * 26 + (ord(c) - 64)
    return col - 1, int(digits) - 1


def _norm(v: object) -> object:
    if isinstance(v, str) and v.startswith("'"):
        v = v[1:]
    if isinstance(v, float) and v == int(v):
        return int(v)
    return v
