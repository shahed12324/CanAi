"""The only place that touches the screen, mouse, keyboard, foreground window and the web.  Everything else (policy, limits, approvals) lives in screen.py /
inputctl.py / browserx.py and is tested with a fake `Backends`.  The real backend below is NOT tested here (no display, no Windows): run it on a real machine before trusting it."""
from __future__ import annotations

import io
import re
import sys
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from typing import Callable

from . import guard


class Backends:
    """Interface.  Every method may raise guard.Refused with a plain-language reason."""

    def screen_size(self) -> tuple[int, int]:
        raise guard.Refused("This backend does not support the screen")

    def grab(self, region: tuple[int, int, int, int] | None) -> bytes:           # PNG bytes
        raise guard.Refused("This backend does not support screenshots")

    def virtual_screen(self) -> tuple[int, int, int, int]:                        # (left, top, width, height) of all monitors together; left/top may be NEGATIVE
        w, h = self.screen_size()
        return (0, 0, w, h)

    def monitors(self) -> list[dict]:                                              # [{"id", "rect": (l, t, r, b) absolute, "scale": 1.0 = 100 %, "primary"}]
        l, t, w, h = self.virtual_screen()
        return [{"id": 1, "rect": (l, t, l + w, t + h), "scale": 1.0, "primary": True}]

    def ui_tree(self, rect: tuple[int, int, int, int]) -> list[dict]:               # accessibility elements inside rect: {"role", "name", "rect", "enabled"}; [] = unavailable
        return []

    def foreground(self) -> dict | None:                                           # {"process": "notepad", "title": "...", "rect": (l, t, r, b)}
        raise guard.Refused("The front window is unknown")

    def move(self, x: int, y: int) -> None: raise guard.Refused("Mouse is not supported")
    def click(self, x: int, y: int, button: str, double: bool) -> None: raise guard.Refused("Mouse is not supported")
    def scroll(self, dy: int) -> None: raise guard.Refused("Mouse is not supported")
    def type_text(self, text: str) -> None: raise guard.Refused("Keyboard is not supported")
    def press(self, keys: list[str]) -> None: raise guard.Refused("Keyboard is not supported")

    def fetch_page(self, url: str, allowed_host: Callable[[str], bool], max_bytes: int, timeout: float) -> dict:   # {"final_url", "title", "text"}
        raise guard.Refused("Web reading is not supported")


class _Text(HTMLParser):
    SKIP = {"script", "style", "noscript", "template", "svg"}

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str] = []
        self.title = ""
        self._skip = 0
        self._in_title = False

    def handle_starttag(self, tag, attrs):
        if tag in self.SKIP:
            self._skip += 1
        elif tag == "title":
            self._in_title = True
        elif tag in ("p", "br", "div", "li", "tr", "h1", "h2", "h3", "h4"):
            self.parts.append("\n")

    def handle_endtag(self, tag):
        if tag in self.SKIP and self._skip:
            self._skip -= 1
        elif tag == "title":
            self._in_title = False

    def handle_data(self, data):
        if self._skip:
            return
        if self._in_title:
            self.title += data
        else:
            self.parts.append(data)


def html_to_text(html: str) -> tuple[str, str]:
    p = _Text()
    p.feed(html)
    text = re.sub(r"[ \t\r\f\v]+", " ", "".join(p.parts))
    text = re.sub(r"\n\s*\n+", "\n", text).strip()
    return re.sub(r"\s+", " ", p.title).strip(), text


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class RealBackends(Backends):
    """mss (screenshots), pyautogui (mouse/keyboard), ctypes (foreground window on Windows), urllib (plain pages; JavaScript pages are NOT rendered)."""

    def __init__(self) -> None:
        _make_dpi_aware()

    def _need(self, mod: str, pip: str):
        try:
            return __import__(mod)
        except ImportError:
            raise guard.Refused(f"{pip} is not installed (pip install {pip})") from None

    def screen_size(self) -> tuple[int, int]:
        mss = self._need("mss", "mss")
        with mss.mss() as s:
            m = s.monitors[0]
            return int(m["width"]), int(m["height"])

    def grab(self, region):
        mss = self._need("mss", "mss")
        with mss.mss() as s:
            m = s.monitors[0]
            box = m if region is None else {"left": m["left"] + region[0], "top": m["top"] + region[1], "width": region[2], "height": region[3]}
            shot = s.grab(box)
            return mss.tools.to_png(shot.rgb, shot.size)

    def virtual_screen(self):
        mss = self._need("mss", "mss")
        with mss.mss() as s:
            m = s.monitors[0]
            return int(m["left"]), int(m["top"]), int(m["width"]), int(m["height"])

    def monitors(self):
        mss = self._need("mss", "mss")
        with mss.mss() as s:
            mons = [(i, m) for i, m in enumerate(s.monitors) if i > 0] or [(0, s.monitors[0])]
        out = []
        for i, m in mons:
            r = (int(m["left"]), int(m["top"]), int(m["left"] + m["width"]), int(m["top"] + m["height"]))
            out.append({"id": i, "rect": r, "scale": _monitor_scale(r), "primary": i == 1})
        return out

    def ui_tree(self, rect):
        return _uia_elements(rect)                                                  # Windows UI Automation if the optional `uiautomation` package is installed

    def foreground(self):
        if sys.platform != "win32":
            raise guard.Refused("Detecting the front window is currently available on Windows only")
        import ctypes
        from ctypes import wintypes
        u, k = ctypes.windll.user32, ctypes.windll.kernel32
        hwnd = u.GetForegroundWindow()
        if not hwnd:
            return None
        n = u.GetWindowTextLengthW(hwnd)
        buf = ctypes.create_unicode_buffer(n + 1)
        u.GetWindowTextW(hwnd, buf, n + 1)
        rect = wintypes.RECT()
        u.GetWindowRect(hwnd, ctypes.byref(rect))
        pid = wintypes.DWORD()
        u.GetWindowThreadProcessId(hwnd, ctypes.byref(pid))
        h = k.OpenProcess(0x1000, False, pid.value)                      # PROCESS_QUERY_LIMITED_INFORMATION
        name = ""
        if h:
            size = wintypes.DWORD(520)
            path = ctypes.create_unicode_buffer(size.value)
            if k.QueryFullProcessImageNameW(h, 0, path, ctypes.byref(size)):
                name = path.value.replace("\\", "/").rsplit("/", 1)[-1]
            k.CloseHandle(h)
        return {"process": name, "title": buf.value, "rect": (rect.left, rect.top, rect.right, rect.bottom)}

    def _gui(self):
        g = self._need("pyautogui", "pyautogui")
        g.FAILSAFE = True                                                 # mouse into a screen corner aborts
        g.PAUSE = 0.05
        return g

    def move(self, x, y): self._gui().moveTo(x, y, duration=0.1)
    def click(self, x, y, button, double): self._gui().click(x, y, clicks=2 if double else 1, button=button)
    def scroll(self, dy): self._gui().scroll(dy)
    def type_text(self, text): self._gui().write(text, interval=0.02)
    def press(self, keys): self._gui().hotkey(*keys)

    def fetch_page(self, url, allowed_host, max_bytes, timeout):
        opener = urllib.request.build_opener(_NoRedirect)
        cur = url
        for _ in range(4):
            req = urllib.request.Request(cur, headers={"User-Agent": "jarvis-agent/1", "Accept": "text/html,text/plain;q=0.8"})
            try:
                with opener.open(req, timeout=timeout) as r:
                    raw = r.read(max_bytes + 1)
                    ctype = r.headers.get("Content-Type", "")
                    break
            except urllib.error.HTTPError as e:
                if e.code in (301, 302, 303, 307, 308) and e.headers.get("Location"):
                    nxt = urllib.parse.urljoin(cur, e.headers["Location"])
                    host = (urllib.parse.urlsplit(nxt).hostname or "").lower()
                    if urllib.parse.urlsplit(nxt).scheme != "https" or not allowed_host(host):
                        raise guard.Refused("The page redirected to an address that is not allowed") from None
                    cur = nxt
                    continue
                raise guard.Refused(f"The site responded with: {e.code}") from None
            except (urllib.error.URLError, TimeoutError, OSError):
                raise guard.Refused("The page could not be fetched") from None
        else:
            raise guard.Refused("Too many redirects")
        if not re.match(r"(text/|application/xhtml)", ctype or "text/html"):
            raise guard.Refused("This is not a text page")
        html = raw[:max_bytes].decode("utf-8", errors="replace")
        title, text = html_to_text(html)
        return {"final_url": cur, "title": title, "text": text}


_current: Backends | None = None                                              # created on first use (RealBackends needs helpers defined further down)


def _make_dpi_aware() -> None:
    """Windows only: be 'per-monitor DPI aware' so window rectangles, mss screenshots and pyautogui clicks all use the SAME physical pixels.
    Without this, a 150 % display makes clicks land at two thirds of the intended place.  (Written from the documentation; never run on Windows.)"""
    if sys.platform != "win32":
        return
    try:
        import ctypes
        try:
            ctypes.windll.user32.SetProcessDpiAwarenessContext(ctypes.c_void_p(-4))           # PER_MONITOR_AWARE_V2
        except (AttributeError, OSError):
            ctypes.windll.shcore.SetProcessDpiAwareness(2)
    except Exception:
        pass


def _monitor_scale(rect: tuple[int, int, int, int]) -> float:
    """Display scaling of the monitor containing rect's centre (1.0 = 100 %, 1.5 = 150 %).  Informational: coordinates are physical pixels everywhere."""
    if sys.platform != "win32":
        return 1.0
    try:
        import ctypes
        from ctypes import wintypes
        pt = wintypes.POINT((rect[0] + rect[2]) // 2, (rect[1] + rect[3]) // 2)
        hmon = ctypes.windll.user32.MonitorFromPoint(pt, 2)                                    # MONITOR_DEFAULTTONEAREST
        x, y = ctypes.c_uint(), ctypes.c_uint()
        if ctypes.windll.shcore.GetDpiForMonitor(hmon, 0, ctypes.byref(x), ctypes.byref(y)) == 0 and x.value:
            return round(x.value / 96.0, 2)
    except Exception:
        pass
    return 1.0


def _uia_elements(rect: tuple[int, int, int, int], limit: int = 400) -> list[dict]:
    """Structured elements of the foreground window through UI Automation (optional package `uiautomation`, Windows only).  Never raises; [] when unavailable.
    Password fields are skipped.  Never run on Windows by the authors."""
    if sys.platform != "win32":
        return []
    try:
        import uiautomation as auto
    except ImportError:
        return []
    out: list[dict] = []
    try:
        root = auto.GetForegroundControl()
        for ctl, _depth in auto.WalkControl(root, includeTop=False, maxDepth=12):
            if len(out) >= limit:
                break
            try:
                if getattr(ctl, "IsPassword", False):
                    continue
                r = ctl.BoundingRectangle
                box = (int(r.left), int(r.top), int(r.right), int(r.bottom))
                if box[2] <= box[0] or box[3] <= box[1] or getattr(ctl, "IsOffscreen", False):
                    continue
                out.append({"role": str(ctl.ControlTypeName or "").replace("Control", "").lower(), "name": str(ctl.Name or "")[:200], "rect": box,
                            "enabled": bool(ctl.IsEnabled)})
            except Exception:
                continue
    except Exception:
        return out
    return out


def get() -> Backends:
    global _current
    if _current is None:
        _current = RealBackends()
    return _current


def set_backends(b: Backends | None) -> None:
    global _current
    _current = b
