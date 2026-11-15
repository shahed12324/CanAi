"""More tools: OCR, Word/Excel conversion, enhancement, QR/barcode, signature, watermark, ID and business cards.
Same contract as ops.py: bytes in, (bytes, mime, extension) out."""

from __future__ import annotations

import io
import re
import shutil
import subprocess  # noqa: S404
import tempfile
from collections.abc import Callable
from functools import lru_cache
from pathlib import Path
from typing import Any

import pypdfium2 as pdfium
from docx import Document
from openpyxl import Workbook
from PIL import Image, ImageChops, ImageDraw, ImageFilter, ImageOps
from pypdf import PdfReader

from app.doctools import ops
from app.doctools.ops import ToolError, hex_color, mm
from app.studio import compose as C

Result = tuple[bytes, str, str]
Files = list[tuple[str, bytes]]
MAX_OCR_PAGES = 20
DOCX = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
XLSX = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"


def _line(v: Any, n: int = 80) -> str:
    return C.clean_line(str(v or ""), n)


# ─────────────────────────────────────────────────────────────── OCR and conversion
@lru_cache(maxsize=1)
def _tess_langs() -> frozenset[str]:
    exe = shutil.which("tesseract")
    if exe is None:
        return frozenset()
    r = subprocess.run([exe, "--list-langs"], capture_output=True, text=True, timeout=20, check=False)  # noqa: S603
    return frozenset(x.strip() for x in r.stdout.splitlines()[1:] if x.strip())


def _ocr_one(im: Image.Image, lang: str) -> str:
    exe = shutil.which("tesseract")
    if exe is None:
        raise ToolError("Text recognition is not installed on this server (tesseract is missing).")
    need = set(lang.split("+"))
    if not need <= _tess_langs():
        raise ToolError("The language data for " + "/".join(sorted(need - _tess_langs())) + " is not installed on this server.")
    big = max(im.size)
    if big < 1400:  # small pictures read better when enlarged
        k = 1400 / big
        im = im.resize((round(im.width * k), round(im.height * k)), Image.Resampling.LANCZOS)
    with tempfile.TemporaryDirectory() as tmp:
        src = Path(tmp) / "in.png"
        im.convert("L").save(src)
        try:
            subprocess.run([exe, str(src), str(Path(tmp) / "out"), "-l", lang, "--psm", "3"], check=True, capture_output=True, timeout=120)  # noqa: S603
        except (subprocess.SubprocessError, OSError) as e:
            raise ToolError("The text could not be read from that file.") from e
        return (Path(tmp) / "out.txt").read_text("utf-8", errors="replace")


def _pages(name: str, data: bytes, dpi: int = 200) -> list[Image.Image]:
    if name.lower().endswith(".pdf") or data[:4] == b"%PDF":
        ops._pdf(data)
        doc = pdfium.PdfDocument(data)
        try:
            return [doc[i].render(scale=dpi / 72).to_pil().convert("RGB") for i in range(min(len(doc), MAX_OCR_PAGES))]
        finally:
            doc.close()
    return [ops.load(data)]


def _lang(p: dict[str, Any]) -> str:
    lang = str(p.get("lang", "eng+ben"))
    if lang not in ("eng", "ben", "eng+ben"):
        raise ToolError("Choose English, Bangla or both.")
    return lang


def ocr_text(files: Files, p: dict[str, Any]) -> str:
    name, data = files[0]
    out = []
    for i, im in enumerate(_pages(name, data), 1):
        t = _ocr_one(im, _lang(p)).strip()
        out.append(t)
    text = "\n\n".join(out).strip()
    if not text:
        raise ToolError("No readable text was found.")
    return text


def docx_bytes(text: str, title: str = "") -> bytes:
    d = Document()
    if title:
        d.add_heading(title, level=1)
    for para in re.split(r"\n{2,}", text.strip()):
        d.add_paragraph(para.strip().replace("\x0c", ""))
    out = io.BytesIO()
    d.save(out)
    return out.getvalue()


def op_ocr(files: Files, p: dict[str, Any]) -> Result:
    text = ocr_text(files, p)
    if str(p.get("format", "txt")) == "docx":
        return docx_bytes(text), DOCX, "docx"
    return text.encode("utf-8"), "text/plain; charset=utf-8", "txt"


def op_image_to_word(files: Files, p: dict[str, Any]) -> Result:
    return docx_bytes(ocr_text(files, p)), DOCX, "docx"


def _pdf_text(data: bytes) -> str:
    r = ops._pdf(data)
    return "\n\n".join((pg.extract_text() or "").strip() for pg in r.pages).strip()


def op_pdf_to_word(files: Files, p: dict[str, Any]) -> Result:
    name, data = files[0]
    text = _pdf_text(data)
    if len(text) < 20:  # a scan: no text layer, so read the pictures
        text = ocr_text(files, p)
    return docx_bytes(text), DOCX, "docx"


def op_pdf_to_excel(files: Files, p: dict[str, Any]) -> Result:
    """Each page becomes a sheet; a line is split into cells wherever there are two or more spaces (works for ruled and spaced tables)."""
    r = ops._pdf(files[0][1])
    wb = Workbook()
    wb.remove(wb.active)
    rows_total = 0
    for n, pg in enumerate(r.pages, 1):
        ws = wb.create_sheet(f"Page {n}")
        for line in (pg.extract_text(extraction_mode="layout") or "").splitlines():
            if not line.strip():
                continue
            cells = [c.strip() for c in re.split(r"\s{2,}|\t", line.strip())]
            ws.append([float(c.replace(",", "")) if re.fullmatch(r"-?\d{1,3}(,\d{3})*(\.\d+)?|-?\d+(\.\d+)?", c) else c for c in cells])
            rows_total += 1
    if rows_total == 0:
        raise ToolError("This PDF has no text to put in a table (it may be a scan; use Image to Text first).")
    out = io.BytesIO()
    wb.save(out)
    return out.getvalue(), XLSX, "xlsx"


def op_text_to_docx(files: Files, p: dict[str, Any]) -> Result:
    t = str(p.get("text", "")).strip()
    if not t:
        raise ToolError("There is no text.")
    return docx_bytes(t[:200_000], str(p.get("title", ""))[:100]), DOCX, "docx"


def op_extract_text(files: Files, p: dict[str, Any]) -> Result:
    """Plain text out of a PDF / Word / text / picture, for translation. Pictures and scans go through OCR."""
    name, data = files[0]
    low = name.lower()
    if low.endswith(".pdf"):
        t = _pdf_text(data) or ocr_text(files, p)
    elif low.endswith(".docx"):
        d = Document(io.BytesIO(data))
        t = "\n\n".join(x.text for x in d.paragraphs if x.text.strip())
    elif low.endswith((".txt", ".rtf")):
        t = data.decode("utf-8", errors="replace")
    elif low.endswith((".png", ".jpg", ".jpeg", ".webp")):
        t = ocr_text(files, p)
    else:
        raise ToolError("Use a PDF, DOCX, TXT or picture.")
    if not t.strip():
        raise ToolError("No readable text was found.")
    return t.strip().encode("utf-8"), "text/plain; charset=utf-8", "txt"


# ─────────────────────────────────────────────────────────────── enhancement
def op_enhance(files: Files, p: dict[str, Any]) -> Result:
    im = ops.load(files[0][1])
    im = ImageOps.autocontrast(im, cutoff=1)
    if p.get("denoise", True):
        im = im.filter(ImageFilter.MedianFilter(3))
    scale = float(p.get("scale", 1) or 1)
    if scale not in (1.0, 1.5, 2.0):
        raise ToolError("Enlarge by 1, 1.5 or 2 times.")
    if scale > 1:
        if im.width * im.height * scale * scale > ops.MAX_PIXELS:
            raise ToolError("The enlarged picture would be too big.")
        im = im.resize((round(im.width * scale), round(im.height * scale)), Image.Resampling.LANCZOS)
    im = im.filter(ImageFilter.UnsharpMask(radius=2, percent=int(p.get("sharpen", 110) or 110), threshold=3))
    return ops._out(im, p)


# ─────────────────────────────────────────────────────────────── generators
def op_qr(files: Files, p: dict[str, Any]) -> Result:
    import qrcode
    from qrcode.constants import ERROR_CORRECT_M

    text = str(p.get("text", "")).strip()
    if not text or len(text) > 1000:
        raise ToolError("Enter the text or link for the QR code (up to 1000 characters).")
    q = qrcode.QRCode(error_correction=ERROR_CORRECT_M, box_size=10, border=4)
    q.add_data(text)
    q.make(fit=True)
    im = q.make_image(fill_color=hex_color(str(p.get("color", "#000000")), "#000000"), back_color=hex_color(str(p.get("bg", "#ffffff"))))
    px = int(p.get("size", 800) or 800)
    if not 200 <= px <= 3000:
        raise ToolError("Size must be 200 to 3000 pixels.")
    pil = im.get_image().convert("RGB").resize((px, px), Image.Resampling.NEAREST)
    if str(p.get("format", "png")) == "jpeg":
        return ops.encode(pil, "jpeg")
    return ops.encode(pil, "png")


BARCODES = {"code128": "code128", "code39": "code39", "ean13": "ean13", "ean8": "ean8", "upca": "upca"}


def op_barcode(files: Files, p: dict[str, Any]) -> Result:
    import barcode
    from barcode.writer import ImageWriter

    kind = str(p.get("type", "code128"))
    text = str(p.get("text", "")).strip()
    if kind not in BARCODES or not text or len(text) > 60:
        raise ToolError("Choose a type and enter the number or text (up to 60 characters).")
    try:
        code = barcode.get(BARCODES[kind], text, writer=ImageWriter())
        buf = io.BytesIO()
        code.write(buf, options={"write_text": False, "module_height": 18.0, "quiet_zone": 4.0, "dpi": 300})
    except Exception as e:  # noqa: BLE001
        raise ToolError("That value is not valid for this barcode type (EAN-13 needs 12 or 13 digits, EAN-8 7 or 8, UPC-A 11 or 12).") from e
    bars = Image.open(io.BytesIO(buf.getvalue())).convert("RGB")
    label = code.get_fullcode()
    size = max(24, bars.height // 8)
    pad = size + 24
    out = Image.new("RGB", (bars.width, bars.height + pad), "white")
    out.paste(bars, (0, 0))
    d = ImageDraw.Draw(out)
    C.draw_text(d, ((out.width - C.text_width(label, size, False)) / 2, bars.height + 8), label, size, False, (0, 0, 0, 255))
    return ops.encode(out, "png")


def op_signature(files: Files, p: dict[str, Any]) -> Result:
    """A photo of a signature on white paper becomes dark ink on a transparent PNG; or a typed name is set in a slanted signature style."""
    ink = hex_color(str(p.get("color", "#111827")), "#111827")
    if files:
        g = ImageOps.autocontrast(ops.load(files[0][1]).convert("L"), cutoff=2)
        alpha = g.point(lambda v: 0 if v > 190 else min(255, round((190 - v) * 255 / 110)))
        layer = Image.new("RGBA", g.size, (*ink, 0))
        layer.putalpha(alpha)
    else:
        name = _line(p.get("text"), 40)
        if not name:
            raise ToolError("Upload a photo of your signature, or type your name.")
        size = 220
        w = int(C.text_width(name, size, True)) + size
        flat = Image.new("RGBA", (w, size * 2), (0, 0, 0, 0))
        C.draw_text(ImageDraw.Draw(flat), (size // 2, size // 3), name, size, True, (*ink, 255))
        layer = flat.transform(flat.size, Image.Transform.AFFINE, (1, 0.32, -size * 0.3, 0, 1, 0), resample=Image.Resampling.BICUBIC)
    box = layer.getchannel("A").point(lambda v: 255 if v > 20 else 0).getbbox()
    if box is None:
        raise ToolError("No signature was found in that picture.")
    layer = layer.crop(box)
    pad = max(8, layer.width // 40)
    canvas = Image.new("RGBA", (layer.width + 2 * pad, layer.height + 2 * pad), (0, 0, 0, 0))
    canvas.paste(layer, (pad, pad))
    width = int(p.get("width", 0) or 0)
    if width:
        if not 100 <= width <= 3000:
            raise ToolError("Width must be 100 to 3000 pixels.")
        canvas = canvas.resize((width, max(1, round(canvas.height * width / canvas.width))), Image.Resampling.LANCZOS)
    return ops.encode(canvas, "png")


def _wm_layer(size: tuple[int, int], text: str, opacity: int, tile: bool, scale: float) -> Image.Image:
    layer = Image.new("RGBA", size, (0, 0, 0, 0))
    fs = max(14, round(min(size) * scale / 100))
    tw = int(C.text_width(text, fs, True)) + fs
    stamp = Image.new("RGBA", (tw, fs * 2), (0, 0, 0, 0))
    C.draw_text(ImageDraw.Draw(stamp), (fs // 2, fs // 3), text, fs, True, (110, 110, 110, round(255 * opacity / 100)))
    stamp = stamp.rotate(30, expand=True, resample=Image.Resampling.BICUBIC)
    if tile:
        for y in range(-stamp.height, size[1], round(stamp.height * 1.6)):
            for x in range(-stamp.width, size[0], round(stamp.width * 1.3)):
                layer.paste(stamp, (x, y), stamp)
    else:
        layer.paste(stamp, ((size[0] - stamp.width) // 2, (size[1] - stamp.height) // 2), stamp)
    return layer


def op_watermark(files: Files, p: dict[str, Any]) -> Result:
    text = _line(p.get("text"), 40)
    if not text:
        raise ToolError("Type the watermark text.")
    opacity = int(p.get("opacity", 35) or 35)
    scale = float(p.get("scale", 8) or 8)
    if not (5 <= opacity <= 100 and 2 <= scale <= 30):
        raise ToolError("Opacity must be 5-100 and size 2-30 percent.")
    tile = str(p.get("layout", "tile")) == "tile"
    name, data = files[0]
    if name.lower().endswith(".pdf"):
        pages = []
        for pg in _pages(name, data, dpi=150):
            base = pg.convert("RGBA")
            base.alpha_composite(_wm_layer(base.size, text, opacity, tile, scale))
            pages.append(base.convert("RGB"))
        return ops.images_to_pdf(pages), "application/pdf", "pdf"
    base = ops.load(data).convert("RGBA")
    base.alpha_composite(_wm_layer(base.size, text, opacity, tile, scale))
    return ops._out(base.convert("RGB"), p)


# ─────────────────────────────────────────────────────────────── cards (85.6 x 54 mm and 90 x 54 mm at 300 dpi)
def _text_block(d: ImageDraw.ImageDraw, x: int, y: int, w: int, text: str, size: int, bold: bool, fill: tuple[int, int, int], floor: int = 20) -> int:
    if not text:
        return y
    s = size
    while s > floor and C.text_width(text, s, bold) > w:
        s -= 2
    C.draw_text(d, (x, y), text, s, bold, (*fill, 255))
    return y + round(s * 1.45)


def _logo_or_photo(files: Files, box: tuple[int, int], cover: bool) -> Image.Image | None:
    if not files:
        return None
    im = ops.load(files[0][1])
    return ImageOps.fit(im, box, Image.Resampling.LANCZOS) if cover else ImageOps.contain(im, box, Image.Resampling.LANCZOS)


def _card_out(im: Image.Image, p: dict[str, Any]) -> Result:
    if str(p.get("format", "png")) == "pdf":
        out = io.BytesIO()
        im.convert("RGB").save(out, "PDF", resolution=float(ops.DPI))
        return out.getvalue(), "application/pdf", "pdf"
    return ops.encode(im, "png")


def op_id_card(files: Files, p: dict[str, Any]) -> Result:
    name = _line(p.get("name"), 40)
    if not name:
        raise ToolError("Enter the name.")
    primary, accent = hex_color(str(p.get("primary", "#1f3a8a")), "#1f3a8a"), hex_color(str(p.get("accent", "#f59e0b")), "#f59e0b")
    w, h = mm(85.6), mm(54.0)
    card = Image.new("RGB", (w, h), "white")
    d = ImageDraw.Draw(card)
    head = round(h * 0.2)
    d.rectangle((0, 0, w, head), fill=primary)
    d.rectangle((0, head, w, head + 8), fill=accent)
    on = C.readable_on(primary)
    org = _line(p.get("org"), 40)
    _text_block(d, 30, round(head * 0.22), w - 60, org or "IDENTITY CARD", 42, True, on, 24)
    ph_w, ph_h = round(w * 0.27), round(h * 0.56)
    photo = _logo_or_photo(files, (ph_w, ph_h), True)
    top = head + 40
    if photo is not None:
        card.paste(photo, (30, top))
        d.rectangle((29, top - 1, 30 + ph_w, top + ph_h), outline=accent, width=3)
    x = 30 + ph_w + 36
    tw = w - x - 30
    y = _text_block(d, x, top - 6, tw, name, 52, True, (20, 20, 20), 28)
    y = _text_block(d, x, y, tw, _line(p.get("title"), 40), 34, False, primary, 22)
    y += 8
    for label, key in (("ID", "id_no"), ("Phone", "phone"), ("Blood", "blood"), ("Valid", "valid")):
        v = _line(p.get(key), 30)
        if v:
            y = _text_block(d, x, y, tw, f"{label}: {v}", 30, False, (50, 50, 50), 20)
    addr = _line(p.get("address"), 90)
    if addr:
        lines = C.wrap(addr, 24, False, w - 60)[:2]
        for i, ln in enumerate(lines):
            C.draw_text(d, (30, h - 28 - 34 * (len(lines) - i)), ln, 24, False, (90, 90, 90, 255))
    d.rectangle((0, h - 12, w, h), fill=primary)
    return _card_out(card, p)


def op_business_card(files: Files, p: dict[str, Any]) -> Result:
    name = _line(p.get("name"), 40)
    if not name:
        raise ToolError("Enter the name.")
    primary, accent = hex_color(str(p.get("primary", "#111827")), "#111827"), hex_color(str(p.get("accent", "#f59e0b")), "#f59e0b")
    w, h = mm(90.0), mm(54.0)
    card = Image.new("RGB", (w, h), "white")
    d = ImageDraw.Draw(card)
    d.rectangle((0, 0, 26, h), fill=primary)
    d.rectangle((26, 0, 34, h), fill=accent)
    left = 80
    logo = _logo_or_photo(files, (240, 130), False)
    y = 50
    if logo is not None:
        rgba = logo.convert("RGBA")
        card.paste(rgba, (left, y), rgba)
        y += logo.height + 24
    company = _line(p.get("company"), 40)
    if company and logo is None:
        y = _text_block(d, left, y, w - left - 50, company, 38, True, accent, 24) + 10
    y = max(y, 190)
    y = _text_block(d, left, y, w - left - 50, name, 62, True, primary, 34)
    y = _text_block(d, left, y, w - left - 50, _line(p.get("title"), 50), 36, False, (90, 90, 90), 22) + 18
    for key in ("phone", "email", "website", "address"):
        v = _line(p.get(key), 70)
        if v:
            y = _text_block(d, left, y, w - left - 50, v, 32, False, (40, 40, 40), 20)
    return _card_out(card, p)


HANDLERS: dict[str, Callable[[Files, dict[str, Any]], Result]] = {
    "ocr": op_ocr, "image_to_word": op_image_to_word, "pdf_to_word": op_pdf_to_word, "pdf_to_excel": op_pdf_to_excel,
    "text_to_docx": op_text_to_docx, "extract_text": op_extract_text, "enhance": op_enhance, "qr": op_qr, "barcode": op_barcode,
    "signature": op_signature, "watermark": op_watermark, "id_card": op_id_card, "business_card": op_business_card,
}
NO_FILES = {"qr", "barcode", "text_to_docx", "signature", "id_card", "business_card"}
