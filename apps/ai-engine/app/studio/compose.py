"""Design composer: background picture (or brand gradient) + brand logo + real, font-drawn text.

Why text is drawn here and not by the image model: image models misspell, and Bengali is where
they fail the most. Text is shaped with Raqm (conjuncts such as ক্ষ, স্ত্র come out right) using a
bundled Noto Sans Bengali (SIL OFL), one font file for the Bengali script and one for Latin, so a
line like "ঈদ অফার 50% ছাড়" is drawn run by run with the right font for each run.

Pure functions: bytes in, bytes out. Nothing is stored and nothing touches the network."""

from __future__ import annotations

import io
import re
import unicodedata
from dataclasses import dataclass, field
from functools import lru_cache
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont, ImageOps, features

FONT_DIR = Path(__file__).parent / "fonts"

# name -> (width, height). Every size is a real placement on a real platform.
SIZES: dict[str, tuple[int, int]] = {
    "post": (1080, 1080),  # Facebook/Instagram square post
    "portrait": (1080, 1350),  # Instagram/Facebook 4:5 feed post
    "story": (1080, 1920),  # Stories / Reels cover
    "cover": (1640, 624),  # Facebook page cover (820x312 at 2x)
    "ad": (1200, 628),  # Link ad / shared-link image
}
THEMES = ("dark", "light", "brand")
MAX_BASE_PIXELS = 25_000_000
MAX_LOGO_PIXELS = 4_000_000
HEX_RE = re.compile(r"\A#[0-9a-fA-F]{6}\Z")
_CONTROL = re.compile(r"[\x00-\x08\x0b-\x1f\x7f-\x9f\u2028\u2029\u202a-\u202e\u2066-\u2069]")


class ComposeError(Exception):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


@dataclass(frozen=True)
class Brand:
    primary: str = "#1f3a8a"
    accent: str = "#f59e0b"
    name: str = ""
    logo: bytes | None = None
    footer: str = ""


@dataclass(frozen=True)
class Copy:
    headline: str = ""
    subline: str = ""
    cta: str = ""
    theme: str = "dark"
    extra: dict[str, str] = field(default_factory=dict)


def clean_line(text: str, limit: int) -> str:
    """One line of printable text: no control or bidi-override characters, single spaces."""
    text = _CONTROL.sub(" ", text or "")
    text = re.sub(r"\s+", " ", text).strip()
    return unicodedata.normalize("NFC", text)[:limit]


# ───────────────────────────────────────────────────────────────────────────── fonts / text
def _is_bengali(ch: str) -> bool:
    o = ord(ch)
    return 0x0980 <= o <= 0x09FF or o in (0x0964, 0x0965, 0x200C, 0x200D)


@lru_cache(maxsize=32)
def _font(script: str, bold: bool, size: int) -> ImageFont.FreeTypeFont:
    path = FONT_DIR / f"NotoSansBengali-{script}-{'700' if bold else '400'}.ttf"
    return ImageFont.truetype(str(path), size, layout_engine=ImageFont.Layout.RAQM)


def runs(text: str) -> list[tuple[str, str]]:
    """Split text into (chunk, script) so each chunk is shaped by the right font.

    Bengali letters, vowel signs, digits, danda and the joiners go to the Bengali font (a cluster
    is never cut). Everything else - Latin, ASCII digits, spaces and punctuation - goes to the
    Latin font, which is the one that actually has glyphs for ':', '%', '-' and so on."""
    out: list[list[str]] = []
    for ch in text:
        script = "bengali" if _is_bengali(ch) else "latin"
        if out and out[-1][1] == script:
            out[-1][0] += ch
        else:
            out.append([ch, script])
    return [(a, b) for a, b in out]


def text_width(text: str, size: int, bold: bool) -> float:
    return sum(_font(s, bold, size).getlength(t) for t, s in runs(text))


def draw_text(
    d: ImageDraw.ImageDraw,
    xy: tuple[float, float],
    text: str,
    size: int,
    bold: bool,
    fill: tuple[int, int, int, int],
) -> None:
    x, y = xy
    for chunk, script in runs(text):
        f = _font(script, bold, size)
        d.text((x, y), chunk, font=f, fill=fill)
        x += f.getlength(chunk)


def wrap(text: str, size: int, bold: bool, max_w: int) -> list[str]:
    lines: list[str] = []
    cur = ""
    for word in text.split(" "):
        trial = f"{cur} {word}".strip()
        if cur and text_width(trial, size, bold) > max_w:
            lines.append(cur)
            cur = word
        else:
            cur = trial
    if cur:
        lines.append(cur)
    return lines


def fit(
    text: str, bold: bool, max_w: int, max_h: int, start: int, floor: int, max_lines: int
) -> tuple[int, list[str]]:
    """Largest font size (start..floor) at which the text fits the box in max_lines lines."""
    size = start
    while size >= floor:
        lines = wrap(text, size, bold, max_w)
        if len(lines) <= max_lines and len(lines) * int(size * 1.35) <= max_h:
            if all(text_width(ln, size, bold) <= max_w for ln in lines):
                return size, lines
        size -= 2
    lines = wrap(text, floor, bold, max_w)[:max_lines]
    return floor, lines


# ───────────────────────────────────────────────────────────────────────────── colour
def rgb(hex_color: str) -> tuple[int, int, int]:
    h = hex_color.lstrip("#")
    return int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)


def luminance(c: tuple[int, int, int]) -> float:
    def ch(v: int) -> float:
        s = v / 255
        return s / 12.92 if s <= 0.03928 else ((s + 0.055) / 1.055) ** 2.4

    return 0.2126 * ch(c[0]) + 0.7152 * ch(c[1]) + 0.0722 * ch(c[2])


def contrast(a: tuple[int, int, int], b: tuple[int, int, int]) -> float:
    la, lb = sorted((luminance(a), luminance(b)), reverse=True)
    return (la + 0.05) / (lb + 0.05)


def readable_on(bg: tuple[int, int, int]) -> tuple[int, int, int]:
    white, ink = (255, 255, 255), (17, 24, 39)
    return white if contrast(white, bg) >= contrast(ink, bg) else ink


def shade(c: tuple[int, int, int], k: float) -> tuple[int, int, int]:
    return tuple(max(0, min(255, int(v * k))) for v in c)  # type: ignore[return-value]


# ───────────────────────────────────────────────────────────────────────────── pictures
def open_image(data: bytes, max_pixels: int, code: str) -> Image.Image:
    try:
        Image.open(io.BytesIO(data)).verify()
        opened = Image.open(io.BytesIO(data))
    except Exception:  # noqa: BLE001 - any decoder failure means "not a usable picture"
        raise ComposeError(code) from None
    im: Image.Image = opened
    if im.format not in ("PNG", "JPEG", "WEBP") or im.width * im.height > max_pixels:
        raise ComposeError(code)
    im = ImageOps.exif_transpose(im)
    return im.convert("RGBA")


def gradient(
    size: tuple[int, int], a: tuple[int, int, int], b: tuple[int, int, int]
) -> Image.Image:
    w, h = size
    small = Image.new("RGB", (2, 2))
    small.putdata([a, a, b, b])  # top row a, bottom row b, smoothly stretched below
    base = small.resize((w, h), Image.Resampling.BILINEAR).convert("RGBA")
    return base


def scrim(size: tuple[int, int], strength: int) -> Image.Image:
    """Dark fade, clear at the top and strongest at the bottom, so light text reads on any photo."""
    w, h = size
    alpha = Image.linear_gradient("L").resize((w, h)).point(lambda v: int(v / 255 * strength))
    black = Image.new("RGBA", size, (0, 0, 0, 255))
    black.putalpha(alpha)
    return black


def rounded(d: ImageDraw.ImageDraw, box: tuple[int, int, int, int], r: int, fill: object) -> None:
    d.rounded_rectangle(box, radius=r, fill=fill)  # type: ignore[arg-type]


# ───────────────────────────────────────────────────────────────────────────── the design
def shaping_ok() -> bool:
    """Bengali conjuncts need the Raqm layout engine (needs libfribidi on the server)."""
    return bool(features.check("raqm"))


def render(size_key: str, copy: Copy, brand: Brand, base: bytes | None) -> Image.Image:
    texts = (copy.headline, copy.subline, copy.cta, brand.name, brand.footer)
    if any(_is_bengali(ch) for t in texts for ch in t) and not shaping_ok():
        # Without shaping Bengali comes out broken: refuse rather than draw wrong letters.
        raise ComposeError("no_raqm")
    if size_key not in SIZES:
        raise ComposeError("bad_size")
    if copy.theme not in THEMES:
        raise ComposeError("bad_theme")
    if not HEX_RE.match(brand.primary) or not HEX_RE.match(brand.accent):
        raise ComposeError("bad_color")
    w, h = SIZES[size_key]
    u = min(w, h)  # layout unit: everything scales with the shorter side
    primary, accent = rgb(brand.primary), rgb(brand.accent)

    if base:
        photo = open_image(base, MAX_BASE_PIXELS, "bad_base")
        canvas = ImageOps.fit(photo, (w, h), Image.Resampling.LANCZOS, centering=(0.5, 0.5))
        has_photo = True
    else:
        light = copy.theme == "light"
        canvas = gradient(
            (w, h),
            (245, 246, 250) if light else primary,
            (222, 226, 238) if light else shade(primary, 0.55),
        )
        has_photo = False

    wide = w / h > 1.4
    if has_photo and copy.theme != "light":
        canvas = Image.alpha_composite(canvas, scrim((w, h), 215))
    elif has_photo:
        veil = Image.new("RGBA", (w, h), (255, 255, 255, 150))
        canvas = Image.alpha_composite(canvas, veil)

    on_dark = not (copy.theme == "light")
    bg_mean = (
        shade(primary, 0.55)
        if not has_photo and on_dark
        else (30, 30, 30)
        if on_dark
        else (245, 246, 250)
    )
    ink = readable_on(bg_mean)
    ink_rgba = (*ink, 255)
    soft_rgba = (*ink, 215)

    layer = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    margin = int(u * 0.075)
    box_w = int(w * 0.62) - margin if wide else w - 2 * margin

    # logo, top-left
    top = margin
    logo_bottom = top
    if brand.logo:
        logo = open_image(brand.logo, MAX_LOGO_PIXELS, "bad_logo")
        max_h, max_w = int(h * (0.2 if wide else 0.085)), int(w * 0.3)
        logo.thumbnail((max_w, max_h), Image.Resampling.LANCZOS)
        chip_pad = int(u * 0.012)
        chip = (
            margin - chip_pad,
            top - chip_pad,
            margin + logo.width + chip_pad,
            top + logo.height + chip_pad,
        )
        rounded(d, chip, int(u * 0.015), (255, 255, 255, 235))
        layer.paste(logo, (margin, top), logo)
        logo_bottom = chip[3]
    elif brand.name:
        nm = clean_line(brand.name, 40)
        size = int(u * 0.034)
        draw_text(d, (margin, top), nm, size, True, ink_rgba)
        logo_bottom = top + int(size * 1.4)

    footer_h = 0
    foot = clean_line(brand.footer, 100)
    if foot:
        fs = max(18, int(u * 0.024))
        footer_h = int(fs * 1.6)
        fw = text_width(foot, fs, False)
        while fw > w - 2 * margin and fs > 14:
            fs -= 1
            fw = text_width(foot, fs, False)
        draw_text(d, ((w - fw) / 2, h - margin * 0.55 - fs * 1.35), foot, fs, False, soft_rgba)

    # text block, anchored low on photos and centred on plain gradients
    head = clean_line(copy.headline, 90)
    sub = clean_line(copy.subline, 180)
    cta = clean_line(copy.cta, 40)
    avail_top = logo_bottom + int(u * 0.04)
    avail_bottom = h - margin - footer_h
    avail_h = max(avail_bottom - avail_top, 100)

    blocks: list[tuple[str, int, bool, list[str]]] = []
    used = 0
    gap = int(u * 0.03)
    if head:
        hs, hl = fit(head, True, box_w, int(avail_h * 0.5), int(u * 0.115), int(u * 0.05), 4)
        blocks.append(("head", hs, True, hl))
        used += len(hl) * int(hs * 1.35)
    if sub:
        ss, sl = fit(sub, False, box_w, int(avail_h * 0.28), int(u * 0.048), int(u * 0.03), 4)
        blocks.append(("sub", ss, False, sl))
        used += len(sl) * int(ss * 1.4) + gap
    cta_size = int(u * 0.04)
    cta_pad_x, cta_pad_y = int(u * 0.04), int(u * 0.022)
    cta_h = 0
    if cta:
        cta_h = int(cta_size * 1.35) + 2 * cta_pad_y
        used += cta_h + gap * 2

    y = avail_top + max(0, (avail_h - used) // 2) if not has_photo else avail_bottom - used
    y = max(y, avail_top)
    for kind, size, bold, lines in blocks:
        step = int(size * (1.35 if kind == "head" else 1.4))
        for ln in lines:
            draw_text(d, (margin, y), ln, size, bold, ink_rgba if kind == "head" else soft_rgba)
            y += step
        y += gap
    if cta:
        y += gap
        tw = text_width(cta, cta_size, True)
        btn = (margin, y, margin + int(tw) + 2 * cta_pad_x, y + cta_h)
        rounded(d, btn, cta_h // 2, (*accent, 255))
        draw_text(
            d, (margin + cta_pad_x, y + cta_pad_y), cta, cta_size, True, (*readable_on(accent), 255)
        )

    # accent bar at the left edge: a quiet brand signature
    d.rectangle((0, 0, int(u * 0.016), h), fill=(*accent, 255))
    return Image.alpha_composite(canvas, layer).convert("RGB")


def encode(im: Image.Image, fmt: str) -> tuple[bytes, str]:
    out = io.BytesIO()
    if fmt == "jpeg":
        im.save(out, "JPEG", quality=90, optimize=True)
        return out.getvalue(), "image/jpeg"
    im.save(out, "PNG", optimize=True)
    return out.getvalue(), "image/png"


def render_caption(size_key: str, text: str) -> Image.Image:
    """A transparent full-frame picture holding one subtitle: white text on a soft dark box."""
    text = clean_line(text, 200)
    if any(_is_bengali(ch) for ch in text) and not shaping_ok():
        raise ComposeError("no_raqm")
    if size_key not in SIZES:
        raise ComposeError("bad_size")
    w, h = SIZES[size_key]
    u = min(w, h)
    canvas = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    if not text:
        return canvas
    pad = int(u * 0.03)
    max_w = int(w * 0.86) - 2 * pad
    size, lines = fit(text, True, max_w, int(u * 0.26), int(u * 0.052), int(u * 0.032), 3)
    line_h = int(size * 1.35)
    box_w = max(int(text_width(ln, size, True)) for ln in lines) + 2 * pad
    box_h = line_h * len(lines) + 2 * pad
    x0 = (w - box_w) // 2
    y1 = h - int(h * 0.1)
    y0 = y1 - box_h
    d = ImageDraw.Draw(canvas)
    rounded(d, (x0, y0, x0 + box_w, y1), int(u * 0.02), (0, 0, 0, 170))
    y = y0 + pad
    for ln in lines:
        lw = text_width(ln, size, True)
        draw_text(d, ((w - lw) / 2, y), ln, size, True, (255, 255, 255, 255))
        y += line_h
    return canvas
