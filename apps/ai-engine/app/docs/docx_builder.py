"""ProposalDoc -> .docx. Bengali is a complex script in Word, so every run also carries the complex-script font and size."""

from __future__ import annotations

import io

from docx import Document
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Mm, Pt, RGBColor

from app.docs.html import _safe_href  # noqa: F401  (same URL rule as the PDF)
from app.docs.models import ProposalDoc

LATIN_FONT = "Calibri"
CS_FONT = (
    "Nirmala UI"  # ships with Windows; LibreOffice/Word fall back to Noto Sans Bengali if installed
)


def _rgb(hexcolor: str) -> RGBColor:
    return RGBColor.from_string(hexcolor.lstrip("#").upper())


def _font(run, size: float, bold: bool = False, color: str | None = None) -> None:  # type: ignore[no-untyped-def]
    run.bold = bold
    run.font.size = Pt(size)
    if color:
        run.font.color.rgb = _rgb(color)
    rpr = run._r.get_or_add_rPr()
    fonts = rpr.find(qn("w:rFonts"))
    if fonts is None:
        fonts = OxmlElement("w:rFonts")
        rpr.append(fonts)
    for attr, name in (("w:ascii", LATIN_FONT), ("w:hAnsi", LATIN_FONT), ("w:cs", CS_FONT)):
        fonts.set(qn(attr), name)
    for tag in ("w:szCs", "w:bCs"):
        el = OxmlElement(tag)
        el.set(qn("w:val"), str(int(size * 2)) if tag == "w:szCs" else ("1" if bold else "0"))
        rpr.append(el)
    lang = OxmlElement("w:lang")
    lang.set(qn("w:bidi"), "bn-BD")
    rpr.append(lang)


def _shade(cell, hexcolor: str) -> None:  # type: ignore[no-untyped-def]
    pr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), hexcolor.lstrip("#").upper())
    pr.append(shd)


def _text_block(d, text: str) -> None:  # type: ignore[no-untyped-def]
    import re

    for block in re.split(r"\n\s*\n", text.strip()):
        lines = [ln.rstrip() for ln in block.split("\n") if ln.strip()]
        if not lines:
            continue
        if all(re.match(r"\s*([-•*])\s+", ln) for ln in lines):
            for ln in lines:
                p = d.add_paragraph(style="List Bullet")
                _font(p.add_run(re.sub(r"^\s*[-•*]\s+", "", ln)), 11)
        else:
            p = d.add_paragraph()
            for i, ln in enumerate(lines):
                r = p.add_run(ln)
                _font(r, 11)
                if i < len(lines) - 1:
                    r.add_break()


def build_docx(doc: ProposalDoc) -> bytes:
    d = Document()
    sec = d.sections[0]
    sec.page_width, sec.page_height = Mm(210), Mm(297)
    sec.left_margin = sec.right_margin = Mm(18)
    sec.top_margin, sec.bottom_margin = Mm(18), Mm(18)
    b = doc.brand

    head = d.add_paragraph()
    if b.logo_bytes:
        head.add_run().add_picture(io.BytesIO(b.logo_bytes), height=Mm(14))
        head.add_run("   ")
    _font(head.add_run(b.name), 13, True, b.primary)

    t = d.add_paragraph()
    _font(t.add_run(doc.title), 24, True, b.primary)
    if doc.client_name:
        p = d.add_paragraph()
        _font(p.add_run(f"{doc.label('prepared_for')}: "), 10.5)
        _font(p.add_run(doc.client_name), 10.5, True)
    if doc.date_text:
        p = d.add_paragraph()
        _font(p.add_run(f"{doc.label('date')}: {doc.date_text}"), 10.5)

    def heading(text: str) -> None:
        p = d.add_paragraph()
        p.paragraph_format.space_before = Pt(14)
        p.paragraph_format.keep_with_next = True
        _font(p.add_run(text), 15, True, b.primary)

    for s in doc.sections:
        if s.heading:
            heading(s.heading)
        _text_block(d, s.body)

    if doc.pricing and (doc.pricing.rows or doc.pricing.totals):
        heading(doc.label("pricing"))
        pr = doc.pricing
        table = d.add_table(rows=1, cols=4)
        table.style = "Table Grid"
        table.alignment = WD_TABLE_ALIGNMENT.CENTER
        for i, key in enumerate(("item", "qty", "unit_price", "amount")):
            c = table.rows[0].cells[i]
            _shade(c, b.primary)
            c.paragraphs[0].text = ""
            _font(c.paragraphs[0].add_run(doc.label(key)), 10, True, "#ffffff")
        for r in pr.rows:
            cells = table.add_row().cells
            for i, val in enumerate((r.name, r.qty, r.unit_price, r.total)):
                _font(cells[i].paragraphs[0].add_run(val), 10)
                if i:
                    cells[i].paragraphs[0].alignment = WD_ALIGN_PARAGRAPH.RIGHT
        for tl in pr.totals:
            cells = table.add_row().cells
            cells[0].merge(cells[2])
            _font(cells[0].paragraphs[0].add_run(tl.label), 11 if tl.strong else 10, tl.strong)
            cells[0].paragraphs[0].alignment = WD_ALIGN_PARAGRAPH.RIGHT
            _font(cells[3].paragraphs[0].add_run(tl.value), 11 if tl.strong else 10, tl.strong)
            cells[3].paragraphs[0].alignment = WD_ALIGN_PARAGRAPH.RIGHT
        if pr.note:
            p = d.add_paragraph()
            _font(p.add_run(pr.note), 9.5)

    if doc.portfolio:
        heading(doc.label("portfolio"))
        for w in doc.portfolio:
            p = d.add_paragraph()
            _font(p.add_run(w.title), 12, True)
            if w.url and _safe_href(w.url):
                _font(p.add_run(f"  {w.url}"), 9.5, False, b.accent)
            if w.description:
                _font(d.add_paragraph().add_run(w.description), 10)

    contact = [x for x in (b.email, b.phone, b.website, b.address) if x]
    if contact:
        heading(doc.label("contact"))
        p = d.add_paragraph()
        for i, line in enumerate(contact):
            run = p.add_run(line)
            _font(run, 10.5)
            if i < len(contact) - 1:
                run.add_break()

    out = io.BytesIO()
    d.save(out)
    return out.getvalue()
