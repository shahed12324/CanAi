"""DOCX / XLSX / PPTX writers. Pure functions: validated spec in, file bytes out."""

from __future__ import annotations

import io

from docx import Document
from docx.shared import Pt
from openpyxl import Workbook
from openpyxl.styles import Font
from openpyxl.utils import get_column_letter
from pptx import Presentation

from app.office.models import DeckSpec, DocSpec, SheetSpec

DOCX = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
XLSX = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
PPTX = "application/vnd.openxmlformats-officedocument.presentationml.presentation"


def build_docx(spec: DocSpec) -> bytes:
    d = Document()
    d.core_properties.title = spec.title[:200]
    d.add_heading(spec.title, level=0)
    for b in spec.blocks:
        if b.t in ("h1", "h2", "h3"):
            d.add_heading(b.text or " ", level=int(b.t[1]))
        elif b.t == "p":
            d.add_paragraph(b.text)
        elif b.t in ("ul", "ol"):
            style = "List Bullet" if b.t == "ul" else "List Number"
            if b.text:
                d.add_paragraph(b.text)
            for it in b.items:
                d.add_paragraph(it, style=style)
        else:  # table
            cols = max([len(b.headers), *[len(r) for r in b.rows]], default=0)
            if cols == 0:
                continue
            t = d.add_table(rows=0, cols=cols)
            t.style = "Table Grid"
            if b.headers:
                cells = t.add_row().cells
                for i in range(cols):
                    run = cells[i].paragraphs[0].add_run(b.headers[i] if i < len(b.headers) else "")
                    run.bold = True
                    run.font.size = Pt(11)
            for r in b.rows:
                cells = t.add_row().cells
                for i in range(cols):
                    cells[i].text = r[i] if i < len(r) else ""
            d.add_paragraph("")
    buf = io.BytesIO()
    d.save(buf)
    return buf.getvalue()


def build_xlsx(spec: SheetSpec) -> bytes:
    wb = Workbook()
    if wb.active is not None:
        wb.remove(wb.active)
    used: set[str] = set()
    for s in spec.sheets:
        name, n = s.name, 2
        while name.lower() in used:
            suffix = f" {n}"
            name = s.name[: 31 - len(suffix)] + suffix
            n += 1
        used.add(name.lower())
        ws = wb.create_sheet(name)
        r0 = 1
        if s.headers:
            for c, h in enumerate(s.headers, 1):
                cell = ws.cell(row=1, column=c, value=h)
                cell.data_type = "s"  # never a formula
                cell.font = Font(bold=True)
            r0 = 2
        for ri, row in enumerate(s.rows, r0):
            for c, v in enumerate(row, 1):
                if v is None:
                    continue
                cell = ws.cell(row=ri, column=c, value=v)
                if isinstance(v, str):
                    cell.data_type = "s"  # "=1+1" stays the text "=1+1"
        width = max([len(s.headers), *[len(r) for r in s.rows]], default=0)
        for c in range(1, width + 1):
            longest = max(
                [len(str(s.headers[c - 1])) if c <= len(s.headers) else 0]
                + [len(str(r[c - 1])) for r in s.rows[:200] if c <= len(r) and r[c - 1] is not None]
            )
            ws.column_dimensions[get_column_letter(c)].width = min(60, max(10, longest + 2))
        if s.headers:
            ws.freeze_panes = "A2"
    wb.properties.title = spec.title[:200]
    buf = io.BytesIO()
    wb.save(buf)
    return buf.getvalue()


def build_pptx(spec: DeckSpec) -> bytes:
    p = Presentation()
    p.core_properties.title = spec.title[:200]
    first = p.slides.add_slide(p.slide_layouts[0])
    first.shapes.title.text = spec.title
    if len(first.placeholders) > 1:
        first.placeholders[1].text = ""
    for s in spec.slides:
        sl = p.slides.add_slide(p.slide_layouts[1])
        sl.shapes.title.text = s.title
        body = sl.placeholders[1].text_frame
        body.clear()
        for i, b in enumerate(s.bullets):
            para = body.paragraphs[0] if i == 0 else body.add_paragraph()
            para.text = b
        if s.notes:
            sl.notes_slide.notes_text_frame.text = s.notes
    buf = io.BytesIO()
    p.save(buf)
    return buf.getvalue()
