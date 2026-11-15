import os
import sys

import pytest

from jarvis_agent import actions
from jarvis_agent.config import ALL_KINDS, Config


def cfg(root, **kw):
    return Config(roots=[str(root)], kinds=list(ALL_KINDS), **kw)


def act(c, kind, **params):
    return actions.execute(c, {"id": "x", "kind": kind, "params": params})


def test_read_file_and_cut(root):
    (root / "a.txt").write_text("হ্যালো\nworld", encoding="utf-8")
    ok, out = act(cfg(root), "read_file", path=str(root / "a.txt"))
    assert ok and out == "হ্যালো\nworld"
    (root / "big.txt").write_text("x" * 50000)
    ok, out = act(cfg(root), "read_file", path=str(root / "big.txt"))
    assert ok and len(out) <= actions.RESULT_MAX + 40 and "truncated" in out
    assert not act(cfg(root), "read_file", path=str(root / "nope"))[0]
    assert not act(cfg(root), "read_file", path=str(root))[0]                  # a folder


def test_read_binary_does_not_crash(root):
    (root / "b.bin").write_bytes(bytes(range(256)) * 4)
    ok, out = act(cfg(root), "read_file", path=str(root / "b.bin"))
    assert ok and isinstance(out, str)


def test_list_dir(root):
    (root / "z.txt").write_text("1")
    (root / "A").mkdir()
    ok, out = act(cfg(root), "list_dir", path=str(root))
    assert ok and out.splitlines() == ["A/", "z.txt"]
    empty = root / "e"
    empty.mkdir()
    assert act(cfg(root), "list_dir", path=str(empty)) == (True, "(empty folder)")
    for i in range(130):
        (root / f"f{i:03}").write_text("")
    out = act(cfg(root), "list_dir", path=str(root))[1]
    assert "more" in out
    assert not act(cfg(root), "list_dir", path=str(root / "z.txt"))[0]


def test_write_file_is_atomic_and_overwrites(root):
    p = root / "n.txt"
    assert act(cfg(root), "write_file", path=str(p), content="এক")[0]
    assert p.read_text(encoding="utf-8") == "এক"
    assert act(cfg(root), "write_file", path=str(p), content="দুই")[0]
    assert p.read_text(encoding="utf-8") == "দুই"
    assert [f.name for f in root.iterdir()] == ["n.txt"]                       # no temp files left


def test_write_file_refusals(root):
    c = cfg(root)
    for params in ({"content": ""}, {"content": "a\x00b"}, {"content": "x" * (actions.WRITE_MAX_BYTES + 1)}, {"content": 5}):
        assert not act(c, "write_file", path=str(root / "w.txt"), **params)[0]
    assert not (root / "w.txt").exists()
    assert not act(c, "write_file", path=str(root), content="x")[0]            # a folder
    assert not act(c, "write_file", path=str(root / "nofolder" / "w.txt"), content="x")[0]
    assert not act(c, "write_file", path="/tmp/outside-jarvis-test.txt", content="x")[0]
    assert not os.path.exists("/tmp/outside-jarvis-test.txt")


@pytest.mark.skipif(sys.platform == "win32", reason="symlinks")
def test_write_through_symlink_out_is_refused(root, tmp_path):
    out = tmp_path / "outside"
    out.mkdir()
    (root / "l").symlink_to(out)
    ok, msg = act(cfg(root), "write_file", path=str(root / "l" / "evil.txt"), content="x")
    assert not ok and not (out / "evil.txt").exists()


def test_delete_file_only_files_inside(root, tmp_path):
    f = root / "d.txt"
    f.write_text("x")
    assert act(cfg(root), "delete_file", path=str(f))[0] and not f.exists()
    (root / "dir").mkdir()
    assert not act(cfg(root), "delete_file", path=str(root / "dir"))[0] and (root / "dir").exists()
    assert not act(cfg(root), "delete_file", path=str(root / "gone"))[0]
    outside = tmp_path / "o.txt"
    outside.write_text("x")
    assert not act(cfg(root), "delete_file", path=str(outside))[0] and outside.exists()


@pytest.mark.skipif(sys.platform == "win32", reason="symlinks")
def test_delete_removes_the_link_not_its_target(root, tmp_path):
    target = tmp_path / "keep.txt"
    target.write_text("keep")
    (root / "ln").symlink_to(target)
    assert act(cfg(root), "delete_file", path=str(root / "ln"))[0]
    assert target.exists() and not os.path.lexists(root / "ln")


def test_kind_must_be_switched_on_locally(root):
    c = Config(roots=[str(root)])                                              # default kinds only
    (root / "x").write_text("1")
    assert act(c, "read_file", path=str(root / "x"))[0]
    ok, msg = act(c, "delete_file", path=str(root / "x"))
    assert not ok and "disabled" in msg and (root / "x").exists()
    ok, msg = act(c, "run_command", command="ls")
    assert not ok and "disabled" in msg


def test_unsupported_and_malformed_jobs_fail_politely(root):
    c = cfg(root)
    for kind in ("power", "system_setting", "nonsense"):
        ok, msg = act(c, kind, action="shutdown")
        assert not ok and "not support" in msg
    assert actions.execute(c, {"kind": 5, "params": {}}) [0] is False
    assert actions.execute(c, {"kind": "read_file", "params": "x"})[0] is False
    assert actions.execute(c, {})[0] is False


@pytest.mark.skipif(sys.platform == "win32", reason="posix tools")
def test_run_command_no_shell_and_allowlist(root):
    c = cfg(root, commands=["echo", "sleep"])
    ok, out = act(c, "run_command", command="echo", args=["hi", "$HOME", ";", "ls"])
    assert ok and "hi $HOME ; ls" in out and "exit=0" in out                    # arguments are literal text
    ok, out = act(c, "run_command", command="ls")
    assert not ok and "allowed list" in out
    ok, out = act(c, "run_command", command="echo", args="not-a-list")
    assert not ok
    ok, out = act(c, "run_command", command="sh", args=["-c", "id"])
    assert not ok


@pytest.mark.skipif(sys.platform == "win32", reason="posix tools")
def test_run_command_timeout_and_exit_code(root, monkeypatch):
    monkeypatch.setattr(actions, "COMMAND_TIMEOUT", 1)
    c = cfg(root, commands=["sleep", "false"])
    ok, out = act(c, "run_command", command="sleep", args=["5"])
    assert not ok and "stopped" in out.lower()
    ok, out = act(c, "run_command", command="false")
    assert ok and "exit=1" in out


@pytest.mark.skipif(sys.platform == "win32", reason="posix tools")
def test_run_command_runs_inside_the_first_root(root):
    ok, out = act(cfg(root, commands=["pwd"]), "run_command", command="pwd")
    assert ok and os.path.realpath(root) in out


def test_browser_checks_host_before_opening(root, monkeypatch):
    opened = []
    monkeypatch.setattr(actions.webbrowser, "open", lambda u: opened.append(u) or True)
    c = cfg(root, hosts=["example.com"])
    assert act(c, "browser_navigate", url="https://www.example.com/x")[0] and opened == ["https://www.example.com/x"]
    assert not act(c, "browser_navigate", url="https://evil.io")[0] and len(opened) == 1
    monkeypatch.setattr(actions.webbrowser, "open", lambda u: False)
    ok, msg = act(c, "browser_navigate", url="https://example.com")
    assert not ok and "browser" in msg


@pytest.mark.skipif(sys.platform == "win32", reason="posix tools")
def test_open_app_needs_the_local_list(root, monkeypatch):
    started = []
    monkeypatch.setattr(actions.subprocess, "Popen", lambda *a, **k: started.append(a[0]))
    assert not act(cfg(root), "open_app", app="true")[0] and started == []
    assert act(cfg(root, apps=["true"]), "open_app", app="true")[0] and len(started) == 1


def test_unexpected_errors_are_contained(root, monkeypatch):
    monkeypatch.setitem(actions.HANDLERS, "read_file", lambda c, p: 1 / 0)
    ok, msg = act(cfg(root), "read_file", path=str(root))
    assert not ok and "ZeroDivisionError" in msg and "Traceback" not in msg
