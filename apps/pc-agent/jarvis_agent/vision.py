"""AI screen understanding (Phase 1): look at the window in front, find what is on it, click it, check the result.

Order of preference for finding things: the Windows accessibility tree (exact names, roles, enabled state) first, text recognition (Tesseract) second.
Only the FRONT window is captured (never the whole desktop), only if it belongs to an app the owner listed and is not sensitive.  A click needs ONE clear match
above a confidence bar; if there are several, or it is unsure, nothing is clicked.  The window is re-checked right before the click (it may have moved or changed).
Nothing leaves the computer from here: `screen_describe` only talks to a model when the owner turned `vision_upload` on, and then sends an image with secrets blacked out."""
from __future__ import annotations

import difflib
import io
import re
import unicodedata
from dataclasses import dataclass, field
from typing import Callable

from . import coords, devices, guard, inputctl, ocr, privacy

MAX_READ_CHARS = 3000
MAX_CANDIDATES = 8
DEFAULT_MIN_CONF = 0.6
TIE = 0.1
# set by the runner when a server vision endpoint exists; (png_bytes, question) -> text.  None = no model connected.
model_describer: Callable[[bytes, str], str] | None = None


@dataclass
class Observation:
    window: dict
    shot: coords.Shot
    monitor: dict | None
    png: bytes
    lines: list[dict]                       # OCR lines, image pixels
    words: list[dict]
    elements: list[dict] = field(default_factory=list)       # accessibility elements, SCREEN pixels
    ocr_used: bool = False

    @property
    def confidence(self) -> float:
        ws = [w["conf"] for w in self.words]
        return round(sum(ws) / len(ws) / 100, 2) if ws else 0.0


def _norm(s: str) -> str:
    s = unicodedata.normalize("NFKC", str(s)).casefold()
    return re.sub(r"[^\w ]+", "", s, flags=re.UNICODE).strip()


def _clip(rect, vs):
    l, t, r, b = rect
    vl, vt, vw, vh = vs
    l, t, r, b = max(l, vl), max(t, vt), min(r, vl + vw), min(b, vt + vh)
    if r - l < 8 or b - t < 8:
        raise guard.Refused("The front window is outside the visible screen")
    return l, t, r, b


def capture(cfg, use_ocr: bool = True) -> Observation:
    be = devices.get()
    win = inputctl._foreground_ok(cfg, be.foreground())                   # allowed app, not sensitive, emergency stop is checked by the callers
    vs = be.virtual_screen()
    l, t, r, b = _clip(tuple(win["rect"]), vs)
    png = be.grab((l - vs[0], t - vs[1], r - l, b - t))
    if not png.startswith(b"\x89PNG\r\n\x1a\n"):
        raise guard.Refused("The screenshot could not be taken")
    from PIL import Image
    iw, ih = Image.open(io.BytesIO(png)).size
    shot = coords.Shot((l, t), (r - l, b - t), (iw, ih))
    mon = coords.monitor_of(coords.center((l, t, r, b)), be.monitors())
    words = ocr.read_words(png) if use_ocr else []
    lines = ocr.group_lines(words) if words else []
    elements = [e for e in (be.ui_tree((l, t, r, b)) or []) if e.get("name")]
    return Observation(win, shot, mon, png, lines, words, elements, use_ocr)


def _candidates(ob: Observation, needle: str, role: str | None) -> list[dict]:
    want = _norm(needle)
    if not want:
        return []
    out: list[dict] = []
    for e in ob.elements:
        if role and role.lower() not in str(e.get("role", "")).lower():
            continue
        name = _norm(e["name"])
        if name == want:
            m = 1.0
        elif want in name:
            m = 0.7
        else:
            ratio = difflib.SequenceMatcher(None, want, name).ratio()
            m = ratio * 0.9 if ratio >= 0.85 else 0.0
        if m:
            out.append({"source": "accessibility", "role": e.get("role", ""), "text": e["name"], "box": tuple(e["rect"]), "score": m, "enabled": e.get("enabled", True)})
    if not role:                                                           # OCR knows no roles; asking for a role means "use the accessibility tree"
        for ln in ob.lines:
            ws = ln["words"]
            for n in range(1, min(4, len(ws)) + 1):
                for i in range(len(ws) - n + 1):
                    seg = ws[i:i + n]
                    txt = " ".join(w["text"] for w in seg)
                    nt = _norm(txt)
                    if nt == want:
                        m = 1.0
                    else:
                        ratio = difflib.SequenceMatcher(None, want, nt).ratio()
                        m = ratio * 0.9 if ratio >= 0.85 else 0.0
                    if m:
                        box = (min(w["box"][0] for w in seg), min(w["box"][1] for w in seg), max(w["box"][2] for w in seg), max(w["box"][3] for w in seg))
                        conf = sum(w["conf"] for w in seg) / len(seg) / 100
                        out.append({"source": "ocr", "role": "", "text": txt, "box": coords.to_screen(box, ob.shot), "score": m * conf, "enabled": True})
    out.sort(key=lambda c: (-c["score"], c["source"] != "accessibility"))
    kept: list[dict] = []
    for c in out:                                                         # the same thing seen twice (tree + OCR, or word + n-gram) counts once
        if all(coords.iou(c["box"], k["box"]) <= 0.3 for k in kept):
            kept.append(c)
    return kept


def find(ob: Observation, needle: str, role: str | None = None) -> list[dict]:
    """Best matches, best first.  Each has a 'score' 0-1 (match quality x recognition confidence)."""
    return _candidates(ob, needle, role)[:MAX_CANDIDATES]


def _pick(cands: list[dict], index: object, min_conf: float) -> dict:
    if not cands:
        raise guard.Refused("Nothing on the screen matches that text")
    top = cands[0]["score"]
    if top < min_conf:
        raise guard.Refused(f"Best match is not certain enough ({top:.2f} < {min_conf:.2f}) - nothing was clicked")
    near = sorted([c for c in cands if c["score"] >= top - TIE], key=lambda c: (c["box"][1], c["box"][0]))
    if index is None:
        if len(near) > 1:
            raise guard.Refused(f"{len(near)} elements match equally well - say which one (index 1-{len(near)}, top to bottom, left to right)")
        return near[0]
    if not isinstance(index, int) or isinstance(index, bool) or not 1 <= index <= len(near):
        raise guard.Refused(f"index must be 1-{len(near)}")
    return near[index - 1]


def _text(p: dict, key: str = "text") -> str:
    v = p.get(key)
    if not isinstance(v, str) or not v.strip() or len(v) > 120:
        raise guard.Refused(f"{key} must be 1-120 characters")
    return v.strip()


def _role(p: dict) -> str | None:
    v = p.get("role")
    if v is None:
        return None
    if not isinstance(v, str) or not re.fullmatch(r"[A-Za-z ]{1,30}", v):
        raise guard.Refused("role must be letters only")
    return v


def _head(ob: Observation) -> str:
    mon = f", monitor {ob.monitor['id']} at {int(ob.monitor.get('scale', 1) * 100)}%" if ob.monitor else ""
    return f"Window '{privacy.clean_text(str(ob.window.get('title', '')), 80)}' ({ob.window.get('process', '?')}){mon}"


def screen_read(cfg, p: dict) -> str:
    if p:
        raise guard.Refused("screen_read takes no parameters")
    ob = capture(cfg)
    hide = privacy.secret_boxes(ob.lines)
    keep = [ln["text"] for ln in ob.lines if not privacy.secret_line(ln["text"]) and not any(coords.iou(ln["box"], h) > 0.3 for h in hide)]
    body = privacy.clean_text("\n".join(keep), MAX_READ_CHARS)
    return f"{_head(ob)}; {len(ob.elements)} accessibility elements; text confidence {ob.confidence:.2f}\n{body or '(no text recognised)'}"


def screen_find(cfg, p: dict) -> str:
    needle, role = _text(p), _role(p)
    ob = capture(cfg)
    cands = find(ob, needle, role)
    if not cands:
        return f"{_head(ob)}: no match for '{privacy.clean_text(needle, 60)}'"
    rows = [f"{i}. '{privacy.clean_text(c['text'], 60)}' {c['source']} {c['role'] or ''} at {coords.center(c['box'])} score {c['score']:.2f}" + ("" if c["enabled"] else " DISABLED")
            for i, c in enumerate(cands, 1)]
    return f"{_head(ob)}: {len(cands)} match(es)\n" + "\n".join(rows)


def ui_click(cfg, p: dict) -> str:
    needle, role = _text(p), _role(p)
    mc = p.get("min_confidence", DEFAULT_MIN_CONF)
    if isinstance(mc, bool) or not isinstance(mc, (int, float)) or not 0.5 <= mc <= 1:
        raise guard.Refused("min_confidence must be 0.5-1")
    button = p.get("button", "left")
    if button not in ("left", "right"):
        raise guard.Refused("button must be left or right")
    ob = capture(cfg)
    hit = _pick(find(ob, needle, role), p.get("index"), float(mc))
    if not hit["enabled"]:
        raise guard.Refused("That element is disabled")
    x, y = coords.center(hit["box"])
    now = devices.get().foreground() or {}
    if (now.get("process"), now.get("title"), tuple(now.get("rect", ()))) != (ob.window.get("process"), ob.window.get("title"), tuple(ob.window["rect"])):
        raise guard.Refused("The window changed or moved after it was read - nothing was clicked")
    res = inputctl.input_control(cfg, {"action": "click", "x": x, "y": y, "button": button})
    return f"Clicked '{privacy.clean_text(hit['text'], 60)}' ({hit['source']}, score {hit['score']:.2f}): {res}"


def screen_describe(cfg, p: dict) -> str:
    q = p.get("question", "Describe what is on this window.")
    if not isinstance(q, str) or not q.strip() or len(q) > 300:
        raise guard.Refused("question must be 1-300 characters")
    ob = capture(cfg)
    local = screen_read(cfg, {}).split("\n", 1)
    if not getattr(cfg, "vision_upload", False):
        return f"{local[0]}\n(Local summary only: image upload is off.)\n{local[1][:800] if len(local) > 1 else ''}"
    if model_describer is None:
        return f"{local[0]}\n(No vision model is connected in this version; local text only.)\n{local[1][:800] if len(local) > 1 else ''}"
    safe = privacy.blackout(ob.png, privacy.secret_boxes(ob.lines))
    return privacy.clean_text(model_describer(safe, q.strip()), MAX_READ_CHARS)


# ---- verification ("did it really happen?") -------------------------------------------------------------------------------------------------------------------
def check_screen_text(cfg, spec: dict) -> tuple[bool, str]:
    need = spec.get("contains")
    if not isinstance(need, str) or not need.strip():
        return False, "No text to look for"
    try:
        ob = capture(cfg)
    except guard.Refused as e:
        return False, str(e)
    hit = _norm(need) in _norm(" ".join(ln["text"] for ln in ob.lines))
    return hit, "Text is on the screen" if hit else "Text is not on the screen"


def check_element(cfg, spec: dict) -> tuple[bool, str]:
    try:
        needle, role = _text(spec), _role(spec)
        c = find(capture(cfg), needle, role)
    except guard.Refused as e:
        return False, str(e)
    ok = bool(c) and c[0]["score"] >= DEFAULT_MIN_CONF
    return ok, f"Element found (score {c[0]['score']:.2f})" if ok else "Element not found"
