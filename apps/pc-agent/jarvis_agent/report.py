"""make_report: a local CSV table in an allowed folder -> an Excel report with the data and summary numbers computed HERE (sum, average, min, max, count per
number column, optionally grouped by one column).  A model plans the step; it never does the arithmetic and never sees the data, so the numbers are exact and checkable.
Output is written through docs.create_xlsx, so the same folder rules, backup-before-overwrite and formula-neutralising apply."""
from __future__ import annotations

import csv
import io
import os

from . import docs, guard

MAX_BYTES = 5 * 1024 * 1024
MAX_ROWS = 50_000
MAX_COLS = 40
KEYS = {"src", "dst", "group_by", "overwrite"}


def _num(s: str) -> float | None:
    t = s.strip().replace(",", "") if isinstance(s, str) else ""
    if not t:
        return None
    try:
        v = float(t)
    except ValueError:
        return None
    return v if v == v and abs(v) != float("inf") else None


def _clean(v: float) -> object:
    return int(v) if v == int(v) and abs(v) < 1e15 else round(v, 6)


def read_table(real: str) -> tuple[list[str], list[list[str]]]:
    if os.path.getsize(real) > MAX_BYTES:
        raise guard.Refused("The CSV file is larger than 5 MB")
    with open(real, "rb") as f:
        text = f.read().decode("utf-8-sig", errors="replace")
    try:
        dialect = csv.Sniffer().sniff(text[:4096], delimiters=",;\t")
    except csv.Error:
        dialect = csv.excel
    rows = list(csv.reader(io.StringIO(text), dialect))
    rows = [r for r in rows if any(c.strip() for c in r)]
    if len(rows) < 2:
        raise guard.Refused("The CSV needs a header row and at least one data row")
    if len(rows) - 1 > MAX_ROWS:
        raise guard.Refused(f"More than {MAX_ROWS} rows")
    head = [h.strip() or f"column{i + 1}" for i, h in enumerate(rows[0])]
    if len(head) > MAX_COLS or len(set(head)) != len(head):
        raise guard.Refused(f"Header must have at most {MAX_COLS} distinct column names")
    body = [(r + [""] * len(head))[: len(head)] for r in rows[1:]]
    return head, body


def make_report(cfg, p: dict) -> str:
    if set(p) - KEYS or not {"src", "dst"} <= set(p):
        raise guard.Refused("make_report takes src, dst, optional group_by and overwrite")
    real = guard.path_in_roots(p["src"], cfg.roots)
    if not os.path.isfile(real) or not real.lower().endswith((".csv", ".txt")):
        raise guard.Refused("src must be an existing .csv file")
    head, body = read_table(real)
    numeric = [i for i in range(len(head)) if sum(1 for r in body if _num(r[i]) is not None) >= 0.9 * sum(1 for r in body if r[i].strip()) and any(_num(r[i]) is not None for r in body)]
    gb = p.get("group_by")
    if gb is not None and (not isinstance(gb, str) or gb not in head):
        raise guard.Refused("group_by must be one of the column names")
    summary: list[list[object]] = [["Column", "Count", "Sum", "Average", "Min", "Max"]]
    for i in numeric:
        vals = [v for v in (_num(r[i]) for r in body) if v is not None]
        summary.append([head[i], len(vals), _clean(sum(vals)), _clean(sum(vals) / len(vals)), _clean(min(vals)), _clean(max(vals))])
    sheets: dict[str, list[list[object]]] = {}
    data = [[(_num(c) if _num(c) is not None and i in numeric else c) for i, c in enumerate(r)] for r in body]
    shown = data[: docs.MAX_ROWS - 1]
    sheets["Data"] = [head] + [[_clean(c) if isinstance(c, float) else c for c in r] for r in shown]
    summary.append([])
    summary.append(["Rows in the CSV", len(body)])
    if len(shown) < len(body):
        summary.append([f"Data sheet shows the first {len(shown)} rows; the summary uses all {len(body)}"])
    sheets["Summary"] = summary
    if gb:
        gi = head.index(gb)
        groups: dict[str, list[list[str]]] = {}
        for r in body:
            groups.setdefault(r[gi].strip() or "(empty)", []).append(r)
        if len(groups) > docs.MAX_ROWS - 1:
            raise guard.Refused("Too many different group values")
        rows = [[gb, "Rows"] + [f"Sum of {head[i]}" for i in numeric if i != gi]]
        for g, rs in sorted(groups.items(), key=lambda kv: kv[0].lower()):
            rows.append([g, len(rs)] + [_clean(sum(v for v in (_num(r[i]) for r in rs) if v is not None)) for i in numeric if i != gi])
        sheets[f"By {gb}"[:31]] = rows
    out = docs.create_xlsx(cfg, {"path": p["dst"], "sheets": sheets, "overwrite": p.get("overwrite") is True})
    return f"Report made from {len(body)} rows, {len(numeric)} number column(s)" + (f", grouped by {gb}" if gb else "") + f"; {out}"
