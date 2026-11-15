"""Phase D: pause/continue, live progress, window observation + verification (all against a fake desktop backend - never a real Windows screen)."""
import json
import threading
import time

import pytest

from jarvis_agent import actions, cli, devices, observe, pause, progress, stop, taskengine
from jarvis_agent.config import ALL_KINDS, Config
from jarvis_agent.taskengine import Engine
from test_devices_phase6 import Fake
from test_taskengine import go, mk


@pytest.fixture(autouse=True)
def _clean():
    stop.clear(); pause.clear(); progress.reset()
    observe.sleep = lambda s: None
    yield
    stop.clear(); pause.clear(); progress.reset(); devices.set_backends(None)
    observe.sleep, observe.clock = time.sleep, time.monotonic


def plan(root, n=3):
    return {"id": "pd1", "steps": [{"id": f"s{i}", "kind": "write_file", "params": {"path": str(root / f"f{i}.txt"), "content": f"x{i}"}} for i in range(1, n + 1)]}


# ---------------------------------------------------------------- pause
def test_pause_holds_the_next_step_then_continue_finishes_everything(root):
    eng = Engine(mk(root))
    pause.request("test")
    out = {}
    th = threading.Thread(target=lambda: out.setdefault("r", go(eng, plan(root))))
    th.start()
    time.sleep(0.6)
    assert th.is_alive() and not list(root.glob("f*.txt"))                    # nothing ran while paused
    assert progress.snapshot()["current"]["state"] == "paused"
    pause.clear()
    th.join(5)
    assert out["r"].state == "completed" and len(list(root.glob("f*.txt"))) == 3
    events = [r["event"] for r in eng.audit.rows("pd1")]
    assert "paused" in events and "continued" in events and eng.audit.verify_chain()[0]
    assert progress.snapshot()["last"]["state"] == "completed"


def test_pause_between_steps_lets_the_running_step_finish(root):
    eng = Engine(mk(root))
    real = eng._act
    seen = []

    def act(kind, params):
        r = real(kind, params)
        seen.append(params["path"])
        if len(seen) == 1:
            pause.request("mid-task")                                            # a person presses pause while step 1 runs
        return r
    eng._act = act
    th = threading.Thread(target=lambda: go(eng, plan(root)))
    th.start()
    time.sleep(0.7)
    assert len(seen) == 1 and (root / "f1.txt").exists() and not (root / "f2.txt").exists()
    pause.clear(); th.join(5)
    assert len(seen) == 3


def test_emergency_stop_beats_a_pause(root):
    eng = Engine(mk(root))
    pause.request("x")
    out = {}
    th = threading.Thread(target=lambda: out.setdefault("r", go(eng, plan(root))))
    th.start(); time.sleep(0.4)
    stop.request("test")
    th.join(5)
    assert not th.is_alive() and out["r"].state == "cancelled" and not list(root.glob("f*.txt"))


def test_paused_too_long_gives_up_honestly(root, monkeypatch):
    monkeypatch.setattr(pause, "MAX_PAUSE", 0.5)
    pause.request("forgotten")
    res = go(Engine(mk(root)), plan(root))
    assert res.state == "cancelled" and "emergency stop" in res.summary or "paused" in res.summary and not list(root.glob("f*.txt"))


def test_pause_file_is_the_channel_for_other_processes(root):
    assert not pause.is_set()
    pause.flag_file().parent.mkdir(parents=True, exist_ok=True)
    pause.flag_file().write_text("1")
    assert pause.is_set()
    pause.clear()
    assert not pause.is_set()


def test_cli_pause_and_continue(capsys):
    assert cli.main(["pause"]) == 0 and pause.is_set()
    assert cli.main(["continue"]) == 0 and not pause.is_set()


# ---------------------------------------------------------------- progress
def test_progress_shows_each_step_with_evidence_and_failure(root):
    p = plan(root, 2)
    p["steps"][1]["params"]["path"] = str(root / "missing" / "x.txt")          # folder does not exist -> the step fails
    res = go(Engine(mk(root)), p)
    last = progress.snapshot()["last"]
    assert res.state == "failed" and last["state"] == "failed"
    assert [s["state"] for s in last["steps"]] == ["completed", "failed"] and last["steps"][0]["note"]
    assert progress.snapshot()["current"] is None


# ---------------------------------------------------------------- window observation
def fake(win=None):
    f = Fake(win=win); devices.set_backends(f); return f


def test_window_info_reports_the_front_window_as_outside_data(root):
    fake({"process": "C:/Windows/notepad.exe", "title": "notes - Notepad", "rect": (0, 0, 10, 10)})
    ok, out = actions.execute(mk(root), {"kind": "window_info", "params": {}})
    assert ok and "notepad" in out and "notes - Notepad" in out and out.startswith("[external data]")
    assert "window_info" in taskengine.TAINTS_OUTPUT


def test_window_info_never_shows_a_sensitive_title(root):
    fake({"process": "chrome", "title": "Sign in - Bank of Example", "rect": (0, 0, 10, 10)})
    ok, out = actions.execute(mk(root), {"kind": "window_info", "params": {}})
    assert ok and "Bank of Example" not in out and "sensitive" in out


def test_wait_window_succeeds_when_the_window_appears(root):
    f = fake({"process": "explorer", "title": "Home", "rect": (0, 0, 10, 10)})
    n = {"i": 0}

    def foreground():
        n["i"] += 1
        return {"process": "notepad", "title": "Untitled - Notepad", "rect": (0, 0, 10, 10)} if n["i"] >= 3 else {"process": "explorer", "title": "Home", "rect": (0, 0, 10, 10)}
    f.foreground = foreground
    ok, out = actions.execute(mk(root), {"kind": "wait_window", "params": {"process": "Notepad.EXE", "title_contains": "untitled", "timeout": 5}})
    assert ok and "notepad" in out and n["i"] == 3


def test_wait_window_times_out_and_says_what_was_in_front(root):
    fake({"process": "explorer", "title": "Home", "rect": (0, 0, 10, 10)})
    t = {"v": 0.0}
    observe.clock = lambda: t.__setitem__("v", t["v"] + 1.0) or t["v"]
    ok, out = actions.execute(mk(root), {"kind": "wait_window", "params": {"process": "notepad", "timeout": 3}})
    assert not ok and "explorer" in out and "notepad" in out


def test_wait_window_parameter_checks_and_emergency_stop(root):
    fake()
    for bad in ({}, {"timeout": 5}, {"process": ""}, {"process": "a", "timeout": 0}, {"process": "a", "timeout": 31}, {"process": "a", "timeout": True}, {"process": 5}):
        assert not actions.execute(mk(root), {"kind": "wait_window", "params": bad})[0], bad
    stop.request("t")
    assert not actions.execute(mk(root), {"kind": "wait_window", "params": {"process": "a"}})[0]


def test_window_kinds_are_off_until_the_owner_enables_them(root):
    fake()
    cfg = Config(roots=[str(root)], device_id="d")                               # default kinds only
    assert not actions.execute(cfg, {"kind": "window_info", "params": {}})[0]
    assert "window_info" in ALL_KINDS and "wait_window" in ALL_KINDS


# ---------------------------------------------------------------- verification by the real window state
def test_a_gui_step_is_verified_against_the_foreground_window(root):
    cfg = mk(root, apps=["notepad"])
    spec = {"type": "window_foreground", "process": "notepad", "title_contains": "Notepad"}
    for ok_expected, win in ((True, {"process": "notepad.exe", "title": "a - Notepad", "rect": (0, 0, 9, 9)}), (False, {"process": "explorer", "title": "x", "rect": (0, 0, 9, 9)})):
        fake(win)
        p = {"id": "pg" + str(ok_expected), "steps": [{"id": "s1", "kind": "window_info", "params": {}, "verify": spec}]}
        res = go(Engine(cfg), p)
        assert (res.state == "completed") is ok_expected, res.summary


def test_verify_window_refuses_empty_or_sensitive(root):
    from jarvis_agent import verify
    fake({"process": "chrome", "title": "Password manager", "rect": (0, 0, 9, 9)})
    assert verify.check(mk(root), {"type": "window_foreground", "process": "chrome"})[0] is False
    assert verify.check(mk(root), {"type": "window_foreground"})[0] is False


def test_open_app_then_verify_then_type_then_screenshot_scenario(root):
    """The 'open Notepad, type, prove it with a screenshot' scenario at engine level with a fake desktop (real Windows run is still to be done by a person)."""
    f = fake({"process": "notepad", "title": "Untitled - Notepad", "rect": (0, 0, 800, 600)})
    cfg = mk(root, apps=["notepad"])
    from jarvis_agent import guard
    shot = str(root / "proof.png")
    p = {"id": "pscn", "steps": [
        {"id": "s1", "kind": "wait_window", "params": {"process": "notepad", "timeout": 5}},
        {"id": "s2", "kind": "input_control", "params": {"action": "type", "text": "hello"}, "verify": {"type": "window_foreground", "process": "notepad"}},
        {"id": "s3", "kind": "screenshot", "params": {"path": shot}},
    ]}
    eng = Engine(cfg)
    ap = {sid: eng.approvals.issue(taskengine.step_digest("pscn", s)) for s in p["steps"] for sid in [s["id"]] if taskengine.RISK[s["kind"]] in ("high", "critical")}
    res = go(eng, p, step_ok=ap)
    assert res.state == "completed", res.summary
    assert ("type", "hello") in f.calls and (root / "proof.png").exists()
    assert "hello" not in json.dumps(eng.audit.rows("pscn"))                      # typed text never reaches the audit log


def test_gui_step_without_approval_is_refused(root):
    f = fake()
    p = {"id": "pna", "steps": [{"id": "s1", "kind": "input_control", "params": {"action": "type", "text": "hi"}}]}
    res = go(Engine(mk(root, apps=["notepad"])), p)
    assert res.state == "failed" and not [c for c in f.calls if c[0] == "type"]
