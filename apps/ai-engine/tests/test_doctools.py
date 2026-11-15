"""Document and photo tools: real files in, real files out."""

import base64
import io
import json
import zipfile

import pypdfium2 as pdfium
import pytest
from PIL import Image, ImageDraw
from pypdf import PdfReader

from app.doctools import ops
from tests.conftest import make_headers


def img(size=(600, 400), color=(120, 150, 200), fmt="JPEG"):
    out = io.BytesIO()
    Image.new("RGB", size, color).save(out, fmt)
    return out.getvalue()


def portrait():
    im = Image.new("RGB", (800, 1000), (200, 220, 240))
    d = ImageDraw.Draw(im)
    d.ellipse((300, 250, 500, 500), fill=(180, 120, 90))
    d.rectangle((250, 500, 550, 1000), fill=(30, 30, 90))
    out = io.BytesIO()
    im.save(out, "JPEG")
    return out.getvalue()


def pdf(pages=3):
    ims = [Image.new("RGB", (400, 500), (255 - 40 * i, 255, 255)) for i in range(pages)]
    out = io.BytesIO()
    ims[0].save(out, "PDF", save_all=True, append_images=ims[1:])
    return out.getvalue()


def run(op, files, **p):
    return ops.run(op, [(f"f{i}", b) for i, b in enumerate(files)], p)


def test_passport_photo_has_the_exact_size_in_pixels():
    data, mime, ext = run("passport_photo", [portrait()], size="35x45")
    im = Image.open(io.BytesIO(data))
    assert im.size == (ops.mm(35), ops.mm(45)) and ext == "jpg"
    assert Image.open(io.BytesIO(run("passport_photo", [portrait()], size="2x2in")[0])).size == (600, 600)
    assert Image.open(io.BytesIO(run("passport_photo", [portrait()], size="custom", width_mm=40, height_mm=50)[0])).size == (ops.mm(40), ops.mm(50))


def test_photo_sheet_fits_several_copies_and_stays_inside_the_paper():
    data, _, _ = run("photo_sheet", [portrait()], size="35x45", paper="4r", format="png")
    sheet = Image.open(io.BytesIO(data))
    assert sheet.size in {(1200, 1800), (1800, 1200)}
    four, _, _ = run("photo_sheet", [portrait()], size="35x45", paper="a4", copies=4)
    assert Image.open(io.BytesIO(four)).size == (2480, 3508)
    with pytest.raises(ops.ToolError):
        run("photo_sheet", [portrait()], size="custom", width_mm=190, height_mm=290, paper="4r")


def test_background_remove_clears_the_wall_but_keeps_the_person():
    out, mime, _ = run("bg_remove", [portrait()], tolerance=40)
    assert mime == "image/png"
    rgba = Image.open(io.BytesIO(out))
    assert rgba.getpixel((10, 10))[3] < 20 and rgba.getpixel((400, 380))[3] > 235
    blue, _, _ = run("bg_color", [portrait()], color="#ffffff")
    assert Image.open(io.BytesIO(blue)).getpixel((10, 10))[0] > 240


def test_resize_to_a_file_size_limit():
    big = Image.effect_noise((1600, 1200), 60).convert("RGB")
    out = io.BytesIO()
    big.save(out, "JPEG", quality=95)
    data, _, _ = run("image_resize", [out.getvalue()], percent=100, max_kb=100)
    assert len(data) <= 100 * 1024


def test_crop_rotate_and_bad_values():
    assert Image.open(io.BytesIO(run("image_crop", [img()], left=10, right=10)[0])).size == (480, 400)
    assert Image.open(io.BytesIO(run("image_rotate", [img()], degrees=90)[0])).size == (400, 600)
    with pytest.raises(ops.ToolError):
        run("image_crop", [img()], left=100)
    with pytest.raises(ops.ToolError):
        run("image_resize", [img()])
    with pytest.raises(ops.ToolError):
        run("image_rotate", [b"not an image"])


def test_cleanup_whitens_paper_and_keeps_ink():
    page = Image.new("RGB", (500, 400), (205, 200, 185))  # grey-yellow paper
    ImageDraw.Draw(page).rectangle((100, 100, 140, 300), fill=(20, 20, 20))
    out = io.BytesIO()
    page.save(out, "PNG")
    r = Image.open(io.BytesIO(run("doc_cleanup", [out.getvalue()], mode="bw", format="png")[0])).convert("L")
    assert r.getpixel((400, 50)) == 255 and r.getpixel((120, 200)) < 60


def test_nid_front_back_on_one_a4_page_as_pdf():
    data, mime, ext = run("scan", [img((856, 540)), img((856, 540), (200, 100, 100))], kind="nid")
    r = PdfReader(io.BytesIO(data))
    assert mime == "application/pdf" and len(r.pages) == 1
    w, h = float(r.pages[0].mediabox.width), float(r.pages[0].mediabox.height)
    assert abs(w - 595) < 3 and abs(h - 842) < 3
    jpg, _, _ = run("scan", [img((856, 540))], kind="passport", format="jpeg")
    assert Image.open(io.BytesIO(jpg)).size == (2480, 3508)


def test_multiple_documents_on_one_page_and_overflow_to_next():
    data, _, _ = run("multi_on_page", [img() for _ in range(5)], per_page=4, format="pdf")
    assert len(PdfReader(io.BytesIO(data)).pages) == 2
    with pytest.raises(ops.ToolError):
        run("multi_on_page", [img()], per_page=5)


def test_images_to_pdf_and_back_to_images():
    data, _, _ = run("images_to_pdf", [img(), img((300, 500))])
    assert len(PdfReader(io.BytesIO(data)).pages) == 2
    z, mime, ext = run("pdf_to_images", [data], dpi=72)
    assert ext == "zip" and sorted(zipfile.ZipFile(io.BytesIO(z)).namelist()) == ["page-001.jpg", "page-002.jpg"]
    one, _, ext = run("pdf_to_images", [data], dpi=72, pages="2", format="png")
    assert ext == "png"


def test_merge_split_rotate_crop_compress():
    a, b = pdf(2), pdf(3)
    merged, _, _ = run("pdf_merge", [a, b])
    assert len(PdfReader(io.BytesIO(merged)).pages) == 5
    parts = zipfile.ZipFile(io.BytesIO(run("pdf_split", [merged], pages="1-2,5")[0]))
    assert len(parts.namelist()) == 2 and len(PdfReader(io.BytesIO(parts.read(parts.namelist()[0]))).pages) == 2
    assert len(zipfile.ZipFile(io.BytesIO(run("pdf_split", [merged])[0])).namelist()) == 5
    rot = PdfReader(io.BytesIO(run("pdf_rotate", [a], degrees=90, pages="1")[0]))
    assert rot.pages[0].rotation == 90 and rot.pages[1].rotation == 0
    cropped = PdfReader(io.BytesIO(run("pdf_crop", [a], left=10, right=10, top=5, bottom=5)[0]))
    assert abs(float(cropped.pages[0].mediabox.width) - 0.8 * 400) < 25  # pypdf keeps the original page size under the box
    for level in ("low", "medium", "high"):
        out, _, _ = run("pdf_compress", [merged], level=level)
        assert len(PdfReader(io.BytesIO(out)).pages) == 5
    with pytest.raises(ops.ToolError):
        run("pdf_split", [a], pages="9")
    with pytest.raises(ops.ToolError):
        run("pdf_merge", [a])
    with pytest.raises(ops.ToolError):
        run("pdf_merge", [a, b"nope"])


def test_compress_makes_a_heavy_scan_smaller():
    noisy = Image.effect_noise((1700, 2200), 40).convert("RGB")
    out = io.BytesIO()
    noisy.save(out, "PDF", resolution=200.0, quality=98)
    small, _, _ = run("pdf_compress", [out.getvalue()], level="high")
    assert len(small) < len(out.getvalue())
    assert pdfium.PdfDocument(small)[0].get_size()[0] > 100


def test_office_file_to_pdf():
    shutil = pytest.importorskip("shutil")
    if not (shutil.which("soffice") or shutil.which("libreoffice")):
        pytest.skip("LibreOffice not installed")
    data, mime, _ = ops.run("doc_to_pdf", [("note.txt", "Hello report".encode())], {})
    assert PdfReader(io.BytesIO(data)).pages
    with pytest.raises(ops.ToolError):
        ops.run("doc_to_pdf", [("x.exe", b"MZ")], {})


def test_route_is_signed_and_returns_the_file(client):
    body = json.dumps({"op": "image_rotate", "files": [{"name": "a.jpg", "b64": base64.b64encode(img()).decode()}], "params": {"degrees": 90}}).encode()
    path = "/internal/v1/doctools/run"
    assert client.post(path, content=body).status_code in (401, 403)
    r = client.post(path, content=body, headers=make_headers(method="POST", path=path, body=body) | {"content-type": "application/json"})
    assert r.status_code == 200 and r.headers["x-file-ext"] == "jpg"
    bad = json.dumps({"op": "image_crop", "files": [{"name": "a.jpg", "b64": base64.b64encode(img()).decode()}], "params": {"left": 100}}).encode()
    r = client.post(path, content=bad, headers=make_headers(method="POST", path=path, body=bad) | {"content-type": "application/json"})
    assert r.status_code == 422 and r.json()["error"] == "tool_error"


# ───────────────────────────────────────────────────────────────────────── v0.111 tools
def text_png(text="Hello World 12345", size=(1300, 260)):
    from app.studio import compose as C

    im = Image.new("RGB", size, "white")
    C.draw_text(ImageDraw.Draw(im), (30, 60), text, 110, True, (0, 0, 0, 255))
    out = io.BytesIO()
    im.save(out, "PNG")
    return out.getvalue()


def test_english_ocr_reads_printed_text():
    import shutil

    if not shutil.which("tesseract"):
        pytest.skip("tesseract not installed")
    data, mime, ext = run("ocr", [text_png()], lang="eng")
    assert ext == "txt" and "Hello" in data.decode() and "12345" in data.decode()
    docx, _, ext = run("image_to_word", [text_png()], lang="eng")
    assert ext == "docx" and zipfile.is_zipfile(io.BytesIO(docx))


def test_missing_language_data_is_a_clear_message():
    import shutil

    if not shutil.which("tesseract"):
        pytest.skip("tesseract not installed")
    from app.doctools import extra

    if "ben" in extra._tess_langs():
        pytest.skip("Bangla data present")
    with pytest.raises(ops.ToolError, match="not installed"):
        run("ocr", [text_png()], lang="ben")


def test_pdf_to_word_and_excel_keep_the_text():
    from docx import Document
    from openpyxl import load_workbook
    from reportlab.pdfgen import canvas

    buf = io.BytesIO()
    c = canvas.Canvas(buf)
    c.drawString(72, 750, "Item        Qty        Price")
    c.drawString(72, 730, "Cashew      10         450.50")
    c.save()
    word, _, ext = run("pdf_to_word", [buf.getvalue()])
    assert ext == "docx" and "Cashew" in "\n".join(p.text for p in Document(io.BytesIO(word)).paragraphs)
    xl, _, ext = run("pdf_to_excel", [buf.getvalue()])
    ws = load_workbook(io.BytesIO(xl))["Page 1"]
    rows = [[c.value for c in r] for r in ws.iter_rows()]
    assert rows[1][0] == "Cashew" and rows[1][1] == 10 and rows[1][2] == 450.5
    with pytest.raises(ops.ToolError):
        run("pdf_to_excel", [pdf(1)])  # a picture-only PDF has no text


def test_text_to_docx_and_extract_text_round_trip():
    docx, _, _ = run("text_to_docx", [], text="প্রথম অনুচ্ছেদ\n\nSecond paragraph")
    txt, _, _ = ops.run("extract_text", [("a.docx", docx)], {})
    assert "প্রথম" in txt.decode() and "Second" in txt.decode()


def test_enhance_enlarges_and_keeps_the_picture():
    out, _, _ = run("enhance", [img((300, 200))], scale=2)
    assert Image.open(io.BytesIO(out)).size == (600, 400)
    with pytest.raises(ops.ToolError):
        run("enhance", [img()], scale=5)


def test_qr_code_decodes_back_to_its_text_and_validates():
    out, mime, _ = run("qr", [], text="https://example.com/pay?id=42", size=600, color="#1f3a8a")
    im = Image.open(io.BytesIO(out))
    assert im.size == (600, 600) and mime == "image/png"
    cv2 = pytest.importorskip("cv2")
    import numpy as np

    got, _, _ = cv2.QRCodeDetector().detectAndDecode(cv2.cvtColor(np.array(im.convert("RGB")), cv2.COLOR_RGB2BGR))
    assert got == "https://example.com/pay?id=42"
    with pytest.raises(ops.ToolError):
        run("qr", [], text="")


def test_barcodes_and_bad_values():
    out, _, _ = run("barcode", [], type="code128", text="CANAI-2026")
    assert Image.open(io.BytesIO(out)).width > 200
    ean, _, _ = run("barcode", [], type="ean13", text="590123412345")
    assert Image.open(io.BytesIO(ean)).height > 100
    with pytest.raises(ops.ToolError):
        run("barcode", [], type="ean13", text="abc")
    with pytest.raises(ops.ToolError):
        run("barcode", [], type="nope", text="1")


def test_signature_from_photo_and_from_typed_name():
    sheet = Image.new("RGB", (600, 250), "white")
    ImageDraw.Draw(sheet).line((40, 180, 300, 60, 560, 190), fill=(10, 10, 80), width=8)
    buf = io.BytesIO()
    sheet.save(buf, "JPEG")
    out, mime, _ = run("signature", [buf.getvalue()], color="#000000", width=400)
    sig = Image.open(io.BytesIO(out))
    assert mime == "image/png" and sig.mode == "RGBA" and sig.width == 400 and sig.getpixel((2, 2))[3] == 0
    typed, _, _ = run("signature", [], text="Rahim Uddin")
    assert Image.open(io.BytesIO(typed)).getchannel("A").getbbox() is not None
    bangla, _, _ = run("signature", [], text="রহিম উদ্দিন")
    assert Image.open(io.BytesIO(bangla)).width > 50
    with pytest.raises(ops.ToolError):
        run("signature", [], text="")


def test_watermark_on_picture_and_on_pdf():
    plain = img((800, 600), (255, 255, 255))
    out, _, _ = run("watermark", [plain], text="CONFIDENTIAL", opacity=60)
    wm = Image.open(io.BytesIO(out)).convert("L")
    assert min(wm.getdata()) < 200  # something was drawn over the white page
    pdf_out, mime, _ = ops.run("watermark", [("a.pdf", pdf(2))], {"text": "DRAFT"})
    assert mime == "application/pdf" and len(PdfReader(io.BytesIO(pdf_out)).pages) == 2
    with pytest.raises(ops.ToolError):
        run("watermark", [plain], text="")


def test_id_card_and_business_card_have_card_size_and_bangla_text():
    face = portrait()
    out, mime, _ = run("id_card", [face], name="মোঃ রহিম উদ্দিন", title="Manager", org="CANAI Ltd", id_no="A-1024", phone="01700-000000", blood="B+", address="House 5, Road 3, Dhaka")
    im = Image.open(io.BytesIO(out))
    assert im.size == (1011, 638) and mime == "image/png"
    assert len(set(im.convert("RGB").resize((60, 40)).getdata())) > 8  # not blank
    pdf_out, mime, _ = run("id_card", [], name="Karim", format="pdf")
    page = PdfReader(io.BytesIO(pdf_out)).pages[0]
    assert abs(float(page.mediabox.width) / 72 * 25.4 - 85.6) < 1
    bc, _, _ = run("business_card", [], name="Rahim", title="CEO", company="GoldVest", phone="017", email="a@b.com", website="goldvest.example", address="Dhaka")
    assert Image.open(io.BytesIO(bc)).size == (1063, 638)
    with pytest.raises(ops.ToolError):
        run("id_card", [], name="")


def test_a_tool_that_needs_a_file_says_so():
    with pytest.raises(ops.ToolError):
        ops.run("enhance", [], {})
