"""`jarvis-agent validate-windows --yes`: the real-device validation run (Phase 7).

It opens ONE small test window of its own ("CAN AI Validation Window": a label, a text box, a button) and then drives it through the SAME code path a
real job uses (actions.execute -> policy checks -> devices backend -> real mouse/keyboard), reading the window's real state back to prove each step worked:
typing, a key press, a click at a computed screen coordinate (so DPI / multi-monitor mapping errors show up), a click found by reading the screen
(ui_click via OCR), a screenshot, and the refusals (click outside the window, alt+f4, a window that is not on the allowed list, a "Sign in" window, the emergency stop).
It touches nothing outside its own window.  The result is a JSON report that says honestly whether it ran on a REAL device backend or on a fake one.
Not covered (needs a person): the pyautogui corner fail-safe, other applications, UAC/secure desktop, real websites, microphone, phone approval."""
from __future__ import annotations

import json
import os
import platform
import sys
import tempfile
import time
from datetime import datetime, timezone
from typing import Callable

from . import actions, config as cfgmod, devices, guard, ocr, stop

TITLE = "CAN AI Validation Window"
LABEL = "CAN AI VALIDATION"
BUTTON = "Press Me"
RECT_TOLERANCE = 32                                   # px: the OS frame/shadow around the client area differs by Windows version


class TkProbe:
    """The test window.  Needs tkinter (ships with Python on Windows; included by the build script)."""

    def __init__(self) -> None:
        import tkinter as tk
        self.tk = tk
        self.root = tk.Tk()
        self.root.title(TITLE)
        self.root.geometry("420x260+120+120")
        self.root.attributes("-topmost", True)
        tk.Label(self.root, text=LABEL, font=("Segoe UI", 20)).pack(pady=10)
        self.var = tk.StringVar()
        self.entry = tk.Entry(self.root, textvariable=self.var, font=("Segoe UI", 16), width=24)
        self.entry.pack(pady=8)
        self._clicks = 0
        self.button = tk.Button(self.root, text=BUTTON, font=("Segoe UI", 16), command=self._press)
        self.button.pack(pady=10)
        self.settle(500)
        self.focus()

    def _press(self) -> None:
        self._clicks += 1

    def settle(self, ms: int = 300) -> None:
        end = time.monotonic() + ms / 1000
        while time.monotonic() < end:
            self.root.update()
            time.sleep(0.02)

    def focus(self) -> None:
        self.root.lift(); self.root.focus_force(); self.entry.focus_force(); self.settle(300)

    def rect(self) -> tuple[int, int, int, int]:
        self.root.update_idletasks()
        l, t = self.root.winfo_rootx(), self.root.winfo_rooty()
        return l, t, l + self.root.winfo_width(), t + self.root.winfo_height()

    def entry_text(self) -> str:
        return self.var.get()

    def set_entry(self, s: str) -> None:
        self.var.set(s); self.settle(100)

    def clicks(self) -> int:
        return self._clicks

    def button_center(self) -> tuple[int, int]:
        self.root.update_idletasks()
        return self.button.winfo_rootx() + self.button.winfo_width() // 2, self.button.winfo_rooty() + self.button.winfo_height() // 2

    def set_title(self, s: str) -> None:
        self.root.title(s); self.settle(300)

    def close(self) -> None:
        try:
            self.root.destroy()
        except Exception:
            pass


def _proc_name() -> str:
    n = os.path.basename(sys.executable).lower()
    return n[:-4] if n.endswith(".exe") else n


def _cfg(root: str, apps: list[str]) -> cfgmod.Config:
    return cfgmod.Config(roots=[root], apps=apps, kinds=list(cfgmod.ALL_KINDS))


def run(probe, apps: list[str] | None = None, wait_front: float = 3.0, ocr_ok: Callable[[], bool] | None = None) -> dict:
    """Runs every check against `probe` and returns the report.  `probe` is a TkProbe on a real PC, or a fake in the unit tests."""
    be = devices.get()
    real = isinstance(be, devices.RealBackends)
    res: list[dict] = []
    tmp = tempfile.mkdtemp(prefix="canai-validate-")
    cfg = _cfg(tmp, apps or [_proc_name()])

    def rec(name: str, ok: bool | None, detail: str) -> bool:
        res.append({"name": name, "status": "SKIP" if ok is None else ("PASS" if ok else "FAIL"), "detail": detail[:300]})
        return bool(ok)

    def act(kind: str, **p) -> tuple[bool, str]:
        out = actions.execute(cfg, {"id": "validate", "kind": kind, "params": p})
        probe.settle(350)
        return out

    def in_front() -> bool:
        t0 = time.monotonic()
        while time.monotonic() - t0 < wait_front:
            w = be.foreground() or {}
            if TITLE in str(w.get("title", "")):
                return True
            probe.focus()
        return False

    stop.clear()
    mons = []
    try:
        mons = be.monitors()
    except Exception as e:                                                              # report, do not crash
        rec("monitors", False, f"{type(e).__name__}: {e}")
    if mons:
        rec("monitors", True, f"{len(mons)} monitor(s), scale {', '.join(str(m.get('scale', 1.0)) for m in mons)}")

    if not rec("test window is in front", in_front(), "the validation window must be the foreground window (click it once and run again if Windows refused to focus it)"):
        for n in ("window rectangle matches", "type", "key", "click at computed coordinates", "click outside refused", "alt+f4 refused", "app not on list refused",
                  "sensitive window refused", "screenshot", "read the screen (OCR)", "click found by reading the screen", "emergency stop"):
            rec(n, None, "not run: test window not in front")
        return _report(res, real, mons)

    # geometry: what the window says vs what the agent believes (catches DPI / scaling mistakes)
    pr, fr = probe.rect(), tuple((be.foreground() or {}).get("rect", (0, 0, 0, 0)))
    diff = max(abs(a - b) for a, b in zip(pr, fr)) if len(fr) == 4 else 9999
    rec("window rectangle matches", diff <= RECT_TOLERANCE, f"window says {pr}, agent sees {fr}, largest difference {diff}px (limit {RECT_TOLERANCE})")

    probe.set_entry("")
    ok, out = act("input_control", action="type", text="hello 123")
    rec("type", ok and probe.entry_text() == "hello 123", f"expected 'hello 123', box has {probe.entry_text()!r}; engine: {out[:80]}")
    ok, out = act("input_control", action="key", keys="home")
    ok2, _ = act("input_control", action="type", text=">")
    rec("key", ok and ok2 and probe.entry_text() == ">hello 123", f"expected '>hello 123', box has {probe.entry_text()!r}")

    before = probe.clicks()
    x, y = probe.button_center()
    ok, out = act("input_control", action="click", x=x, y=y)
    rec("click at computed coordinates", ok and probe.clicks() == before + 1, f"aimed at ({x},{y}); clicks {before} -> {probe.clicks()}; {out[:60]}")

    before = probe.clicks()
    r = probe.rect()
    ok, out = act("input_control", action="click", x=r[2] + 60, y=r[1] + 10)
    rec("click outside refused", (not ok) and probe.clicks() == before, f"refused={not ok}; {out[:80]}")
    ok, out = act("input_control", action="key", keys="alt+f4")
    rec("alt+f4 refused", (not ok) and in_front(), f"refused={not ok}; window still open={in_front()}")

    saved = cfg.apps
    cfg.apps = ["notepad"] if _proc_name() != "notepad" else ["calc"]
    probe.set_entry("keep")
    ok, out = act("input_control", action="type", text="zzz")
    rec("app not on list refused", (not ok) and probe.entry_text() == "keep", f"refused={not ok}; box {probe.entry_text()!r}")
    cfg.apps = saved

    probe.set_title("Sign in")
    be_now = be.foreground() or {}
    ok, out = act("input_control", action="type", text="zzz")
    rec("sensitive window refused", (not ok) and probe.entry_text() == "keep", f"refused={not ok}; title seen {str(be_now.get('title'))!r}")
    probe.set_title(TITLE)
    in_front()

    path = os.path.join(tmp, "shot.png")
    ok, out = act("screenshot", path=path)
    rec("screenshot", ok and os.path.isfile(path) and open(path, "rb").read(8) == b"\x89PNG\r\n\x1a\n", f"{out[:80]}")

    have_ocr = ocr_ok() if ocr_ok else ocr.available()
    if not have_ocr:
        rec("read the screen (OCR)", None, "Tesseract not installed - install it to test screen_read / ui_click")
        rec("click found by reading the screen", None, "needs Tesseract")
    else:
        ok, out = act("screen_read")
        rec("read the screen (OCR)", ok and "validation" in out.lower(), f"looking for {LABEL!r}; got {len(out)} chars")
        before = probe.clicks()
        ok, out = act("ui_click", text=BUTTON)
        rec("click found by reading the screen", ok and probe.clicks() == before + 1, f"clicks {before} -> {probe.clicks()}; {out[:80]}")

    probe.set_entry("keep")
    stop.request("validate")
    ok, out = act("input_control", action="type", text="zzz")
    stop.clear()
    rec("emergency stop", (not ok) and probe.entry_text() == "keep" and not stop.is_set(), f"refused={not ok}; box {probe.entry_text()!r}")
    return _report(res, real, mons)


def _report(res: list[dict], real: bool, mons: list[dict]) -> dict:
    p = sum(r["status"] == "PASS" for r in res); f = sum(r["status"] == "FAIL" for r in res); s = sum(r["status"] == "SKIP" for r in res)
    return {"tool": "jarvis-agent validate-windows", "when": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "platform": f"{platform.system()} {platform.release()} / Python {platform.python_version()}",
            "real_device": real, "evidence": "real mouse/keyboard on this PC" if real else "SIMULATED: a fake backend was used - this is NOT evidence about a real PC",
            "monitors": [{"scale": m.get("scale"), "rect": m.get("rect")} for m in mons],
            "summary": {"pass": p, "fail": f, "skip": s}, "results": res,
            "not_covered": ["pyautogui corner fail-safe", "other applications", "UAC / secure desktop", "real websites", "microphone", "phone approval"]}


def main(argv: list[str] | None = None) -> int:
    argv = list(argv if argv is not None else sys.argv[1:])
    if "--yes" not in argv:
        print("This opens a small test window and then moves the mouse and types INTO THAT WINDOW ONLY.\n"
              "Do not touch the mouse or keyboard while it runs (about 20 seconds). Moving the mouse into a screen corner aborts it.\n"
              "Run again with --yes to start:  jarvis-agent validate-windows --yes")
        return 2
    be = devices.get()
    if not isinstance(be, devices.RealBackends):
        print("No real device backend; refusing to produce a report that looks real."); return 1
    try:
        probe = TkProbe()
    except Exception as e:
        print(f"Cannot open the test window ({type(e).__name__}: {e}). Is tkinter installed and is there a desktop session?"); return 1
    try:
        rep = run(probe)
    finally:
        probe.close()
    d = cfgmod.home_dir(); d.mkdir(parents=True, exist_ok=True)
    path = d / f"validation-{datetime.now().strftime('%Y%m%d-%H%M%S')}.json"
    path.write_text(json.dumps(rep, indent=2, ensure_ascii=False), encoding="utf-8")
    for r in rep["results"]:
        print(f"{r['status']:5} {r['name']}: {r['detail']}")
    s = rep["summary"]
    print(f"\n{s['pass']} passed, {s['fail']} failed, {s['skip']} skipped.  Report saved: {path}\nPaste this report into docs/DESKTOP_WINDOWS_CHECKLIST.md results.  Not covered: " + ", ".join(rep["not_covered"]))
    return 1 if s["fail"] else 0
