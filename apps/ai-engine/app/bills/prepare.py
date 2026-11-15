"""Turn an uploaded bill (photo or PDF) into what a vision model can read: bounded, re-encoded JPEG images,
or the text layer of a PDF. Nothing here trusts the declared mime type, the file name, or the document's content."""

from __future__ import annotations

import base64
import io
from dataclasses import dataclass, field

from PIL import Image, ImageOps

MAX_BYTES = 6 * 1024 * 1024
MAX_SIDE = 1600
MAX_PIXELS = 40_000_000
MAX_PDF_PAGES = 3
MIN_TEXT_CHARS = 80
MAX_TEXT_CHARS = 12_000

Image.MAX_IMAGE_PIXELS = MAX_PIXELS  # a decompression bomb raises instead of eating memory


class Unsupported(Exception):
    """The file cannot be scanned. `code` is a stable machine word."""

    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


@dataclass
class Prepared:
    images: list[dict[str, str]] = field(
        default_factory=list
    )  # [{"mime": "image/jpeg", "data": b64}]
    text: str = ""
    pages: int = 0


def sniff(data: bytes) -> str | None:
    if data[:3] == b"\xff\xd8\xff":
        return "image/jpeg"
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return "image/png"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "image/webp"
    if data[:5] == b"%PDF-":
        return "application/pdf"
    return None


def _jpeg(img: Image.Image) -> dict[str, str]:
    img = ImageOps.exif_transpose(img) or img
    if img.mode not in ("RGB", "L"):
        bg = Image.new("RGB", img.size, "white")
        rgba = img.convert("RGBA")
        bg.paste(rgba, mask=rgba.split()[-1])
        img = bg
    else:
        img = img.convert("RGB")
    img.thumbnail((MAX_SIDE, MAX_SIDE))
    out = io.BytesIO()
    img.save(out, "JPEG", quality=85)  # re-encoding drops EXIF / GPS / embedded profiles
    return {"mime": "image/jpeg", "data": base64.b64encode(out.getvalue()).decode("ascii")}


def prepare(
    data: bytes,
    declared_mime: str,
    max_pages: int = MAX_PDF_PAGES,
    max_text: int = MAX_TEXT_CHARS,
) -> Prepared:
    if not data:
        raise Unsupported("empty")
    if len(data) > MAX_BYTES:
        raise Unsupported("too_large")
    kind = sniff(data)
    if kind is None or kind != declared_mime:
        raise Unsupported("unsupported_type")
    if kind == "application/pdf":
        return _pdf(data, max_pages, max_text)
    try:
        with Image.open(io.BytesIO(data)) as im:
            im.load()
            return Prepared(images=[_jpeg(im)], pages=1)
    except (OSError, Image.DecompressionBombError, ValueError):
        raise Unsupported("unreadable_image") from None


def _pdf(data: bytes, max_pages: int, max_text: int) -> Prepared:
    from pypdf import PdfReader
    from pypdf.errors import PyPdfError

    try:
        reader = PdfReader(io.BytesIO(data))
        if reader.is_encrypted:
            raise Unsupported("encrypted_pdf")
        total = len(reader.pages)
        if total == 0:
            raise Unsupported("unreadable_pdf")
        text = "\n".join(
            (reader.pages[i].extract_text() or "") for i in range(min(total, max_pages))
        )
    except Unsupported:
        raise
    except (PyPdfError, ValueError, KeyError, OSError, RecursionError):
        raise Unsupported("unreadable_pdf") from None
    text = text.strip()
    if len(text) >= MIN_TEXT_CHARS:
        return Prepared(text=text[:max_text], pages=min(total, max_pages))
    return _scanned_pdf(data, total, max_pages)


def _scanned_pdf(data: bytes, total: int, max_pages: int) -> Prepared:
    import pypdfium2 as pdfium

    try:
        pdf = pdfium.PdfDocument(data)
        images = []
        for i in range(min(total, max_pages)):
            page = pdf[i]
            w, h = page.get_size()
            scale = min(2.0, MAX_SIDE / max(w, h, 1))
            images.append(_jpeg(page.render(scale=max(0.5, scale)).to_pil()))
        return Prepared(images=images, pages=len(images))
    except (pdfium.PdfiumError, OSError, ValueError):
        raise Unsupported("unreadable_pdf") from None
