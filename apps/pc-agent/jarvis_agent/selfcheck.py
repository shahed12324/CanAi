"""`jarvis-agent selfcheck`: runs on the machine being verified (e.g. a Windows PC) and prints PASS / FAIL / SKIP per capability.
It never clicks, types or sends anything; it only reads (screen size, one screenshot into a temp folder, the foreground window, the local rules, the emergency-stop file)."""
from __future__ import annotations

import os
import platform
import tempfile
from typing import Callable

from . import config as cfgmod, devices, guard, screen, stop


def _try(name: str, fn: Callable[[], str]) -> tuple[str, str, str]:
    try:
        return name, "PASS", fn()
    except guard.Refused as e:
        return name, "FAIL", str(e)
    except Exception as e:                                       # a broken driver must be reported, not crash the check
        return name, "FAIL", f"{type(e).__name__}: {e}"[:200]


def run() -> list[tuple[str, str, str]]:
    be = devices.get()
    out: list[tuple[str, str, str]] = [("os", "PASS", f"{platform.system()} {platform.release()} / Python {platform.python_version()}")]

    def size() -> str:
        w, h = be.screen_size()
        return f"{w}x{h}"
    out.append(_try("screen size", size))

    def shot() -> str:
        data = be.grab(None)
        if not data.startswith(screen.PNG_SIG):
            raise guard.Refused("not a PNG")
        return f"{len(data) // 1024} KB PNG"
    out.append(_try("screenshot", shot))

    def fg() -> str:
        w = be.foreground()
        if not w:
            raise guard.Refused("no foreground window reported")
        return f"process={w.get('process')!r} rect={w.get('rect')}"
    out.append(_try("foreground window", fg))

    def mod(name: str):
        def f() -> str:
            __import__(name)
            return "importable"
        return f
    for m in ("mss", "pyautogui"):
        out.append(_try(f"library {m}", mod(m)))

    def tess() -> str:
        from . import ocr
        exe = ocr.binary()
        if not exe:
            raise guard.Refused("Tesseract not found (needed for screen_read / screen_find / ui_click); see docs/DESKTOP_INSTALL.md")
        return exe
    out.append(_try("text recognition (Tesseract)", tess))

    def vs() -> str:
        l, t, w, h = be.virtual_screen()
        mons = be.monitors()
        return f"virtual screen {w}x{h} at ({l},{t}); {len(mons)} monitor(s); scale {', '.join(str(m.get('scale', 1.0)) for m in mons)}"
    out.append(_try("monitors and scaling", vs))

    def uia() -> str:
        try:
            import uiautomation  # noqa: F401
        except ImportError:
            raise guard.Refused("optional package 'uiautomation' not installed - screen features fall back to text recognition only") from None
        return "importable"
    out.append(_try("library uiautomation (optional)", uia))

    def pw() -> str:
        import playwright  # noqa: F401
        from . import browserauto  # noqa: F401
        return "importable (needs Chrome or Edge installed)"
    out.append(_try("library playwright (browser_run)", pw))

    def stopfile() -> str:
        stop.request("selfcheck")
        ok = stop.is_set()
        stop.clear()
        if not ok or stop.is_set():
            raise guard.Refused("emergency-stop marker did not set/clear")
        return "set + clear works"
    out.append(_try("emergency stop marker", stopfile))

    def home() -> str:
        d = cfgmod.home_dir()
        d.mkdir(parents=True, exist_ok=True)
        with tempfile.NamedTemporaryFile(dir=d, delete=True):
            pass
        return str(d)
    out.append(_try("settings folder writable", home))

    def tmpdir() -> str:
        with tempfile.TemporaryDirectory() as t:
            p = os.path.join(t, "a.png")
            c = cfgmod.Config(roots=[t], kinds=["screenshot"])
            screen.screenshot(c, {"path": p})
            return f"saved {os.path.getsize(p) // 1024} KB inside an allowed folder"
    out.append(_try("screenshot kind end-to-end", tmpdir))
    return out


def main() -> int:
    rows = run()
    for n, st, d in rows:
        print(f"{st:5} {n}: {d}")
    bad = sum(1 for _, st, _ in rows if st == "FAIL")
    print(f"\n{len(rows) - bad} passed, {bad} failed.  (Mouse/keyboard, microphone, wake word and the browser are NOT exercised here: see docs/WINDOWS_VERIFICATION.md.)")
    return 1 if bad else 0
