"""Keep secrets off the screen-reading path: password fields are never read, secret-looking text is blacked out of any image that may leave the computer,
and text results are redacted.  A pattern list is a guard rail, not a guarantee."""
from __future__ import annotations

import io
import re

from .untrusted import SECRETS, redact

_LABEL = re.compile(r"password|passcode|passwd|\bpin\b|\bcvv\b|\bcvc\b|security code|one[- ]time|\botp\b|recovery key|secret", re.I)
_SECRET_TEXT = [rx for rx, _ in SECRETS]


def secret_line(text: str) -> bool:
    return bool(_LABEL.search(text)) or any(rx.search(text) for rx in _SECRET_TEXT)


def clean_text(text: str, limit: int = 4000) -> str:
    return redact(text, limit)


def secret_boxes(lines: list[dict], elements: list[dict] | None = None) -> list[tuple[int, int, int, int]]:
    """IMAGE-pixel boxes to hide: lines that look secret, plus whatever sits to the right of / below a secret label (where the value is typed)."""
    boxes = []
    for i, ln in enumerate(lines):
        if secret_line(ln["text"]):
            l, t, r, b = ln["box"]
            h = b - t
            boxes.append((l, t, r, b))
            boxes.append((l, t - h // 2, r + 600, b + h * 2))             # the value field next to / under the label
    return boxes


def blackout(png: bytes, boxes: list[tuple[int, int, int, int]]) -> bytes:
    from PIL import Image, ImageDraw
    im = Image.open(io.BytesIO(png)).convert("RGB")
    d = ImageDraw.Draw(im)
    for b in boxes:
        d.rectangle(b, fill=(0, 0, 0))
    out = io.BytesIO()
    im.save(out, "PNG")
    return out.getvalue()
