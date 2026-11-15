"""Create a spreadsheet or a Word document inside an allowed folder, and check it by opening it again (F10, F12).

Both are made with the same atomic write as files, refuse to overwrite without a backup, and cap size.  `verify_*` re-opens the file from disk
and compares — the proof that "the report exists and holds what was asked", not a claim."""
from __future__ import annotations

import os
import tempfile

from . import fileops, guard
from .config import Config

MAX_ROWS, MAX_COLS, MAX_CELL, MAX_PARAS = 2000, 40, 500, 500


def _target(cfg: Config, p: dict, ext: str) -> str:
    parent, name = guard.parent_in_roots(p.get("path"), cfg.roots)
    if not name.lower().endswith(ext):
        raise guard.Refused(f"The file name must end with {ext}")
    return os.path.join(parent, name)


def _cell(v: object) -> object:
    if isinstance(v, bool) or v is None:
        return v
    if isinstance(v, (int, float)):
        return v
    if isinstance(v, str):
        if len(v) > MAX_CELL or "\x00" in v:
            raise guard.Refused("Cell text is invalid or too large")
        return v
    raise guard.Refused("Cells may only hold text or numbers")


def _is_number(s: str) -> bool:
    try:
        float(s)
        return True
    except ValueError:
        return False


def _rows(sheets: object) -> dict[str, list[list[object]]]:
    if not isinstance(sheets, dict) or not 1 <= len(sheets) <= 10:
        raise guard.Refused("Invalid sheets (1–10 allowed)")
    out: dict[str, list[list[object]]] = {}
    for name, rows in sheets.items():
        if not isinstance(name, str) or not 1 <= len(name) <= 31 or any(c in name for c in "[]:*?/\\"):
            raise guard.Refused("Invalid sheet name")
        if not isinstance(rows, list) or len(rows) > MAX_ROWS or any(not isinstance(r, list) or len(r) > MAX_COLS for r in rows):
            raise guard.Refused("Row/column count out of range")
        out[name] = [[_cell(c) for c in r] for r in rows]
    return out


def create_xlsx(cfg: Config, p: dict) -> str:
    try:
        from openpyxl import Workbook
    except ImportError:
        raise guard.Refused("openpyxl is not installed (pip install openpyxl)")
    target = _target(cfg, p, ".xlsx")
    sheets = _rows(p.get("sheets"))
    bak = fileops._ready_target(target, p.get("overwrite") is True)
    wb = Workbook()
    wb.remove(wb.active)
    for name, rows in sheets.items():
        ws = wb.create_sheet(name)
        for r in rows:
            ws.append(r)
        for row in ws.iter_rows():                               # a text cell starting with = + - @ would run as a formula when someone opens the file: force text
            for c in row:
                if isinstance(c.value, str) and c.value[:1] in ("=", "+", "-", "@") and not _is_number(c.value):
                    c.data_type = "s"
    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(target), prefix=".canai-", suffix=".xlsx")
    os.close(fd)
    try:
        wb.save(tmp)
        os.replace(tmp, target)
    except Exception:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise
    return f"Excel file created ({sum(len(r) for r in sheets.values())} rows)" + (f"; backup of the old one: {os.path.basename(bak)}" if bak else "")


def create_docx(cfg: Config, p: dict) -> str:
    try:
        import docx
    except ImportError:
        raise guard.Refused("python-docx is not installed (pip install python-docx)")
    target = _target(cfg, p, ".docx")
    title, paras = p.get("title"), p.get("paragraphs")
    if not isinstance(paras, list) or not 1 <= len(paras) <= MAX_PARAS or not all(isinstance(x, str) and len(x) <= 4000 and "\x00" not in x for x in paras):
        raise guard.Refused("Invalid paragraphs")
    if title is not None and (not isinstance(title, str) or len(title) > 200):
        raise guard.Refused("Invalid title")
    bak = fileops._ready_target(target, p.get("overwrite") is True)
    d = docx.Document()
    if title:
        d.add_heading(title, level=1)
    for t in paras:
        d.add_paragraph(t)
    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(target), prefix=".canai-", suffix=".docx")
    os.close(fd)
    try:
        d.save(tmp)
        os.replace(tmp, target)
    except Exception:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise
    return f"Word file created ({len(paras)} paragraphs)" + (f"; backup of the old one: {os.path.basename(bak)}" if bak else "")


def read_xlsx(path: str) -> dict[str, list[list[object]]]:
    from openpyxl import load_workbook
    wb = load_workbook(path, read_only=True, data_only=False)
    try:
        return {ws.title: [list(r) for r in ws.iter_rows(values_only=True)] for ws in wb.worksheets}
    finally:
        wb.close()


def read_docx(path: str) -> list[str]:
    import docx
    return [x.text for x in docx.Document(path).paragraphs]
