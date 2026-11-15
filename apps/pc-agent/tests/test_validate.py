"""The validation harness itself, run against a SIMULATED desktop: a fake window that really reacts to typing and clicks.  This proves the harness logic
(what it checks, that it detects a wrong click mapping, a missing refusal, a report that lies) - it is NOT evidence about a real PC."""
import json

import pytest

from jarvis_agent import cli, devices, stop, validate


class SimDesk(devices.Backends):
    """One window with a text box and a button; mouse/keyboard act on it.  `scale` simulates a DPI mapping bug: clicks land scale x too far."""

    def __init__(self, scale=1.0, title=validate.TITLE, process="python"):
        self.title, self.process, self.scale = title, process, scale
        self.rect = (120, 120, 540, 380)
        self.text = ""; self.clicks = 0; self.btn = (330, 300, 90, 40)      # button box: x, y, w, h (screen)
        self.cursor = 0

    # -- the "window" side
    def foreground(self): return {"process": self.process, "title": self.title, "rect": self.rect}
    def screen_size(self): return (1920, 1080)
    def virtual_screen(self): return (0, 0, 1920, 1080)
    def monitors(self): return [{"id": 1, "rect": (0, 0, 1920, 1080), "scale": 1.0, "primary": True}]
    def grab(self, region): return b"\x89PNG\r\n\x1a\n" + b"0" * 64
    def type_text(self, t): self.text = self.text[:self.cursor] + t + self.text[self.cursor:]; self.cursor += len(t)
    def press(self, k):
        if tuple(k) == ("home",): self.cursor = 0
    def click(self, x, y, b, d):
        x, y = x * self.scale, y * self.scale
        bx, by, bw, bh = self.btn
        if bx <= x < bx + bw and by <= y < by + bh: self.clicks += 1
    def move(self, x, y): pass
    def scroll(self, dy): pass


class SimProbe:
    def __init__(self, desk): self.d = desk
    def settle(self, ms=0): pass
    def focus(self): pass
    def rect(self): return self.d.rect
    def entry_text(self): return self.d.text
    def set_entry(self, s): self.d.text = s; self.d.cursor = len(s)
    def clicks(self): return self.d.clicks
    def button_center(self): x, y, w, h = self.d.btn; return x + w // 2, y + h // 2
    def set_title(self, s): self.d.title = s
    def close(self): pass


@pytest.fixture(autouse=True)
def _clean(tmp_path, monkeypatch):
    monkeypatch.setenv("JARVIS_AGENT_HOME", str(tmp_path / "h"))
    stop.clear()
    yield
    devices.set_backends(None); stop.clear()


def go(desk, ocr=False):
    devices.set_backends(desk)
    return validate.run(SimProbe(desk), apps=["python"], wait_front=0.01, ocr_ok=lambda: ocr)


def by(rep): return {r["name"]: r["status"] for r in rep["results"]}


def test_a_correct_desktop_passes_everything_it_can_and_says_it_is_simulated():
    rep = go(SimDesk())
    st = by(rep)
    assert rep["summary"]["fail"] == 0, rep
    for n in ("test window is in front", "window rectangle matches", "type", "key", "click at computed coordinates", "click outside refused", "alt+f4 refused",
              "app not on list refused", "sensitive window refused", "screenshot", "emergency stop"):
        assert st[n] == "PASS", (n, rep["results"])
    assert st["read the screen (OCR)"] == "SKIP" and st["click found by reading the screen"] == "SKIP"        # no Tesseract claimed
    assert rep["real_device"] is False and "SIMULATED" in rep["evidence"] and "NOT evidence" in rep["evidence"]
    assert "microphone" in rep["not_covered"] and "pyautogui corner fail-safe" in rep["not_covered"]


def test_a_dpi_mapping_bug_is_detected_by_the_click_check():
    rep = go(SimDesk(scale=1.5))                                              # the click lands 1.5x too far
    assert by(rep)["click at computed coordinates"] == "FAIL" and rep["summary"]["fail"] >= 1


def test_wrong_window_rectangle_is_reported():
    d = SimDesk(); p = SimProbe(d)
    devices.set_backends(d)
    p.rect = lambda: (0, 0, 300, 200)                                         # the window claims a different place than the OS reports
    rep = validate.run(p, apps=["python"], wait_front=0.01, ocr_ok=lambda: False)
    assert by(rep)["window rectangle matches"] == "FAIL"


def test_window_not_in_front_skips_the_rest_instead_of_lying():
    rep = go(SimDesk(title="Something else"))
    st = by(rep)
    assert st["test window is in front"] == "FAIL" and st["type"] == "SKIP" and st["click at computed coordinates"] == "SKIP"
    assert rep["summary"]["pass"] <= 1                                        # at most the monitors line


def test_a_missing_refusal_is_a_failure_not_a_pass(monkeypatch):
    from jarvis_agent import inputctl
    monkeypatch.setattr(inputctl, "BLOCKED_COMBOS", set())                     # simulate a guard that was removed
    monkeypatch.setattr(inputctl, "KEY_TOKENS", inputctl.KEY_TOKENS | {"f4"})
    d = SimDesk(); devices.set_backends(d)
    # the harness checks that the window is still in front after alt+f4; a desk that closes on alt+f4 must be caught
    orig = d.press
    def press(k):
        if frozenset(k) == frozenset(("alt", "f4")): d.title = "gone"
        orig(k)
    d.press = press
    rep = validate.run(SimProbe(d), apps=["python"], wait_front=0.01, ocr_ok=lambda: False)
    assert by(rep)["alt+f4 refused"] == "FAIL"


def test_cli_requires_yes_and_refuses_without_a_real_backend(capsys):
    assert cli.main(["validate-windows"]) == 2 and "--yes" in capsys.readouterr().out
    devices.set_backends(SimDesk())
    assert cli.main(["validate-windows", "--yes"]) == 1
    assert "refusing to produce a report that looks real" in capsys.readouterr().out
    assert not list((__import__("jarvis_agent.config", fromlist=["x"]).home_dir()).glob("validation-*.json")) if (__import__("jarvis_agent.config", fromlist=["x"]).home_dir()).exists() else True


def test_report_is_plain_json():
    rep = go(SimDesk())
    assert json.loads(json.dumps(rep))["summary"]["fail"] == 0
