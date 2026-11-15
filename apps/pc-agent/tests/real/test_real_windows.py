"""Real Windows PC checks (areas 14, 15, 13).  Run on Windows with a desktop session, as the normal user (NOT as administrator):
    set JARVIS_REAL_DEVICE=1 && python -m pytest tests/real -v
Do not touch the mouse/keyboard while test_mouse_and_keyboard runs (~20 s)."""
import os
import subprocess
import sys

import pytest

REAL = os.environ.get("JARVIS_REAL_DEVICE") == "1"
WIN = sys.platform == "win32"
pytestmark = [pytest.mark.real_device, pytest.mark.skipif(not REAL, reason="set JARVIS_REAL_DEVICE=1 on the real machine"),
              pytest.mark.skipif(not WIN, reason="Windows only")]


@pytest.mark.area(15)
def test_mouse_and_keyboard_in_the_test_window_only():
    from jarvis_agent import validate
    assert validate.main(["--yes"]) == 0


@pytest.mark.area(13)
def test_dpapi_roundtrip_and_a_stored_token_is_not_plain(tmp_path, monkeypatch):
    from jarvis_agent import config as cfgmod, secretstore
    monkeypatch.setenv("JARVIS_AGENT_HOME", str(tmp_path))
    monkeypatch.delenv("JARVIS_NO_DPAPI", raising=False)
    monkeypatch.setattr(secretstore, "_backend_ready", False)
    assert secretstore.backend() is not None, "DPAPI backend did not load"
    tok = "R" * 64
    cfgmod.save(cfgmod.Config(server="https://x.example", token=tok))
    raw = cfgmod.path().read_text(encoding="utf-8")
    assert tok not in raw and secretstore.PREFIX in raw
    assert cfgmod.load().token == tok


@pytest.mark.area(13)
def test_windows_path_tricks_are_refused_on_a_real_volume(tmp_path):
    from jarvis_agent import guard
    from jarvis_agent.guard import Refused
    ok = tmp_path / "ok.txt"
    ok.write_text("x")
    assert guard.path_in_roots(str(ok), [str(tmp_path)])
    for bad in (str(tmp_path / "CON"), str(tmp_path / "a.txt:hidden"), str(tmp_path / "a*.txt"), "\\\\?\\" + str(ok)):
        with pytest.raises(Refused):
            guard.path_in_roots(bad, [str(tmp_path)])
    short = subprocess.run(["cmd", "/c", "for %I in (\"" + str(ok) + "\") do @echo %~sI"], capture_output=True, text=True).stdout.strip()
    assert guard.path_in_roots(short or str(ok), [str(tmp_path)])        # 8.3 short names resolve to the real path inside the folder


@pytest.mark.area(15)
def test_window_info_and_screenshot_work():
    from jarvis_agent import actions
    from jarvis_agent.config import ALL_KINDS, Config
    import tempfile
    d = tempfile.mkdtemp()
    cfg = Config(roots=[d], kinds=list(ALL_KINDS))
    ok, out = actions.execute(cfg, {"kind": "window_info", "params": {}})
    assert ok or "sensitive" in out.lower() or "No window" in out, out
    ok, out = actions.execute(cfg, {"kind": "screenshot", "params": {"path": os.path.join(d, "s.png")}})
    assert (ok and os.path.getsize(os.path.join(d, "s.png")) > 1000) or "sensitive" in out.lower(), out


@pytest.mark.area(15)
def test_emergency_stop_kills_a_real_child_process(tmp_path):
    import threading, time
    from jarvis_agent import actions, stop
    from jarvis_agent.config import ALL_KINDS, Config
    cfg = Config(roots=[str(tmp_path)], commands=["ping"], kinds=list(ALL_KINDS))
    res = {}
    t = threading.Thread(target=lambda: res.update(r=actions.execute(cfg, {"kind": "run_command", "params": {"command": "ping", "args": ["-n", "30", "127.0.0.1"]}})))
    t0 = time.time(); t.start(); time.sleep(1.5); stop.request("real-test"); t.join(10)
    stop.clear()
    assert not t.is_alive() and time.time() - t0 < 10 and res["r"][0] is False
