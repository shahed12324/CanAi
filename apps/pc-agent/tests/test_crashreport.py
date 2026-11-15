"""Phase 12: local crash reports - what they hold, what they never hold, rotation, hooks, CLI, window API."""
import json
import os
import subprocess
import sys
import threading
from pathlib import Path

import pytest

from jarvis_agent import cli, crashreport
from test_gui import Win


def boom(msg="x"):
    raise RuntimeError(msg)


def make(msg="oops", where="main"):
    try:
        boom(msg)
    except RuntimeError as e:
        return crashreport.record(type(e), e, e.__traceback__, where)


def test_report_is_written_private_and_listed():
    name = make("disk full")
    assert name and name.startswith("crash-")
    p = crashreport.crash_dir() / name
    assert p.exists()
    if os.name == "posix":
        assert (p.stat().st_mode & 0o777) == 0o600
    r = crashreport.read(name)
    assert r["type"] == "RuntimeError" and r["message"] == "disk full" and r["version"]
    assert r["frames"][-1]["func"] == "boom" and r["frames"][-1]["file"] == "test_crashreport.py"
    assert crashreport.list_reports()[0]["name"] == name


def test_report_holds_no_secrets_home_path_or_source_code(monkeypatch):
    tok = "a" * 64
    msg = f"failed Bearer {'Z' * 30} token={tok} sk-{'k' * 24} at {Path.home()}/docs http://127.0.0.1:43211/#SECRETFRAGMENT12345"
    name = make(msg)
    raw = (crashreport.crash_dir() / name).read_text()
    for bad in (tok, "Z" * 30, "k" * 24, "SECRETFRAGMENT12345", str(Path.home()) + "/docs"):
        assert bad not in raw
    assert "~/docs" in raw or str(Path.home()) == "/"
    assert "raise RuntimeError" not in raw            # no source lines
    assert "frames" in raw and "locals" not in raw


def test_message_is_short_and_printable():
    name = make("x" * 5000 + "\x00\x07")
    r = crashreport.read(name)
    assert len(r["message"]) <= crashreport.MAX_MSG + 1 and "\x00" not in r["message"]


def test_only_newest_reports_are_kept(monkeypatch):
    for i in range(crashreport.KEEP + 4):
        make(f"m{i}")
    files = [p for p in crashreport.crash_dir().iterdir() if p.name.startswith("crash-") and p.suffix == ".json"]
    assert len(files) == crashreport.KEEP


def test_record_never_raises_even_if_the_folder_is_unusable(tmp_path, monkeypatch):
    f = tmp_path / "file"
    f.write_text("x")
    monkeypatch.setenv("JARVIS_AGENT_HOME", str(f))   # a FILE where a folder is needed
    assert make("x") is None


def test_read_refuses_path_tricks():
    for n in ("../config.json", "crash-1.json", "/etc/passwd", "crash-0000000001-zzzzzz.json", ""):
        assert crashreport.read(n) is None


def test_clear_and_bundle():
    make("a")
    make("b")
    assert len(json.loads(crashreport.bundle())["reports"]) == 2
    assert crashreport.clear() == 2
    assert crashreport.list_reports() == []


def test_shell_reports_are_listed_too():
    d = crashreport.crash_dir()
    d.mkdir(parents=True)
    (d / "crash-shell-1700000000.json").write_text(json.dumps({"kind": "shell", "time": 1700000000, "where": "src/main.rs:1", "message": "panic"}))
    rows = crashreport.list_reports()
    assert rows[0]["kind"] == "shell" and crashreport.read("crash-shell-1700000000.json")["message"] == "panic"


def test_thread_exception_is_recorded(monkeypatch):
    import threading as th
    monkeypatch.setattr(crashreport, "_installed", False)
    old_s, old_t = sys.excepthook, th.excepthook
    sys.excepthook = lambda *a: None
    th.excepthook = lambda a: None
    try:
        crashreport.install()
        t = th.Thread(target=lambda: boom("in thread"))
        t.start()
        t.join()
        assert any(r["message"] == "in thread" and r["where"] == "thread" for r in crashreport.list_reports())
    finally:
        sys.excepthook, th.excepthook = old_s, old_t


def test_cli_unexpected_error_saves_report_and_returns_70(monkeypatch, capsys):
    monkeypatch.setattr(cli, "cmd_status", lambda a: boom("cli broke"))
    # main() rebinds set_defaults(fn=cmd_status) at parse time, so patch the name it resolves
    assert cli.main(["status"]) == 70
    assert "crash report was saved" in capsys.readouterr().out
    assert crashreport.list_reports()[0]["message"] == "cli broke"


def test_cli_crash_report_commands(capsys):
    assert cli.main(["crash-report"]) == 0
    assert "No crash reports" in capsys.readouterr().out
    name = make("zz")
    assert cli.main(["crash-report"]) == 0 and name in capsys.readouterr().out
    assert cli.main(["crash-report", "--show", name]) == 0 and '"zz"' in capsys.readouterr().out
    assert cli.main(["crash-report", "--show", "../x"]) == 1
    assert cli.main(["crash-report", "--bundle"]) == 0 and "reports" in capsys.readouterr().out
    assert cli.main(["crash-report", "--clear"]) == 0 and crashreport.list_reports() == []


def test_window_api_lists_shows_clears_and_needs_the_token():
    w = Win()
    name = make("window")
    assert w.j("GET", "/api/crashes", token=None)[0] == 401
    s, j = w.j("GET", "/api/crashes")
    assert s == 200 and j["reports"][0]["name"] == name
    s, j = w.j("GET", "/api/crashes?name=" + name)
    assert s == 200 and j["message"] == "window"
    assert w.j("GET", "/api/crashes?name=..%2Fconfig.json")[0] == 404
    assert w.j("POST", "/api/crashes/clear", {}, token=None)[0] == 401
    assert w.j("POST", "/api/crashes/clear", {})[1]["deleted"] == 1
    assert w.j("GET", "/api/crashes")[1]["reports"] == []
