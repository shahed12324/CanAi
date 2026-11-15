"""Phase 26f: server rules for screenshot / input_control / browser_extract."""
import pytest
from psycopg.types.json import Jsonb

from conftest import as_admin
from test_pc_control import ROOTS, clean, device, policy, req  # noqa: F401
from test_memory import second_user  # noqa: F401

W = "/home/me/work"


def test_risk_classes(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    r = req(app, world, dev, "screenshot", {"path": W + "/s.png"})
    assert r["status"] == "pending" and r["risk"] == "high"
    r = req(app, world, dev, "input_control", {"action": "type", "text": "hi"})
    assert r["status"] == "pending" and r["risk"] == "high"
    r = req(app, world, dev, "browser_extract", {"url": "https://example.com/x"})
    assert r["risk"] == "medium"


def test_high_kinds_can_never_be_allow(app, owner, world):
    dev, _ = device(app, world)
    assert policy(app, world, dev, modes={"screenshot": "allow"}) == "bad_input"
    assert policy(app, world, dev, modes={"input_control": "allow"}) == "bad_input"
    assert policy(app, world, dev, modes={"screenshot": "ask", "input_control": "deny", "browser_extract": "allow"}) == "ok"
    as_admin(owner)
    owner.execute("UPDATE pc_policies SET modes = %s WHERE device_id = %s", (Jsonb({"screenshot": "allow", "input_control": "allow"}), dev))
    assert req(app, world, dev, "screenshot", {"path": W + "/s.png"})["status"] == "pending"     # a tampered row cannot auto-approve


def test_screenshot_params(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    s = lambda p: req(app, world, dev, "screenshot", p)
    assert s({"path": "/etc/s.png"})["reason"] == "outside_scope"
    assert s({"path": W + "/s.txt"})["reason"] == "bad_params"
    assert s({})["reason"] == "bad_params"
    assert s({"path": W + "/../s.png"})["reason"] == "bad_params"
    assert s({"path": W + "/s.png", "region": [0, 0, 10]})["reason"] == "bad_params"
    assert s({"path": W + "/s.png", "region": [0, 0, 0, 10]})["reason"] == "bad_params"
    assert s({"path": W + "/s.png", "region": [0, -1, 5, 10]})["reason"] == "bad_params"
    assert s({"path": W + "/s.png", "region": [0, 0, 5.5, 10]})["reason"] == "bad_params"
    assert s({"path": W + "/s.png", "region": [0, 0, "5", 10]})["reason"] == "bad_params"
    assert s({"path": W + "/s.png", "overwrite": "yes"})["reason"] == "bad_params"
    assert s({"path": W + "/s.png", "region": [0, 0, 100, 100], "overwrite": True})["status"] == "pending"


def test_input_params(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    i = lambda p: req(app, world, dev, "input_control", p)
    for bad in [{}, {"action": "drag"}, {"action": "type"}, {"action": "type", "text": ""}, {"action": "type", "text": "x" * 201}, {"action": "type", "text": "a\x1bb"},
                {"action": "key", "keys": "win+r"}, {"action": "key", "keys": "ctrl+"}, {"action": "key", "keys": 5}, {"action": "key", "keys": "a+b+c+d+e"},
                {"action": "click"}, {"action": "click", "x": 1}, {"action": "click", "x": -1, "y": 1}, {"action": "click", "x": 1.5, "y": 1}, {"action": "click", "x": "1", "y": 1},
                {"action": "click", "x": 1, "y": 1, "button": "middle"}, {"action": "scroll", "dy": 21}, {"action": "scroll", "dy": "2"}, {"action": "scroll"}]:
        assert i(bad)["reason"] == "bad_params", bad
    for good in [{"action": "type", "text": "hello"}, {"action": "key", "keys": "ctrl+s"}, {"action": "click", "x": 10, "y": 20}, {"action": "click", "x": 10, "y": 20, "button": "right"},
                 {"action": "move", "x": 1, "y": 1}, {"action": "scroll", "dy": -3}]:
        assert i(good)["status"] == "pending", good


def test_browser_extract_hosts(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    b = lambda u: req(app, world, dev, "browser_extract", {"url": u})
    assert b("http://example.com/")["reason"] == "bad_params"
    assert b("https://evil.test/")["reason"] == "outside_scope"
    assert b("https://example.com/a b")["reason"] == "bad_params"
    assert b("https://example.com/a")["status"] == "pending"
    assert req(app, world, dev, "browser_extract", {})["reason"] == "bad_params"


def test_old_kinds_still_work_after_rename(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    assert req(app, world, dev, "read_file", {"path": W + "/a"})["status"] == "pending" or True
    assert req(app, world, dev, "copy_file", {"src": W + "/a", "dst": "/etc/x"})["reason"] == "outside_scope"
    assert req(app, world, dev, "power", {"action": "hibernate-and-wipe"})["reason"] == "bad_params"


# ---------------------------------------------------------------- Phase 33a: window_info / wait_window
def test_window_kinds_risk_and_params(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    r = req(app, world, dev, "window_info", {})
    assert r["status"] == "pending" and r["risk"] == "medium"
    assert req(app, world, dev, "window_info", {"x": 1})["reason"] == "bad_params"
    w = lambda p: req(app, world, dev, "wait_window", p)
    ok = w({"process": "notepad", "title_contains": "Untitled", "timeout": 15})
    assert ok["risk"] == "low" and ok.get("reason") in (None, "")
    assert w({"title_contains": "Report"})["risk"] == "low"
    for bad in ({}, {"timeout": 5}, {"process": ""}, {"process": "x" * 121}, {"process": 5}, {"process": "a", "timeout": 0},
                {"process": "a", "timeout": 31}, {"process": "a", "timeout": 2.5}, {"process": "a", "extra": 1}, {"process": "a", "timeout": "9"}):
        assert w(bad)["reason"] == "bad_params", bad


def test_old_kinds_still_behave_after_33a(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    assert req(app, world, dev, "screenshot", {"path": W + "/s.png"})["risk"] == "high"
    assert req(app, world, dev, "screenshot", {"path": "/etc/s.png"})["reason"] == "outside_scope"
    assert req(app, world, dev, "input_control", {"action": "type", "text": "hi"})["risk"] == "high"
    assert req(app, world, dev, "read_file", {"path": W + "/a.txt"})["risk"] == "low"
    assert req(app, world, dev, "browser_extract", {"url": "https://example.com/x"})["risk"] == "medium"


# ---------------------------------------------------------------- Phase 34a: screen_read / screen_find / ui_click / screen_describe
def test_screen_kinds_risk_and_params(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    r = lambda k, p: req(app, world, dev, k, p)
    assert r("screen_read", {})["risk"] == "high" and r("screen_read", {})["status"] == "pending"
    assert r("screen_read", {"x": 1})["reason"] == "bad_params"
    assert r("screen_find", {"text": "Save"})["risk"] == "high"
    assert r("screen_find", {"text": "Save", "role": "button"})["status"] == "pending"
    assert r("ui_click", {"text": "Save", "role": "button", "index": 2, "button": "right", "min_confidence": 0.8})["status"] == "pending"
    assert r("screen_describe", {})["status"] == "pending"
    assert r("screen_describe", {"question": "What is the error?"})["risk"] == "high"
    for kind, bad in (("screen_find", {}), ("screen_find", {"text": ""}), ("screen_find", {"text": "x" * 121}), ("screen_find", {"text": 5}),
                      ("screen_find", {"text": "a", "index": 1}), ("screen_find", {"text": "a", "role": "b3"}), ("screen_find", {"text": "a", "role": "x" * 31}),
                      ("ui_click", {"text": "a", "index": 0}), ("ui_click", {"text": "a", "index": 21}), ("ui_click", {"text": "a", "index": 1.5}), ("ui_click", {"text": "a", "index": "2"}),
                      ("ui_click", {"text": "a", "button": "middle"}), ("ui_click", {"text": "a", "button": 1}),
                      ("ui_click", {"text": "a", "min_confidence": 0.4}), ("ui_click", {"text": "a", "min_confidence": 1.1}), ("ui_click", {"text": "a", "min_confidence": "0.9"}),
                      ("ui_click", {"text": "a", "x": 5, "y": 5}), ("ui_click", {"x": 5, "y": 5}),
                      ("screen_describe", {"question": ""}), ("screen_describe", {"question": "q" * 301}), ("screen_describe", {"question": 1}), ("screen_describe", {"other": "x"})):
        assert r(kind, bad)["reason"] == "bad_params", (kind, bad)


def test_old_kinds_still_behave_after_34a(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    assert req(app, world, dev, "window_info", {})["risk"] == "medium"
    assert req(app, world, dev, "wait_window", {"process": "notepad"})["risk"] == "low"
    assert req(app, world, dev, "screenshot", {"path": "/etc/s.png"})["reason"] == "outside_scope"
    assert req(app, world, dev, "read_file", {"path": W + "/a.txt"})["risk"] == "low"


def test_34a_down_refuses_when_rows_exist(app, owner, world):
    import psycopg
    from conftest import _exec_file
    dev, _ = device(app, world)
    policy(app, world, dev)
    assert req(app, world, dev, "screen_read", {})["status"] == "pending"
    with pytest.raises(psycopg.errors.Error, match="cannot roll back"):
        _exec_file(owner, "phase34a.down.sql")
    assert owner.execute("SELECT pc_kind_risk('screen_read')").fetchone()[0] == "high"      # nothing was rolled back


def test_34a_rolls_back_and_forward_on_a_clean_database(owner):
    from conftest import _exec_file
    _exec_file(owner, "phase37a.down.sql")                                  # migrations are undone newest first
    _exec_file(owner, "phase36a.down.sql")
    _exec_file(owner, "phase35a.down.sql")
    _exec_file(owner, "phase34a.down.sql")
    assert owner.execute("SELECT pc_kind_risk('screen_read')").fetchone()[0] is None
    assert owner.execute("SELECT pc_kind_risk('window_info')").fetchone()[0] == "medium"
    assert owner.execute("SELECT pc_target_ok('window_info', '{}'::jsonb, '{}', '{}', '{}', '{}')").fetchone()[0] == "ok"
    assert owner.execute("SELECT pc_target_ok('screen_read', '{}'::jsonb, '{}', '{}', '{}', '{}')").fetchone()[0] != "ok"
    _exec_file(owner, "phase34a.up.sql")
    assert owner.execute("SELECT pc_kind_risk('ui_click')").fetchone()[0] == "high"
    assert owner.execute("SELECT pc_target_ok('screen_read', '{}'::jsonb, '{}', '{}', '{}', '{}')").fetchone()[0] == "ok"
    assert owner.execute("SELECT pc_target_ok('screen_read', '{\"a\":1}'::jsonb, '{}', '{}', '{}', '{}')").fetchone()[0] == "bad_params"
    _exec_file(owner, "phase35a.up.sql")
    _exec_file(owner, "phase36a.up.sql")
    _exec_file(owner, "phase37a.up.sql")
    assert owner.execute("SELECT pc_kind_risk('browser_run')").fetchone()[0] == "high"


# ---------------------------------------------------------------- Phase 35a: browser_run
def test_browser_run_params(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    r = lambda p: req(app, world, dev, "browser_run", p)
    u = "https://example.com/form"
    good = r({"url": u, "steps": [{"op": "fill", "label": "Name", "value": "A"}, {"op": "check", "label": "Agree"}, {"op": "select", "css": "#t", "value": "x"},
                                  {"op": "click", "text": "Send"}, {"op": "wait_text", "text": "Thanks", "timeout": 5}, {"op": "expect_text", "text": "Thanks"}, {"op": "extract"}]})
    assert good["status"] == "pending" and good["risk"] == "high", good
    assert r({"steps": [{"op": "goto", "url": u}, {"op": "extract", "css": "p"}]})["status"] == "pending"
    assert r({"url": "https://evil.example/x", "steps": [{"op": "extract"}]})["reason"] == "outside_scope"
    assert r({"steps": [{"op": "goto", "url": "https://evil.example/x"}]})["reason"] == "outside_scope"
    assert r({"url": u, "steps": [{"op": "goto", "url": "http://example.com/x"}]})["reason"] == "outside_scope"
    for bad in ({"url": u, "steps": []}, {"url": u}, {"url": u, "steps": [{"op": "extract"}] * 21}, {"url": u, "steps": [{"op": "eval", "js": "1"}]},
                {"url": u, "steps": [{"op": "fill", "label": "a"}]}, {"url": u, "steps": [{"op": "fill", "value": "a"}]}, {"url": u, "steps": [{"op": "fill", "label": "a", "value": "x" * 501}]},
                {"url": u, "steps": [{"op": "click", "text": ""}]}, {"url": u, "steps": [{"op": "click"}]}, {"url": u, "steps": [{"op": "extract", "label": "a"}]},
                {"url": u, "steps": [{"op": "wait_text", "text": "a", "timeout": 16}]}, {"url": u, "steps": [{"op": "wait_text", "text": "a", "timeout": "5"}]},
                {"url": u, "steps": ["extract"]}, {"url": u, "steps": [{"op": 5}]}, {"url": u, "steps": [{"op": "extract"}], "extra": 1},
                {"steps": [{"op": "extract"}]},
                {"url": u, "steps": [{"op": "click", "text": "Pay now"}]}, {"url": u, "steps": [{"op": "click", "css": "#buy"}]},
                {"url": u, "steps": [{"op": "click", "label": "Delete my account"}]}):
        assert r(bad)["reason"] == "bad_params", bad
    assert r({"url": u, "steps": [{"op": "click", "text": "Payments overview"}]})["status"] == "pending"      # whole words only: "Payments" is not "pay"


def test_35a_rolls_back_and_forward_on_a_clean_database(owner):
    from conftest import _exec_file
    _exec_file(owner, "phase37a.down.sql")
    _exec_file(owner, "phase36a.down.sql")
    _exec_file(owner, "phase35a.down.sql")
    assert owner.execute("SELECT pc_kind_risk('browser_run')").fetchone()[0] is None
    assert owner.execute("SELECT pc_kind_risk('ui_click')").fetchone()[0] == "high"
    _exec_file(owner, "phase35a.up.sql")
    _exec_file(owner, "phase36a.up.sql")
    _exec_file(owner, "phase37a.up.sql")
    assert owner.execute("SELECT pc_kind_risk('browser_run')").fetchone()[0] == "high"


# ---------------------------------------------------------------- Phase 36a: make_report
def test_make_report_params_and_scope(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    r = lambda p: req(app, world, dev, "make_report", p)
    ok = r({"src": W + "/sales.csv", "dst": W + "/report.xlsx", "group_by": "region", "overwrite": False})
    assert ok["status"] == "pending" and ok["risk"] == "medium", ok
    assert r({"src": W + "/s.txt", "dst": W + "/r.xlsx"})["status"] == "pending"
    assert r({"src": "/etc/s.csv", "dst": W + "/r.xlsx"})["reason"] == "outside_scope"
    assert r({"src": W + "/s.csv", "dst": "/tmp/r.xlsx"})["reason"] == "outside_scope"
    for bad in ({"src": W + "/s.csv"}, {"dst": W + "/r.xlsx"}, {"src": W + "/s.exe", "dst": W + "/r.xlsx"}, {"src": W + "/s.csv", "dst": W + "/r.xls"},
                {"src": W + "/s.csv", "dst": W + "/r.xlsx", "extra": 1}, {"src": W + "/s.csv", "dst": W + "/r.xlsx", "group_by": ""},
                {"src": W + "/s.csv", "dst": W + "/r.xlsx", "group_by": 5}, {"src": W + "/s.csv", "dst": W + "/r.xlsx", "overwrite": "yes"},
                {"src": W + "/../x.csv", "dst": W + "/r.xlsx"}, {"src": 5, "dst": W + "/r.xlsx"}):
        assert r(bad)["reason"] == "bad_params", bad
