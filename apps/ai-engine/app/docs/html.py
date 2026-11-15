"""ProposalDoc -> one self-contained HTML page (fonts inlined, no external requests). All user text is escaped."""

from __future__ import annotations

import base64
import re
from functools import lru_cache
from html import escape
from pathlib import Path
from urllib.parse import urlsplit

from app.docs.models import ProposalDoc

FONT_DIR = Path(__file__).parent / "fonts"
# unicode-range keeps Bengali glyphs on the Bengali face and everything else on Noto Sans.
FACES = [
    ("Doc Sans", "NotoSansBengali-400.woff2", 400, "U+0980-09FF,U+200C-200D,U+25CC"),
    ("Doc Sans", "NotoSansBengali-700.woff2", 700, "U+0980-09FF,U+200C-200D,U+25CC"),
    (
        "Doc Sans",
        "NotoSans-latin-400.woff2",
        400,
        "U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD",
    ),
    (
        "Doc Sans",
        "NotoSans-latin-700.woff2",
        700,
        "U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD",
    ),
]


@lru_cache(maxsize=1)
def font_css() -> str:
    out = []
    for family, fname, weight, rng in FACES:
        data = base64.b64encode((FONT_DIR / fname).read_bytes()).decode()
        out.append(
            f"@font-face{{font-family:'{family}';font-weight:{weight};font-style:normal;"
            f"src:url(data:font/woff2;base64,{data}) format('woff2');unicode-range:{rng};}}"
        )
    return "".join(out)


def _paragraphs(text: str) -> str:
    """Blank line = new paragraph; lines starting with '- ' or '• ' form a bullet list."""
    html: list[str] = []
    for block in re.split(r"\n\s*\n", text.strip()):
        lines = [ln.rstrip() for ln in block.split("\n") if ln.strip()]
        if not lines:
            continue
        if all(re.match(r"\s*([-•*])\s+", ln) for ln in lines):
            items = "".join(f"<li>{escape(re.sub(r'^\s*[-•*]\s+', '', ln))}</li>" for ln in lines)
            html.append(f"<ul>{items}</ul>")
        else:
            html.append("<p>" + "<br>".join(escape(ln) for ln in lines) + "</p>")
    return "".join(html)


def _safe_href(url: str) -> str | None:
    p = urlsplit(url.strip())
    return url.strip() if p.scheme in ("http", "https") and p.netloc else None


def build_html(doc: ProposalDoc) -> str:
    b = doc.brand
    logo = ""
    if b.logo_b64:
        logo = f'<img class="logo" alt="" src="data:{b.logo_mime};base64,{b.logo_b64}">'
    contact = [x for x in (b.email, b.phone, b.website, b.address) if x]

    sections = "".join(
        f"<section>{'<h2>' + escape(s.heading) + '</h2>' if s.heading else ''}{_paragraphs(s.body)}</section>"
        for s in doc.sections
    )

    pricing = ""
    if doc.pricing and (doc.pricing.rows or doc.pricing.totals):
        rows = "".join(
            f"<tr><td>{escape(r.name)}</td><td class='n'>{escape(r.qty)}</td>"
            f"<td class='n'>{escape(r.unit_price)}</td><td class='n'>{escape(r.total)}</td></tr>"
            for r in doc.pricing.rows
        )
        totals = "".join(
            f"<tr class='t{' strong' if t.strong else ''}'><td colspan='3' class='n'>{escape(t.label)}</td>"
            f"<td class='n'>{escape(t.value)}</td></tr>"
            for t in doc.pricing.totals
        )
        note = f"<p class='note'>{escape(doc.pricing.note)}</p>" if doc.pricing.note else ""
        pricing = (
            f"<section class='keep'><h2>{escape(doc.label('pricing'))}</h2><table><thead><tr>"
            f"<th>{escape(doc.label('item'))}</th><th class='n'>{escape(doc.label('qty'))}</th>"
            f"<th class='n'>{escape(doc.label('unit_price'))}</th><th class='n'>{escape(doc.label('amount'))}</th>"
            f"</tr></thead><tbody>{rows}{totals}</tbody></table>{note}</section>"
        )

    portfolio = ""
    if doc.portfolio:
        items = []
        for p in doc.portfolio:
            href = _safe_href(p.url)
            title = (
                f"<a href='{escape(href, quote=True)}'>{escape(p.title)}</a>"
                if href
                else escape(p.title)
            )
            desc = f"<p>{escape(p.description)}</p>" if p.description else ""
            items.append(f"<div class='work'><h3>{title}</h3>{desc}</div>")
        portfolio = f"<section><h2>{escape(doc.label('portfolio'))}</h2>{''.join(items)}</section>"

    footer = ""
    if contact:
        footer = f"<section class='keep contact'><h2>{escape(doc.label('contact'))}</h2><p>{'<br>'.join(escape(c) for c in contact)}</p></section>"

    prepared = ""
    if doc.client_name or doc.date_text:
        prepared = "<div class='meta'>"
        if doc.client_name:
            prepared += f"<div><span>{escape(doc.label('prepared_for'))}</span><strong>{escape(doc.client_name)}</strong></div>"
        if doc.date_text:
            prepared += f"<div><span>{escape(doc.label('date'))}</span><strong>{escape(doc.date_text)}</strong></div>"
        prepared += "</div>"

    return f"""<!doctype html><html lang="{doc.language}"><head><meta charset="utf-8"><title>{escape(doc.title)}</title><style>
{font_css()}
@page {{ size: A4; margin: 18mm 16mm 20mm; }}
*{{box-sizing:border-box}}
body{{font-family:'Doc Sans',sans-serif;font-size:11pt;line-height:1.65;color:#1b1f24;margin:0}}
.band{{background:{b.primary};color:#fff;padding:5mm 7mm;margin:0 0 8mm;border-radius:2mm;display:flex;align-items:center;justify-content:space-between;gap:12mm}}
.band .brand{{font-size:13pt;font-weight:700}}
.logo{{max-height:16mm;max-width:50mm;object-fit:contain;background:#fff;padding:2mm;border-radius:2mm}}
h1{{font-size:24pt;line-height:1.3;margin:0 0 4mm;color:{b.primary}}}
h2{{font-size:15pt;margin:7mm 0 2mm;color:{b.primary};border-bottom:0.6mm solid {b.accent};padding-bottom:1mm}}
h3{{font-size:12pt;margin:3mm 0 0}}
a{{color:{b.accent};text-decoration:none}}
p{{margin:0 0 3mm}} ul{{margin:0 0 3mm;padding-left:6mm}} li{{margin-bottom:1mm}}
.meta{{display:flex;gap:14mm;margin:4mm 0 2mm;font-size:10.5pt}} .meta span{{display:block;color:#666;font-size:9pt}}
table{{width:100%;border-collapse:collapse;margin-top:3mm}}
th{{background:{b.primary};color:#fff;text-align:left;padding:2mm 3mm;font-size:10pt}}
td{{padding:2mm 3mm;border-bottom:0.3mm solid #d9dde2;vertical-align:top}}
.n{{text-align:right;white-space:nowrap}}
tr.t td{{border-bottom:none;padding-top:1mm;padding-bottom:1mm}} tr.strong td{{font-weight:700;font-size:12pt;color:{b.primary};border-top:0.5mm solid {b.accent}}}
.note{{font-size:9.5pt;color:#555;margin-top:3mm}}
.work{{margin-bottom:3mm}} .work p{{margin:0;color:#444;font-size:10pt}}
.keep,tr,.work{{break-inside:avoid}} h2{{break-after:avoid}}
.contact{{margin-top:8mm}}
</style></head><body>
<div class="band"><div class="brand">{escape(b.name)}</div>{logo}</div>
<h1>{escape(doc.title)}</h1>{prepared}{sections}{pricing}{portfolio}{footer}
</body></html>"""
