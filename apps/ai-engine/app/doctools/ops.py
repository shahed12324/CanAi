"""Every tool in one place. Inputs and outputs are bytes; limits are enforced here so callers cannot skip them."""

from __future__ import annotations

import io
import re
import shutil
import subprocess  # noqa: S404
import tempfile
import zipfile
from collections.abc import Callable
from pathlib import Path
from typing import Any

import pypdfium2 as pdfium
from PIL import Image, ImageChops, ImageDraw, ImageFilter, ImageOps
from pypdf import PdfReader, PdfWriter
from pypdf.generic import RectangleObject

MAX_PIXELS = 40_000_000
MAX_PAGES = 200
MAX_FILES = 40
DPI = 300
PAPER_MM = {"a4": (210.0, 297.0), "4r": (101.6, 152.4), "5r": (127.0, 177.8), "6r": (152.4, 203.2), "a5": (148.0, 210.0)}
CARD_MM = (85.6, 54.0)  # ID-1: NID / driving licence
Image.MAX_IMAGE_PIXELS = MAX_PIXELS


class ToolError(ValueError):
    """A problem with the user's file or settings; the message is safe to show."""


def mm(v: float, dpi: int = DPI) -> int:
    return max(1, round(v / 25.4 * dpi))


# ─────────────────────────────────────────────────────────────── images
def load(data: bytes) -> Image.Image:
    try:
        im: Image.Image = Image.open(io.BytesIO(data))
        im.load()
    except Exception as e:  # noqa: BLE001
        raise ToolError("That file is not a readable image.") from e
    im = ImageOps.exif_transpose(im) or im
    if im.mode in ("RGBA", "LA", "P"):
        im = im.convert("RGBA")
        bg = Image.new("RGB", im.size, "white")
        bg.paste(im, mask=im.getchannel("A"))
        return bg
    return im.convert("RGB")


def encode(im: Image.Image, fmt: str = "jpeg", quality: int = 90, dpi: int = DPI) -> tuple[bytes, str, str]:
    out = io.BytesIO()
    if fmt == "png":
        im.save(out, "PNG", dpi=(dpi, dpi))
        return out.getvalue(), "image/png", "png"
    im.convert("RGB").save(out, "JPEG", quality=max(20, min(95, quality)), dpi=(dpi, dpi), optimize=True)
    return out.getvalue(), "image/jpeg", "jpg"


def hex_color(v: str, default: str = "#ffffff") -> tuple[int, int, int]:
    m = re.fullmatch(r"#?([0-9a-fA-F]{6})", (v or "").strip()) or re.fullmatch(r"#?([0-9a-fA-F]{6})", default)
    assert m
    h = m.group(1)
    return int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)


def crop(im: Image.Image, left: float = 0, top: float = 0, right: float = 0, bottom: float = 0) -> Image.Image:
    """Margins as percent of each side."""
    for v in (left, top, right, bottom):
        if not 0 <= v < 100:
            raise ToolError("Crop values must be between 0 and 99 percent.")
    w, h = im.size
    box = (round(w * left / 100), round(h * top / 100), w - round(w * right / 100), h - round(h * bottom / 100))
    if box[2] - box[0] < 8 or box[3] - box[1] < 8:
        raise ToolError("Nothing would be left after cropping.")
    return im.crop(box)


def resize(im: Image.Image, width: int = 0, height: int = 0, percent: float = 0, keep_ratio: bool = True) -> Image.Image:
    w, h = im.size
    if percent:
        width, height = round(w * percent / 100), round(h * percent / 100)
    elif width and not height and keep_ratio:
        height = round(h * width / w)
    elif height and not width and keep_ratio:
        width = round(w * height / h)
    if not (width and height):
        raise ToolError("Give a width and/or height, or a percent.")
    if not (8 <= width <= 10000 and 8 <= height <= 10000) or width * height > MAX_PIXELS:
        raise ToolError("That size is out of range (8 to 10000 pixels).")
    return im.resize((width, height), Image.Resampling.LANCZOS)


def rotate(im: Image.Image, degrees: float) -> Image.Image:
    return im.rotate(-degrees, expand=True, resample=Image.Resampling.BICUBIC, fillcolor=(255, 255, 255))


def jpeg_under_kb(im: Image.Image, kb: int) -> bytes:
    """Highest JPEG quality that fits the size limit; shrinks the picture a little if even quality 25 is too big."""
    target = kb * 1024
    cur = im.convert("RGB")
    for _ in range(8):
        for q in (92, 85, 78, 70, 60, 50, 40, 30, 25):
            data = encode(cur, "jpeg", q)[0]
            if len(data) <= target:
                return data
        cur = cur.resize((max(32, round(cur.width * 0.85)), max(32, round(cur.height * 0.85))), Image.Resampling.LANCZOS)
    raise ToolError("Could not reach that file size without making the picture tiny.")


def cleanup(im: Image.Image, mode: str = "color") -> Image.Image:
    """Document background cleanup: removes shadows and paper tint so the page reads white and the ink stays dark."""

    def flat(channel: Image.Image) -> Image.Image:
        small = channel.resize((max(8, channel.width // 8), max(8, channel.height // 8)), Image.Resampling.BILINEAR)
        bg = small.filter(ImageFilter.MaxFilter(7)).filter(ImageFilter.GaussianBlur(6)).resize(channel.size, Image.Resampling.BICUBIC)
        diff = ImageChops.subtract(bg, channel)  # how much darker than the paper around it
        lut = [max(0, min(255, round(255 - d * 255 / 90))) for d in range(256)]
        return diff.point(lut)

    if mode == "color":
        return Image.merge("RGB", [flat(c) for c in im.split()])
    g = flat(im.convert("L"))
    if mode == "bw":
        g = g.point(lambda v: 255 if v > 150 else 0)
    return g.convert("RGB")


def remove_background(im: Image.Image, tolerance: int = 40, feather: int = 2) -> Image.Image:
    """Plain-background removal: grows from the picture's border over colours close to the border colour.
    Works for studio walls and scanned paper; it is not a person-segmentation model."""
    if not 5 <= tolerance <= 120:
        raise ToolError("Tolerance must be between 5 and 120.")
    scale = min(1.0, 700 / max(im.size))
    small = im.resize((max(1, round(im.width * scale)), max(1, round(im.height * scale))), Image.Resampling.LANCZOS)
    marker = (255, 0, 255)
    probe = small.copy()
    w, h = probe.size
    seeds = [(0, 0), (w - 1, 0), (0, h - 1), (w - 1, h - 1), (w // 2, 0), (w // 2, h - 1), (0, h // 2), (w - 1, h // 2)]
    for s in seeds:
        px = probe.getpixel(s)
        if px != marker:
            ImageDraw.floodfill(probe, s, marker, thresh=tolerance)
    mask = Image.new("L", small.size, 255)
    mask.paste(0, mask=_is_marker(probe, marker))
    mask = mask.resize(im.size, Image.Resampling.BILINEAR)
    if feather:
        mask = mask.filter(ImageFilter.GaussianBlur(feather))
    out = im.convert("RGBA")
    out.putalpha(mask)
    return out


def _is_marker(probe: Image.Image, marker: tuple[int, int, int]) -> Image.Image:
    r, g, b = probe.split()
    m = ImageChops.multiply(ImageChops.multiply(r.point(lambda v: 255 if v == marker[0] else 0), g.point(lambda v: 255 if v == marker[1] else 0)), b.point(lambda v: 255 if v == marker[2] else 0))
    return m


def flatten(im: Image.Image, color: tuple[int, int, int]) -> Image.Image:
    bg = Image.new("RGB", im.size, color)
    if im.mode == "RGBA":
        bg.paste(im, mask=im.getchannel("A"))
        return bg
    return im.convert("RGB")


def passport_photo(im: Image.Image, width_mm: float, height_mm: float, zoom: float = 1.0, dx: float = 0, dy: float = 0, bg: str = "", dpi: int = DPI) -> Image.Image:
    """Exact-size photo. Zoom 1 fits the whole picture; dx and dy (percent) move the face inside the frame."""
    if not (10 <= width_mm <= 200 and 10 <= height_mm <= 300):
        raise ToolError("Photo size must be between 10 and 200 mm wide.")
    if not 1.0 <= zoom <= 4.0 or abs(dx) > 50 or abs(dy) > 50:
        raise ToolError("Zoom must be 1 to 4 and the shift within 50 percent.")
    if bg:
        im = flatten(remove_background(im), hex_color(bg))
    tw, th = mm(width_mm, dpi), mm(height_mm, dpi)
    ratio = tw / th
    w, h = im.size
    cw, ch = (h * ratio, h) if w / h > ratio else (w, w / ratio)  # biggest crop with the right shape
    cw, ch = cw / zoom, ch / zoom
    cx, cy = w / 2 - dx / 100 * w, h / 2 - dy / 100 * h
    left = min(max(cx - cw / 2, 0), w - cw)
    top = min(max(cy - ch / 2, 0), h - ch)
    box = (round(left), round(top), round(left + cw), round(top + ch))
    return im.crop(box).resize((tw, th), Image.Resampling.LANCZOS)


def photo_sheet(photo: Image.Image, paper: str, copies: int = 0, gap_mm: float = 2.0, margin_mm: float = 5.0, dpi: int = DPI) -> Image.Image:
    """Tiles one photo as many times as fits (or `copies`). Thin cut guides are drawn between pictures."""
    if paper not in PAPER_MM:
        raise ToolError("Unknown paper size.")
    pw, ph = (mm(v, dpi) for v in PAPER_MM[paper])
    gap, mar = mm(gap_mm, dpi), mm(margin_mm, dpi)
    w, h = photo.size
    best = None
    for pw_, ph_ in ((pw, ph), (ph, pw)):  # portrait or landscape, whichever fits more
        cols = max(0, (pw_ - 2 * mar + gap) // (w + gap))
        rows = max(0, (ph_ - 2 * mar + gap) // (h + gap))
        if best is None or cols * rows > best[0] * best[1]:
            best = (cols, rows, pw_, ph_)
    assert best
    cols, rows, pw, ph = best
    if cols * rows == 0:
        raise ToolError("The photo is larger than the paper.")
    total = min(cols * rows, copies) if copies else cols * rows
    sheet = Image.new("RGB", (pw, ph), "white")
    gw, gh = cols * w + (cols - 1) * gap, rows * h + (rows - 1) * gap
    x0, y0 = (pw - gw) // 2, (ph - gh) // 2
    d = ImageDraw.Draw(sheet)
    for i in range(total):
        c, r = i % cols, i // cols
        x, y = x0 + c * (w + gap), y0 + r * (h + gap)
        sheet.paste(photo, (x, y))
        d.rectangle((x - 1, y - 1, x + w, y + h), outline=(200, 200, 200))
    return sheet


def place_on_page(items: list[Image.Image], paper: str = "a4", margin_mm: float = 10.0, gap_mm: float = 8.0, per_page: int = 0, dpi: int = DPI) -> list[Image.Image]:
    """Puts pictures on pages top to bottom, each scaled to the width of the page. `per_page` forces a grid
    (2 = stacked, 4 = 2x2, 6 = 2x3) and makes every picture fit its cell."""
    if paper not in PAPER_MM:
        raise ToolError("Unknown paper size.")
    pw, ph = (mm(v, dpi) for v in PAPER_MM[paper])
    mar, gap = mm(margin_mm, dpi), mm(gap_mm, dpi)
    pages: list[Image.Image] = []
    if per_page:
        cols = {1: 1, 2: 1, 3: 1, 4: 2, 6: 2, 8: 2, 9: 3}.get(per_page)
        if cols is None:
            raise ToolError("Choose 1, 2, 3, 4, 6, 8 or 9 per page.")
        rows = per_page // cols
        cw, chh = (pw - 2 * mar - (cols - 1) * gap) // cols, (ph - 2 * mar - (rows - 1) * gap) // rows
        for s in range(0, len(items), per_page):
            page = Image.new("RGB", (pw, ph), "white")
            for i, im in enumerate(items[s : s + per_page]):
                fit = ImageOps.contain(im, (cw, chh), Image.Resampling.LANCZOS)
                x = mar + (i % cols) * (cw + gap) + (cw - fit.width) // 2
                y = mar + (i // cols) * (chh + gap) + (chh - fit.height) // 2
                page.paste(fit, (x, y))
            pages.append(page)
        return pages
    for im in items:
        pages.append(Image.new("RGB", (pw, ph), "white"))
        fit = ImageOps.contain(im, (pw - 2 * mar, ph - 2 * mar), Image.Resampling.LANCZOS)
        pages[-1].paste(fit, ((pw - fit.width) // 2, (ph - fit.height) // 2))
    return pages


def card_page(front: Image.Image, back: Image.Image | None, paper: str = "a4", width_mm: float = 0, dpi: int = DPI) -> Image.Image:
    """NID / licence: both sides at real card size (85.6 x 54 mm unless width_mm is given), front over back, centred."""
    pw, ph = (mm(v, dpi) for v in PAPER_MM[paper])
    cw = mm(width_mm or CARD_MM[0], dpi)
    if cw > pw - mm(10, dpi):
        raise ToolError("The card would be wider than the page.")
    sides = [s for s in (front, back) if s is not None]
    scaled = [ImageOps.contain(s, (cw, mm(120, dpi))) for s in sides]
    gap = mm(12, dpi)
    total = sum(s.height for s in scaled) + gap * (len(scaled) - 1)
    page = Image.new("RGB", (pw, ph), "white")
    y = max(mm(10, dpi), (ph - total) // 3)
    d = ImageDraw.Draw(page)
    for s in scaled:
        x = (pw - s.width) // 2
        page.paste(s, (x, y))
        d.rectangle((x - 1, y - 1, x + s.width, y + s.height), outline=(180, 180, 180))
        y += s.height + gap
    return page


# ─────────────────────────────────────────────────────────────── PDF
def _pdf(data: bytes) -> PdfReader:
    try:
        r = PdfReader(io.BytesIO(data))
        if r.is_encrypted:
            raise ToolError("That PDF is password protected.")
        if len(r.pages) > MAX_PAGES:
            raise ToolError(f"PDFs are limited to {MAX_PAGES} pages.")
    except ToolError:
        raise
    except Exception as e:  # noqa: BLE001
        raise ToolError("That file is not a readable PDF.") from e
    return r


def _bytes(w: PdfWriter) -> bytes:
    out = io.BytesIO()
    w.write(out)
    return out.getvalue()


def images_to_pdf(pages: list[Image.Image], quality: int = 85) -> bytes:
    if not pages:
        raise ToolError("No pictures given.")
    out = io.BytesIO()
    rgb = [p.convert("RGB") for p in pages]
    rgb[0].save(out, "PDF", save_all=True, append_images=rgb[1:], resolution=float(DPI), quality=quality)
    return out.getvalue()


def pdf_merge(files: list[bytes]) -> bytes:
    if len(files) < 2:
        raise ToolError("Give at least two PDFs to merge.")
    w = PdfWriter()
    total = 0
    for f in files:
        r = _pdf(f)
        total += len(r.pages)
        if total > MAX_PAGES:
            raise ToolError(f"The merged file would pass {MAX_PAGES} pages.")
        for p in r.pages:
            w.add_page(p)
    return _bytes(w)


def parse_ranges(spec: str, count: int) -> list[list[int]]:
    """'1-3,5' → [[0,1,2],[4]] (0-based). Empty means one file per page."""
    if not spec.strip():
        return [[i] for i in range(count)]
    out: list[list[int]] = []
    for part in spec.split(","):
        m = re.fullmatch(r"\s*(\d+)\s*(?:-\s*(\d+))?\s*", part)
        if not m:
            raise ToolError("Write pages like 1-3,5,7-9.")
        a, b = int(m.group(1)), int(m.group(2) or m.group(1))
        if a < 1 or b < a or b > count:
            raise ToolError(f"Page range {part.strip()} is outside this PDF ({count} pages).")
        out.append(list(range(a - 1, b)))
    return out


def pdf_split(data: bytes, spec: str = "") -> bytes:
    r = _pdf(data)
    groups = parse_ranges(spec, len(r.pages))
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for n, g in enumerate(groups, 1):
            w = PdfWriter()
            for i in g:
                w.add_page(r.pages[i])
            z.writestr(f"part-{n:02d}-pages-{g[0] + 1}-{g[-1] + 1}.pdf", _bytes(w))
    return out.getvalue()


def pdf_rotate(data: bytes, degrees: int, spec: str = "") -> bytes:
    if degrees % 90:
        raise ToolError("Rotate by 90, 180 or 270 degrees.")
    r = _pdf(data)
    chosen = {i for g in parse_ranges(spec, len(r.pages)) for i in g} if spec.strip() else set(range(len(r.pages)))
    w = PdfWriter()
    for i, p in enumerate(r.pages):
        if i in chosen:
            p.rotate(degrees)
        w.add_page(p)
    return _bytes(w)


def pdf_crop(data: bytes, left: float = 0, top: float = 0, right: float = 0, bottom: float = 0) -> bytes:
    """Margins in percent of the page; the page is hidden outside the new box (content is not destroyed)."""
    for v in (left, top, right, bottom):
        if not 0 <= v < 50:
            raise ToolError("Crop each side by 0 to 49 percent.")
    r = _pdf(data)
    w = PdfWriter()
    for p in r.pages:
        box = p.cropbox
        x0, y0, x1, y1 = float(box.left), float(box.bottom), float(box.right), float(box.top)
        pw, ph = x1 - x0, y1 - y0
        new = RectangleObject((x0 + pw * left / 100, y0 + ph * bottom / 100, x1 - pw * right / 100, y1 - ph * top / 100))
        p.cropbox = new
        p.mediabox = new
        w.add_page(p)
    return _bytes(w)


def pdf_to_images(data: bytes, fmt: str = "jpeg", dpi: int = 150, spec: str = "") -> tuple[bytes, str, str]:
    r = _pdf(data)
    if not 50 <= dpi <= 300:
        raise ToolError("DPI must be between 50 and 300.")
    pages = sorted({i for g in parse_ranges(spec, len(r.pages)) for i in g}) if spec.strip() else list(range(len(r.pages)))
    doc = pdfium.PdfDocument(data)
    try:
        if len(pages) == 1:
            im = doc[pages[0]].render(scale=dpi / 72).to_pil()
            return encode(im, fmt, 90, dpi)
        out = io.BytesIO()
        with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
            for i in pages:
                data_, _, ext = encode(doc[i].render(scale=dpi / 72).to_pil(), fmt, 90, dpi)
                z.writestr(f"page-{i + 1:03d}.{ext}", data_)
        return out.getvalue(), "application/zip", "zip"
    finally:
        doc.close()


def pdf_compress(data: bytes, level: str = "medium") -> bytes:
    """low = lossless clean-up (text stays text). medium/high = every page re-drawn as a picture (smaller, text no longer selectable)."""
    r = _pdf(data)
    if level == "low":
        w = PdfWriter(clone_from=r)
        for p in w.pages:
            p.compress_content_streams()
        try:
            w.compress_identical_objects(remove_duplicates=True, remove_orphans=True)       # pypdf >= 6
        except TypeError:
            w.compress_identical_objects(remove_identicals=True, remove_orphans=True)       # older pypdf
        return _bytes(w)
    if level not in ("medium", "high"):
        raise ToolError("Level must be low, medium or high.")
    dpi, q = (130, 70) if level == "medium" else (100, 55)
    doc = pdfium.PdfDocument(data)
    try:
        ims = [doc[i].render(scale=dpi / 72).to_pil().convert("RGB") for i in range(len(doc))]
    finally:
        doc.close()
    out = io.BytesIO()
    ims[0].save(out, "PDF", save_all=True, append_images=ims[1:], resolution=float(dpi), quality=q)
    return out.getvalue()


def doc_to_pdf(data: bytes, filename: str) -> bytes:
    """Word, Excel, PowerPoint, OpenDocument and text files through LibreOffice (must be installed on the engine image)."""
    ext = Path(filename).suffix.lower()
    if ext not in {".doc", ".docx", ".xls", ".xlsx", ".ppt", ".pptx", ".odt", ".ods", ".odp", ".txt", ".rtf"}:
        raise ToolError("Supported: Word, Excel, PowerPoint, OpenDocument, RTF and text files.")
    office = shutil.which("soffice") or shutil.which("libreoffice")
    if office is None:
        raise ToolError("Document conversion is not installed on this server (LibreOffice is missing).")
    with tempfile.TemporaryDirectory() as tmp:
        src = Path(tmp) / f"input{ext}"
        src.write_bytes(data)
        try:
            subprocess.run(  # noqa: S603
                [office, f"-env:UserInstallation=file://{tmp}/profile", "--headless", "--convert-to", "pdf", "--outdir", tmp, str(src)],
                check=True, capture_output=True, timeout=90,
            )
        except (subprocess.SubprocessError, OSError) as e:
            raise ToolError("The document could not be converted.") from e
        pdf = Path(tmp) / "input.pdf"
        if not pdf.exists():
            raise ToolError("The document could not be converted.")
        return pdf.read_bytes()


# ─────────────────────────────────────────────────────────────── dispatcher
Result = tuple[bytes, str, str]  # data, mime, extension
PRESETS = {"35x45": (35.0, 45.0), "2x2in": (50.8, 50.8), "passport": (35.0, 45.0), "stamp": (25.0, 30.0)}


def _imgs(files: list[tuple[str, bytes]]) -> list[Image.Image]:
    return [load(b) for _, b in files]


def _one(files: list[tuple[str, bytes]]) -> Image.Image:
    if not files:
        raise ToolError("Upload a file first.")
    return load(files[0][1])


def _out(im: Image.Image, p: dict[str, Any], alpha_ok: bool = False) -> Result:
    fmt = str(p.get("format", "jpeg")).lower()
    if fmt == "png" or (alpha_ok and im.mode == "RGBA"):
        return encode(im, "png")
    kb = int(p.get("max_kb") or 0)
    if kb:
        return jpeg_under_kb(im, kb), "image/jpeg", "jpg"
    return encode(im, "jpeg", int(p.get("quality", 90)))


def _pdf_out(pages: list[Image.Image]) -> Result:
    return images_to_pdf(pages), "application/pdf", "pdf"


def run(op: str, files: list[tuple[str, bytes]], p: dict[str, Any]) -> Result:
    if len(files) > MAX_FILES:
        raise ToolError(f"At most {MAX_FILES} files at a time.")
    def f(k: str, d: float = 0.0) -> float:
        return float(p.get(k, d) or d)

    paper = str(p.get("paper", "a4")).lower()

    handlers: dict[str, Callable[[], Result]] = {
        "image_crop": lambda: _out(crop(_one(files), f("left"), f("top"), f("right"), f("bottom")), p),
        "image_resize": lambda: _out(resize(_one(files), int(f("width")), int(f("height")), f("percent"), bool(p.get("keep_ratio", True))), p),
        "image_rotate": lambda: _out(rotate(_one(files), f("degrees", 90)), p),
        "doc_cleanup": lambda: _out(cleanup(_one(files), str(p.get("mode", "color"))), p),
        "bg_remove": lambda: _out(remove_background(_one(files), int(f("tolerance", 40))), p, alpha_ok=True),
        "bg_color": lambda: _out(flatten(remove_background(_one(files), int(f("tolerance", 40))), hex_color(str(p.get("color", "#ffffff")))), p),
        "passport_photo": lambda: _out(_photo(files, p), p),
        "photo_sheet": lambda: _out(photo_sheet(_photo(files, p), paper if paper != "a4" else "a4", int(f("copies")), f("gap_mm", 2), f("margin_mm", 5)), p),
        "a4_page": lambda: _pdf_or_image(place_on_page(_imgs(files), paper, f("margin_mm", 10), per_page=0), p),
        "multi_on_page": lambda: _pdf_or_image(place_on_page(_imgs(files), paper, f("margin_mm", 10), f("gap_mm", 8), int(f("per_page", 4))), p),
        "scan": lambda: _scan(files, p, paper),
        "images_to_pdf": lambda: _pdf_out(_imgs(files) if str(p.get("fit", "a4")) == "original" else place_on_page(_imgs(files), paper, f("margin_mm", 0))),
        "pdf_to_images": lambda: pdf_to_images(files[0][1], str(p.get("format", "jpeg")).lower(), int(f("dpi", 150)), str(p.get("pages", ""))),
        "pdf_merge": lambda: (pdf_merge([b for _, b in files]), "application/pdf", "pdf"),
        "pdf_split": lambda: (pdf_split(files[0][1], str(p.get("pages", ""))), "application/zip", "zip"),
        "pdf_compress": lambda: (pdf_compress(files[0][1], str(p.get("level", "medium"))), "application/pdf", "pdf"),
        "pdf_rotate": lambda: (pdf_rotate(files[0][1], int(f("degrees", 90)), str(p.get("pages", ""))), "application/pdf", "pdf"),
        "pdf_crop": lambda: (pdf_crop(files[0][1], f("left"), f("top"), f("right"), f("bottom")), "application/pdf", "pdf"),
        "doc_to_pdf": lambda: (doc_to_pdf(files[0][1], files[0][0]), "application/pdf", "pdf"),
    }
    from app.doctools import extra  # imported late: extra uses this module

    if op in extra.HANDLERS:
        if not files and op not in extra.NO_FILES:
            raise ToolError("Upload a file first.")
        return extra.HANDLERS[op](files, p)
    if op not in handlers:
        raise ToolError("Unknown tool.")
    if not files:
        raise ToolError("Upload a file first.")
    return handlers[op]()


def _photo(files: list[tuple[str, bytes]], p: dict[str, Any]) -> Image.Image:
    size = str(p.get("size", "35x45"))
    if size == "custom":
        w, h = float(p.get("width_mm") or 0), float(p.get("height_mm") or 0)
    elif size in PRESETS:
        w, h = PRESETS[size]
    else:
        raise ToolError("Unknown photo size.")
    return passport_photo(_one(files), w, h, float(p.get("zoom", 1) or 1), float(p.get("dx", 0) or 0), float(p.get("dy", 0) or 0), str(p.get("bg", "")))


def _pdf_or_image(pages: list[Image.Image], p: dict[str, Any]) -> Result:
    if str(p.get("format", "pdf")).lower() == "pdf":
        return _pdf_out(pages)
    if len(pages) == 1:
        return _out(pages[0], p)
    out = io.BytesIO()
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for i, pg in enumerate(pages, 1):
            data, _, ext = _out(pg, p)
            z.writestr(f"page-{i:02d}.{ext}", data)
    return out.getvalue(), "application/zip", "zip"


def _scan(files: list[tuple[str, bytes]], p: dict[str, Any], paper: str) -> Result:
    """Presets: nid / licence (front [+ back] on one page, real card size), passport (data page, 125 mm wide), birth (full page)."""
    kind = str(p.get("kind", "nid"))
    ims = _imgs(files)
    if bool(p.get("cleanup", False)):
        ims = [cleanup(i, str(p.get("mode", "color"))) for i in ims]
    if kind in ("nid", "licence", "license"):
        page = card_page(ims[0], ims[1] if len(ims) > 1 else None, paper)
    elif kind == "passport":
        page = card_page(ims[0], ims[1] if len(ims) > 1 else None, paper, width_mm=125)
    elif kind in ("birth", "certificate"):
        page = place_on_page(ims[:1], paper, 8)[0]
    else:
        raise ToolError("Unknown scan type.")
    return _pdf_or_image([page], p)
