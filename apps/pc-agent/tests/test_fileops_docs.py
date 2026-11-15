"""F10 / F12 / T08: file operations stay inside allowed folders, never overwrite without a backup, delete reversibly; reports are real files that re-open correctly."""
import os
import sys

import pytest

from jarvis_agent import actions, verify
from jarvis_agent.config import ALL_KINDS, Config


def cfg(root, **kw):
    return Config(roots=[str(root)], kinds=list(ALL_KINDS), device_id="dev1", **kw)


def act(c, kind, **p):
    return actions.execute(c, {"id": "x", "kind": kind, "params": p})


def test_copy_move_make_dir_and_no_silent_overwrite(root):
    c = cfg(root)
    (root / "a.txt").write_text("one")
    assert act(c, "copy_file", src=str(root / "a.txt"), dst=str(root / "b.txt"))[0]
    assert (root / "b.txt").read_text() == "one" and (root / "a.txt").exists()
    ok, msg = act(c, "copy_file", src=str(root / "a.txt"), dst=str(root / "b.txt"))
    assert not ok and "exists" in msg                                           # existing target: refused
    (root / "a.txt").write_text("two")
    ok, msg = act(c, "copy_file", src=str(root / "a.txt"), dst=str(root / "b.txt"), overwrite=True)
    assert ok and "backup" in msg and (root / "b.txt").read_text() == "two"
    backups = os.listdir(root / ".canai-backup")
    assert len(backups) == 1 and (root / ".canai-backup" / backups[0]).read_text() == "one"   # old content kept
    assert act(c, "make_dir", path=str(root / "sub"))[0]
    assert act(c, "move_file", src=str(root / "b.txt"), dst=str(root / "sub" / "c.txt"))[0]
    assert not (root / "b.txt").exists() and (root / "sub" / "c.txt").read_text() == "two"
    assert not act(c, "make_dir", path=str(root / "sub"))[0]                 # already there
    assert not act(c, "make_dir", path=str(root / ".canai-trash"))[0]        # reserved


def test_paths_outside_roots_and_symlinks_refused(root, tmp_path):
    c = cfg(root)
    other = tmp_path / "elsewhere"
    other.mkdir()
    (other / "x.txt").write_text("secret")
    (root / "a.txt").write_text("a")
    assert not act(c, "copy_file", src=str(other / "x.txt"), dst=str(root / "x.txt"))[0]
    assert not act(c, "copy_file", src=str(root / "a.txt"), dst=str(other / "y.txt"))[0]
    assert not act(c, "move_file", src=str(root / "a.txt"), dst=str(root / ".." / "z.txt"))[0]
    if hasattr(os, "symlink") and sys.platform != "win32":
        os.symlink(other / "x.txt", root / "link.txt")
        assert not act(c, "copy_file", src=str(root / "link.txt"), dst=str(root / "l2.txt"))[0]   # a link is never followed
        assert not act(c, "trash_file", path=str(root / "link.txt"))[0]
    assert (other / "x.txt").read_text() == "secret" and not (other / "y.txt").exists()


def test_trash_is_reversible_and_logged(root):
    c = cfg(root)
    (root / "old.txt").write_text("keep me")
    ok, msg = act(c, "trash_file", path=str(root / "old.txt"))
    assert ok and "restored" in msg and not (root / "old.txt").exists()
    kept = os.listdir(root / ".canai-trash")
    assert any(n.endswith("old.txt") for n in kept) and "manifest.jsonl" in kept
    moved = [n for n in kept if n.endswith("old.txt")][0]
    assert (root / ".canai-trash" / moved).read_text() == "keep me"          # nothing erased
    assert not act(c, "trash_file", path=str(root / ".canai-trash" / moved))[0]   # not into the trash twice
    assert not act(c, "trash_file", path=str(root))[0]                      # folders are not touched


def test_kinds_are_off_unless_enabled(root):
    c = Config(roots=[str(root)], kinds=["read_file"])                       # default-deny
    (root / "a.txt").write_text("a")
    for kind, p in [("copy_file", {"src": str(root / "a.txt"), "dst": str(root / "b.txt")}), ("trash_file", {"path": str(root / "a.txt")}),
                    ("create_xlsx", {"path": str(root / "r.xlsx"), "sheets": {"S": [[1]]}})]:
        ok, msg = act(c, kind, **p)
        assert not ok and "disabled" in msg
    assert (root / "a.txt").exists() and not (root / "b.txt").exists()


def test_xlsx_created_reopened_and_verified(root):
    c = cfg(root)
    rows = [["পণ্য", "বিক্রি"], ["A", 120], ["B", 80.5], ["মোট", 200.5]]
    ok, msg = act(c, "create_xlsx", path=str(root / "report.xlsx"), sheets={"Sales": rows})
    assert ok, msg
    good, ev = verify.check(c, {"type": "xlsx_cells", "path": str(root / "report.xlsx"), "expect": {"Sales": {"A1": "পণ্য", "B2": 120, "B3": 80.5, "B4": 200.5}}})
    assert good, ev
    bad, ev = verify.check(c, {"type": "xlsx_cells", "path": str(root / "report.xlsx"), "expect": {"Sales": {"B2": 999}}})
    assert not bad and "B2" in ev                                           # a wrong value is caught, not assumed
    assert not verify.check(c, {"type": "xlsx_cells", "path": str(root / "report.xlsx"), "expect": {"Nope": {"A1": 1}}})[0]


def test_xlsx_formula_text_is_not_a_formula_and_limits(root):
    c = cfg(root)
    assert act(c, "create_xlsx", path=str(root / "f.xlsx"), sheets={"S": [["=cmd|' /C calc'!A0", "-5", "@x"]]})[0]
    from openpyxl import load_workbook
    ws = load_workbook(root / "f.xlsx").active
    assert ws["A1"].data_type == "s" and ws["A1"].value.startswith("=")      # stored as text
    assert not act(c, "create_xlsx", path=str(root / "n.txt"), sheets={"S": [[1]]})[0]            # wrong extension
    assert not act(c, "create_xlsx", path=str(root / "x.xlsx"), sheets={"S": [[{"a": 1}]]})[0]    # not text/number
    assert not act(c, "create_xlsx", path=str(root / "x.xlsx"), sheets={"S": [[1]] * 2001})[0]
    assert not act(c, "create_xlsx", path=str(root / "x.xlsx"), sheets={"a/b": [[1]]})[0]
    assert not (root / "x.xlsx").exists()
    (root / "r.xlsx").write_text("old")
    assert not act(c, "create_xlsx", path=str(root / "r.xlsx"), sheets={"S": [[1]]})[0]            # no silent overwrite
    ok, msg = act(c, "create_xlsx", path=str(root / "r.xlsx"), sheets={"S": [[1]]}, overwrite=True)
    assert ok and (root / ".canai-backup").is_dir()


def test_docx_created_and_verified(root):
    c = cfg(root)
    ok, msg = act(c, "create_docx", path=str(root / "r.docx"), title="সাপ্তাহিক রিপোর্ট", paragraphs=["মোট বিক্রি ২০০ টাকা", "Second line"])
    assert ok, msg
    assert verify.check(c, {"type": "docx_text", "path": str(root / "r.docx"), "contains": "মোট বিক্রি"})[0]
    assert not verify.check(c, {"type": "docx_text", "path": str(root / "r.docx"), "contains": "অন্য কিছু"})[0]
    assert not act(c, "create_docx", path=str(root / "r2.docx"), paragraphs=[])[0]


def test_verify_detects_missing_corrupt_and_hash(root):
    c = cfg(root)
    (root / "x.xlsx").write_bytes(b"not a workbook")
    ok, ev = verify.check(c, {"type": "xlsx_cells", "path": str(root / "x.xlsx"), "expect": {"S": {"A1": 1}}})
    assert not ok and "Could not verify" in ev                              # corrupt file reads as 'not verified'
    assert not verify.check(c, {"type": "file_exists", "path": str(root / "missing")})[0]
    (root / "h.txt").write_text("abc")
    h = verify.sha256_file(str(root / "h.txt"))
    assert verify.check(c, {"type": "file_hash", "path": str(root / "h.txt"), "sha256": h})[0]
    assert not verify.check(c, {"type": "file_hash", "path": str(root / "h.txt"), "sha256": "0" * 64})[0]
    assert verify.check(c, {"type": "file_absent", "path": str(root / "gone.txt")})[0]
    assert not verify.check(c, {"type": "file_exists", "path": "/etc/passwd"})[0]               # outside the allowed folders
