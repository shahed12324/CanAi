"""Local text recognition with the Tesseract program (no network, no Python OCR package).  Words come back with their pixel boxes and a 0-100 confidence.
Tesseract must be installed on the computer (Windows: https://github.com/UB-Mannheim/tesseract/wiki); when it is missing everything says so plainly instead of guessing."""
from __future__ import annotations

import io
import os
import shutil
import subprocess
import sys

from . import guard

TIMEOUT = 30
MAX_PIXELS = 40_000_000
_WIN_PATHS = (r"C:\Program Files\Tesseract-OCR\tesseract.exe", r"C:\Program Files (x86)\Tesseract-OCR\tesseract.exe")


def binary() -> str | None:
    env = os.environ.get("JARVIS_TESSERACT")
    if env and os.path.isfile(env):
        return env
    found = shutil.which("tesseract")
    if found:
        return found
    if sys.platform == "win32":
        return next((p for p in _WIN_PATHS if os.path.isfile(p)), None)
    return None


def available() -> bool:
    return binary() is not None


def _solid(d, i: int, a: int, b: int, off: int = 7) -> bool:
    """True when the run is part of a filled block (ink on both sides of it), e.g. a dark button with light text: that is background, not an outline."""
    if i - off < 0 or i + off >= d.shape[0]:
        return False
    return bool(d[i - off, a:b].mean() > 0.5 and d[i + off, a:b].mean() > 0.5)


def _strip_borders(png: bytes, min_run: int = 30) -> bytes:
    """Buttons and text boxes have outlines that make Tesseract skip the words inside.  Long straight runs of ink (>= min_run pixels, horizontal or vertical) are
    painted over with the background, one pixel wider on every side to catch soft (anti-aliased) edges; text strokes are shorter than that.
    Returns the PNG unchanged if the picture cannot be processed."""
    try:
        import numpy as np
        from PIL import Image
        g = np.asarray(Image.open(io.BytesIO(png)).convert("L"), dtype=np.uint8)
        light_bg = g.mean() > 128
        ink = g < 190 if light_bg else g > 65
        mask = np.zeros(g.shape, dtype=bool)
        for axis in (0, 1):
            d = ink if axis == 1 else ink.T
            m = mask if axis == 1 else mask.T
            for i in range(d.shape[0]):
                if d[i].sum() < min_run:
                    continue
                edges = np.flatnonzero(np.diff(np.concatenate(([0], d[i].astype(np.int8), [0]))))
                for a, b in zip(edges[::2], edges[1::2]):
                    if b - a >= min_run and not _solid(d, i, a, b):
                        m[i, a:b] = True
        grown = mask.copy()
        for dy, dx in ((1, 0), (-1, 0), (0, 1), (0, -1)):
            grown |= np.roll(mask, (dy, dx), axis=(0, 1))
        out = g.copy()
        out[grown] = 255 if light_bg else 0
        buf = io.BytesIO()
        Image.fromarray(out).save(buf, "PNG")
        return buf.getvalue()
    except Exception:
        return png


def read_words(png: bytes, lang: str = "eng") -> list[dict]:
    """[{"text", "conf" (0-100), "box": (l, t, r, b) in IMAGE pixels, "line": (block, par, line)}] for every recognised word."""
    exe = binary()
    if not exe:
        raise guard.Refused("Text recognition is not available: install Tesseract OCR (see docs/DESKTOP_INSTALL.md)")
    from PIL import Image
    im = Image.open(io.BytesIO(png))
    if im.width * im.height > MAX_PIXELS:
        raise guard.Refused("The screenshot is too large to read")
    flags = 0x08000000 if sys.platform == "win32" else 0                                    # CREATE_NO_WINDOW
    try:
        r = subprocess.run([exe, "stdin", "stdout", "-l", lang, "--psm", "11", "tsv"], input=_strip_borders(png), capture_output=True, timeout=TIMEOUT, creationflags=flags)
    except subprocess.TimeoutExpired:
        raise guard.Refused("Text recognition took too long") from None
    if r.returncode != 0:
        raise guard.Refused("Text recognition failed")
    out: list[dict] = []
    for row in r.stdout.decode("utf-8", "replace").splitlines()[1:]:
        c = row.split("\t")
        if len(c) < 12 or c[0] != "5":
            continue
        text = c[11].strip()
        try:
            conf = float(c[10])
            l, t, w, h = (int(c[i]) for i in (6, 7, 8, 9))
        except ValueError:
            continue
        if text and conf >= 0 and w > 0 and h > 0:
            out.append({"text": text, "conf": conf, "box": (l, t, l + w, t + h), "line": (c[2], c[3], c[4])})
    return out


def group_lines(words: list[dict]) -> list[dict]:
    """Words of one printed line joined: [{"text", "conf" (mean), "box"}], top-to-bottom."""
    by: dict[tuple, list[dict]] = {}
    for w in words:
        by.setdefault(w["line"], []).append(w)
    lines = []
    for ws in by.values():
        ws.sort(key=lambda w: w["box"][0])
        box = (min(w["box"][0] for w in ws), min(w["box"][1] for w in ws), max(w["box"][2] for w in ws), max(w["box"][3] for w in ws))
        lines.append({"text": " ".join(w["text"] for w in ws), "conf": sum(w["conf"] for w in ws) / len(ws), "box": box, "words": ws})
    lines.sort(key=lambda ln: (ln["box"][1], ln["box"][0]))
    return lines
