"""Screen awareness: a screenshot saved INSIDE an approved folder (evidence for plans), never returned as image data, never uploaded by the agent.
Reading what is on it (OCR / vision) is a separate, explicit step done on the server with the person's AI provider; nothing here does that."""
from __future__ import annotations

import hashlib
import os
import re
import tempfile

from . import devices, fileops, guard

MAX_PNG_BYTES = 8 * 1024 * 1024
MAX_DIM = 8192
# windows we refuse to capture or type into: sign-in / secrets / security prompts.  A word list is a guard rail, not a guarantee.
SENSITIVE = re.compile(r"password|passcode|passwd|sign[ -]?in|log[ -]?in|credential|vault|keepass|bitwarden|1password|windows security|user account control|"
                       r"private browsing|incognito|পাসওয়ার্ড|লগ ?ইন|সাইন ?ইন|কার্ড|ব্যাংক|bank|payment|wallet|"
                       r"paypal|stripe|checkout|billing|credit card|debit card|\bcvv\b|\botp\b|authenticator|two[- ]factor|2fa|recovery (key|code)|seed phrase|private key|ssh key|"
                       r"bkash|nagad|upay|binance|metamask|coinbase|পেমেন্ট|ওয়ালেট|বিকাশ|নগদ|ওটিপি|পিন", re.I)
PNG_SIG = b"\x89PNG\r\n\x1a\n"


def sensitive_window(win: dict | None) -> bool:
    return bool(win) and bool(SENSITIVE.search(str(win.get("title", "")) + " " + str(win.get("process", ""))))


def _region(p: dict, size: tuple[int, int]):
    r = p.get("region")
    if r is None:
        return None
    if not (isinstance(r, list) and len(r) == 4 and all(isinstance(v, int) and not isinstance(v, bool) for v in r)):
        raise guard.Refused("region must be integers [x, y, width, height]")
    x, y, w, h = r
    if x < 0 or y < 0 or w < 1 or h < 1 or w > MAX_DIM or h > MAX_DIM or x + w > size[0] or y + h > size[1]:
        raise guard.Refused("region is outside the screen or unusually large")
    return (x, y, w, h)


def screenshot(cfg, p: dict) -> str:
    parent, name = guard.parent_in_roots(p.get("path"), cfg.roots)
    if not name.lower().endswith(".png"):
        raise guard.Refused("The file name must end with .png")
    be = devices.get()
    win = be.foreground()
    if sensitive_window(win):
        raise guard.Refused("The front window is sensitive (password/bank/security) — screenshot not taken")
    region = _region(p, be.screen_size())
    target = os.path.join(parent, name)
    bak = fileops._ready_target(target, p.get("overwrite") is True)
    data = be.grab(region)
    if not data.startswith(PNG_SIG) or len(data) > MAX_PNG_BYTES:
        raise guard.Refused("The screenshot could not be captured properly or is too large")
    fd, tmp = tempfile.mkstemp(dir=parent, prefix=".canai-")
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
        os.replace(tmp, target)
    except BaseException:
        if os.path.exists(tmp):
            os.remove(tmp)
        raise
    return f"Screenshot saved ({len(data) // 1024} KB, sha256 {hashlib.sha256(data).hexdigest()[:12]})" + (f"; backup of the previous one: {os.path.basename(bak)}" if bak else "")
