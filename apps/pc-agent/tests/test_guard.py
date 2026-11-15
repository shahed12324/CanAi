import os
import sys

import pytest

from jarvis_agent import guard
from jarvis_agent.guard import Refused


def test_path_inside_root_is_accepted_and_resolved(root):
    f = root / "a.txt"
    f.write_text("x")
    assert guard.path_in_roots(str(f), [str(root)]) == os.path.realpath(f)
    assert guard.path_in_roots(str(root), [str(root)]) == os.path.realpath(root)


@pytest.mark.parametrize("bad", ["", None, 5, "relative/a.txt", "a" * 521, "/x\n/y", "/x\x00y", "/ok/../etc/passwd", "C:\\a\\..\\b"])
def test_malformed_paths_are_refused(bad, root):
    with pytest.raises(Refused):
        guard.path_in_roots(bad, [str(root)])


def test_outside_and_lookalike_folders_are_refused(root, tmp_path):
    other = tmp_path / "allowed-evil"
    other.mkdir()
    (other / "a").write_text("x")
    with pytest.raises(Refused):
        guard.path_in_roots(str(other / "a"), [str(root)])        # same prefix, different folder
    with pytest.raises(Refused):
        guard.path_in_roots("/etc/passwd", [str(root)])


def test_empty_roots_allow_nothing(root):
    with pytest.raises(Refused):
        guard.path_in_roots(str(root / "a"), [])


def test_whole_drive_root_is_never_a_valid_folder(root):
    with pytest.raises(Refused):
        guard.path_in_roots("/etc/hosts", ["/"])


@pytest.mark.skipif(sys.platform == "win32", reason="symlinks")
def test_symlink_escape_is_refused(root, tmp_path):
    secret = tmp_path / "secret"
    secret.mkdir()
    (secret / "pw.txt").write_text("secret")
    (root / "link").symlink_to(secret)
    with pytest.raises(Refused):
        guard.path_in_roots(str(root / "link" / "pw.txt"), [str(root)])
    (root / "flink").symlink_to(secret / "pw.txt")
    with pytest.raises(Refused):
        guard.path_in_roots(str(root / "flink"), [str(root)])


@pytest.mark.skipif(sys.platform == "win32", reason="symlinks")
def test_symlinked_root_is_followed_both_ways(root, tmp_path):
    link = tmp_path / "rootlink"
    link.symlink_to(root)
    (root / "a").write_text("x")
    assert guard.path_in_roots(str(link / "a"), [str(root)]) == os.path.realpath(root / "a")
    assert guard.path_in_roots(str(root / "a"), [str(link)]) == os.path.realpath(root / "a")


def test_parent_in_roots_does_not_resolve_the_name(root, tmp_path):
    parent, name = guard.parent_in_roots(str(root / "x.txt"), [str(root)])
    assert (parent, name) == (os.path.realpath(root), "x.txt")
    for bad in (str(root) + "/", "/", ".", "/.."):
        with pytest.raises(Refused):
            guard.parent_in_roots(bad, [str(root)])


def test_program_names(monkeypatch):
    assert os.path.basename(guard.program_ok("ls", ["LS"])).startswith("ls")
    for bad in ("../bin/sh", "a b", "sh;ls", "", "x" * 61, None, "bin/ls"):
        with pytest.raises(Refused):
            guard.program_ok(bad, ["sh", "ls", "../bin/sh", "bin/ls"])
    with pytest.raises(Refused):
        guard.program_ok("ls", [])                                  # not on the local list
    with pytest.raises(Refused):
        guard.program_ok("definitely-not-installed-xyz", ["definitely-not-installed-xyz"])


def test_args(root):
    assert guard.args_ok(None) == []
    assert guard.args_ok(["-l", "x"]) == ["-l", "x"]
    for bad in ("x", [1], ["a\nb"], ["a" * 201], ["a"] * 21):
        with pytest.raises(Refused):
            guard.args_ok(bad)


def test_urls():
    h = ["example.com"]
    assert guard.url_ok("https://example.com/a?b=1", h)
    assert guard.url_ok("https://docs.example.com", h)
    assert guard.url_ok("https://EXAMPLE.com:8443/x", h)
    for bad in ("http://example.com", "https://evil-example.com", "https://example.com.evil.io", "https://user:pw@example.com",
                "https://example.com@evil.io", "https://evil.io#@example.com", "javascript:alert(1)", "https://example.com/a b", "https://example.com/\n", "", None, "https://" + "a" * 2000 + ".example.com"):
        with pytest.raises(Refused):
            guard.url_ok(bad, h)
    with pytest.raises(Refused):
        guard.url_ok("https://example.com", [])


def test_dotdot_is_refused_even_when_it_would_land_inside(root):
    (root / "sub").mkdir()
    (root / "a").write_text("x")
    with pytest.raises(Refused):
        guard.path_in_roots(str(root / "sub") + "/../a", [str(root)])
