"""Phase 26c: server rules for copy_file / move_file / make_dir / trash_file / create_xlsx / create_docx."""
import pytest
from psycopg.types.json import Jsonb

from conftest import as_admin
from test_pc_control import ROOTS, clean, device, policy, req  # noqa: F401
from test_memory import second_user  # noqa: F401

W = "/home/me/work"


def test_risk_classes(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    for kind, params, risk in [
        ("copy_file", {"src": W + "/a", "dst": W + "/b"}, "medium"), ("make_dir", {"path": W + "/new"}, "medium"),
        ("create_xlsx", {"path": W + "/r.xlsx", "sheets": {"S": [[1]]}}, "medium"), ("create_docx", {"path": W + "/r.docx", "paragraphs": ["x"]}, "medium"),
        ("move_file", {"src": W + "/a", "dst": W + "/b"}, "high"), ("trash_file", {"path": W + "/a"}, "high"),
    ]:
        r = req(app, world, dev, kind, params)
        assert r["status"] == "pending" and r["risk"] == risk, (kind, r)


def test_both_ends_of_copy_and_move_must_be_inside_the_folders(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    for kind in ("copy_file", "move_file"):
        assert req(app, world, dev, kind, {"src": W + "/a", "dst": "/etc/cron.d/x"})["reason"] == "outside_scope"
        assert req(app, world, dev, kind, {"src": "/etc/passwd", "dst": W + "/p"})["reason"] == "outside_scope"
        assert req(app, world, dev, kind, {"src": W + "/a", "dst": W + "/../../etc/x"})["reason"] == "bad_params"
        assert req(app, world, dev, kind, {"src": W + "/a"})["reason"] == "bad_params"
        assert req(app, world, dev, kind, {"src": W + "/a", "dst": "relative/x"})["reason"] == "bad_params"
        assert req(app, world, dev, kind, {"src": W + "/a", "dst": W + "/b", "overwrite": "yes"})["reason"] == "bad_params"
        assert req(app, world, dev, kind, {"src": W + "/a", "dst": W + "/b", "overwrite": True})["status"] == "pending"


def test_single_path_kinds_are_scoped(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    for kind in ("make_dir", "trash_file", "create_xlsx", "create_docx"):
        assert req(app, world, dev, kind, {"path": "/etc/x"})["reason"] == "outside_scope"
        assert req(app, world, dev, kind, {"path": W + "/../x"})["reason"] == "bad_params"
        assert req(app, world, dev, kind, {})["reason"] == "bad_params"


def test_move_and_trash_can_never_be_auto_approved_or_set_to_allow(app, owner, world):
    dev, _ = device(app, world)
    assert policy(app, world, dev, modes={"move_file": "allow"}) == "bad_input"
    assert policy(app, world, dev, modes={"trash_file": "allow"}) == "bad_input"
    assert policy(app, world, dev, modes={"copy_file": "allow", "make_dir": "allow", "move_file": "ask", "trash_file": "deny"}) == "ok"
    assert req(app, world, dev, "copy_file", {"src": W + "/a", "dst": W + "/b"})["status"] == "approved"
    assert req(app, world, dev, "trash_file", {"path": W + "/a"})["status"] == "blocked"
    as_admin(owner)
    owner.execute("UPDATE pc_policies SET modes = %s WHERE device_id = %s", (Jsonb({"move_file": "allow", "trash_file": "allow"}), dev))
    assert req(app, world, dev, "move_file", {"src": W + "/a", "dst": W + "/b"})["status"] == "pending"   # even a tampered row cannot auto-approve
    assert req(app, world, dev, "trash_file", {"path": W + "/a"})["status"] == "pending"


def test_old_kinds_unchanged_and_unknown_kinds_rejected(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    assert req(app, world, dev, "read_file", {"path": W + "/a"})["status"] == "pending"
    assert req(app, world, dev, "read_file", {"path": "/etc/passwd"})["reason"] == "outside_scope"
    assert req(app, world, dev, "format_disk", {"path": W})["reason"] in ("bad_input", "denied_by_policy", "bad_params", "unknown_kind")
