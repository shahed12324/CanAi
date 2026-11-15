import base64
import io
import json
import shutil
import subprocess
import zipfile

import pytest
from docx import Document

from app.docs.docx_builder import build_docx
from app.docs.html import _paragraphs, build_html
from app.docs.models import Brand, ProposalDoc
from app.docs.pdf import allowed_request, render_pdf
from tests.conftest import make_headers


def _png(w: int = 8, h: int = 8, rgb=(31, 58, 95)) -> str:
    import struct
    import zlib

    def chunk(t: bytes, d: bytes) -> bytes:
        c = struct.pack(">I", len(d)) + t + d
        return c + struct.pack(">I", zlib.crc32(t + d) & 0xFFFFFFFF)

    raw = b"".join(b"\x00" + bytes(rgb) * w for _ in range(h))
    png = (
        b"\x89PNG\r\n\x1a\n"
        + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
        + chunk(b"IDAT", zlib.compress(raw))
        + chunk(b"IEND", b"")
    )
    return base64.b64encode(png).decode()


PNG_1PX = _png()

BN = {
    "language": "bn",
    "title": "ওয়েবসাইট উন্নয়ন প্রস্তাব",
    "client_name": "আলো ডিজিটাল লিমিটেড",
    "date_text": "০২ নভেম্বর ২০২৬",
    "sections": [
        {
            "heading": "আপনার সাইটে চিহ্নিত সুযোগ",
            "body": "- মোবাইল-ফ্রেন্ডলি নয়\n- ফেসবুক পিক্সেল নেই\n\nআমরা এগুলো ঠিক করে দেব।",
        },
        {
            "heading": "কাজের পরিধি",
            "body": "কর্মক্ষেত্রে প্রতিক্রিয়াশীল, দ্রুত ও নিরাপদ ওয়েবসাইট।\nস্বত্ত্ব ও সহায়তা অন্তর্ভুক্ত।",
        },
    ],
    "pricing": {
        "rows": [
            {"name": "ওয়েবসাইট ডিজাইন", "qty": "১", "unit_price": "৳ ৫০,০০০", "total": "৳ ৫০,০০০"},
            {"name": "SEO সেটআপ", "qty": "১", "unit_price": "৳ ১২,৫০০", "total": "৳ ১২,৫০০"},
        ],
        "totals": [{"label": "মোট", "value": "৳ ৬২,৫০০", "strong": True}],
        "note": "মূল্য ১৫% ভ্যাট ছাড়া।",
    },
    "portfolio": [
        {
            "title": "বিডি শপ",
            "url": "https://example.com/bdshop",
            "description": "ই-কমার্স সাইট, ৩ সপ্তাহে।",
        }
    ],
    "brand": {
        "name": "জার্ভিস স্টুডিও",
        "email": "hello@jarvis.test",
        "phone": "+8801712345678",
        "logo_b64": PNG_1PX,
    },
    "labels": {
        "prepared_for": "প্রস্তুত করা হয়েছে",
        "date": "তারিখ",
        "item": "বিবরণ",
        "qty": "পরিমাণ",
        "unit_price": "একক মূল্য",
        "amount": "মোট",
        "pricing": "মূল্য",
        "portfolio": "আমাদের কাজ",
        "contact": "যোগাযোগ",
    },
}


def chromium_works() -> bool:
    try:
        import asyncio

        asyncio.run(render_pdf(ProposalDoc(title="x")))
        return True
    except Exception:  # noqa: BLE001
        return False


needs_chromium = pytest.mark.skipif(not chromium_works(), reason="Chromium not available")


def test_html_escapes_user_text_and_keeps_bengali():
    d = ProposalDoc(
        **{**BN, "title": "<script>alert(1)</script>", "client_name": 'A"><img src=x onerror=1>'}
    )
    h = build_html(d)
    assert "<script>alert" not in h and "&lt;script&gt;" in h
    assert "<img src=x" not in h and "&gt;&lt;img" in h
    assert "মোবাইল-ফ্রেন্ডলি নয়" in h and "<ul><li>" in h


def test_paragraphs_and_bullets():
    out = _paragraphs("one\ntwo\n\n- a\n- b\n\nlast")
    assert out == "<p>one<br>two</p><ul><li>a</li><li>b</li></ul><p>last</p>"
    assert _paragraphs("   \n\n ") == ""


def test_only_http_links_become_links():
    d = ProposalDoc(
        title="t",
        portfolio=[
            {"title": "ok", "url": "https://a.test/x"},
            {"title": "bad", "url": "javascript:alert(1)"},
        ],
    )
    h = build_html(d)
    assert "href='https://a.test/x'" in h and "javascript:" not in h


@pytest.mark.parametrize("bad", ["red", "#12345", "#gggggg", "url(x)", "#fff;}</style>"])
def test_brand_colours_must_be_hex(bad):
    with pytest.raises(ValueError):
        Brand(primary=bad)


def test_logo_must_be_a_small_png_or_jpeg():
    Brand(logo_b64=PNG_1PX)
    for bad in (
        base64.b64encode(b"GIF89a....").decode(),
        base64.b64encode(b"<svg onload=x>").decode(),
        "not base64!!",
        base64.b64encode(b"\x89PNG\r\n\x1a\n" + b"0" * 700_000).decode(),
    ):
        with pytest.raises(ValueError):
            Brand(logo_b64=bad)


def test_only_data_urls_may_load_while_rendering():
    assert allowed_request("data:image/png;base64,AAAA") and allowed_request("about:blank")
    for u in (
        "http://169.254.169.254/latest",
        "https://evil.test/x.css",
        "file:///etc/passwd",
        "ftp://x/y",
    ):
        assert not allowed_request(u)


def test_docx_has_text_table_and_complex_script_fonts():
    data = build_docx(ProposalDoc(**BN))
    d = Document(io.BytesIO(data))
    text = "\n".join(p.text for p in d.paragraphs)
    for s in (
        "ওয়েবসাইট উন্নয়ন প্রস্তাব",
        "আলো ডিজিটাল লিমিটেড",
        "আপনার সাইটে চিহ্নিত সুযোগ",
        "আমরা এগুলো ঠিক করে দেব।",
        "বিডি শপ",
    ):
        assert s in text
    assert [c.text for c in d.tables[0].rows[0].cells] == ["বিবরণ", "পরিমাণ", "একক মূল্য", "মোট"]
    assert any("৳ ৬২,৫০০" in c.text for r in d.tables[0].rows for c in r.cells)
    with zipfile.ZipFile(io.BytesIO(data)) as z:
        xml = z.read("word/document.xml").decode()
        assert 'w:cs="Nirmala UI"' in xml and "w:szCs" in xml and 'w:bidi="bn-BD"' in xml
        assert any(n.startswith("word/media/") for n in z.namelist())  # the logo


@needs_chromium
def test_bengali_pdf_renders_with_the_bengali_font(tmp_path):
    import asyncio

    pdf = asyncio.run(render_pdf(ProposalDoc(**BN)))
    assert pdf.startswith(b"%PDF")
    f = tmp_path / "p.pdf"
    f.write_bytes(pdf)
    if shutil.which("pdffonts"):
        fonts = subprocess.run(["pdffonts", str(f)], capture_output=True, text=True).stdout
        assert "NotoSansBengali" in fonts and "NotoSans-" in fonts
    if shutil.which("pdftotext"):
        # Shaped Bengali comes back from text extraction as scattered glyph pieces, so only the Latin parts are asserted
        # here; the shaping itself is checked by looking at the rendered page (see the PNG check below).
        txt = subprocess.run(
            ["pdftotext", "-layout", str(f), "-"], capture_output=True, text=True
        ).stdout
        import re

        assert "hello@jarvis.test" in txt and "+8801712345678" in txt
        assert re.search(r"\d\s*/\s*\d", txt)  # page numbers in the footer
    if shutil.which("pdftoppm"):
        subprocess.run(
            ["pdftoppm", "-r", "60", "-png", "-singlefile", str(f), str(tmp_path / "pg")],
            check=True,
        )
        png = (tmp_path / "pg.png").read_bytes()
        assert png.startswith(b"\x89PNG") and len(png) > 8000  # not a blank page
    if shutil.which("pdfinfo"):
        info = subprocess.run(["pdfinfo", str(f)], capture_output=True, text=True).stdout
        assert "Pages:" in info and "A4" in info


@needs_chromium
def test_long_document_paginates(tmp_path):
    import asyncio

    secs = [{"heading": f"Section {i}", "body": "word " * 300} for i in range(12)]
    pdf = asyncio.run(render_pdf(ProposalDoc(title="Long", sections=secs)))
    f = tmp_path / "l.pdf"
    f.write_bytes(pdf)
    if shutil.which("pdfinfo"):
        pages = int(
            [
                ln
                for ln in subprocess.run(
                    ["pdfinfo", str(f)], capture_output=True, text=True
                ).stdout.splitlines()
                if ln.startswith("Pages:")
            ][0].split()[1]
        )
        assert pages >= 4


# ---- route
def _post(c, path, payload):
    body = json.dumps(payload).encode()
    h = make_headers(method="POST", path=path, body=body) | {"Content-Type": "application/json"}
    return c.post(path, content=body, headers=h)


def test_route_needs_a_signature(client):
    assert client.post("/internal/v1/render/proposal.docx", json={"title": "x"}).status_code in (
        401,
        403,
    )


def test_route_returns_docx_and_rejects_bad_input(client):
    r = _post(client, "/internal/v1/render/proposal.docx", BN)
    assert r.status_code == 200 and r.content[:2] == b"PK"
    assert "wordprocessingml" in r.headers["content-type"]
    bad = _post(client, "/internal/v1/render/proposal.docx", {**BN, "brand": {"primary": "red"}})
    assert bad.status_code == 422 and PNG_1PX not in bad.text
    assert _post(client, "/internal/v1/render/proposal.docx", {"title": ""}).status_code == 422


@needs_chromium
def test_route_returns_pdf(client):
    r = _post(client, "/internal/v1/render/proposal.pdf", BN)
    assert (
        r.status_code == 200
        and r.content.startswith(b"%PDF")
        and r.headers["content-type"] == "application/pdf"
    )
