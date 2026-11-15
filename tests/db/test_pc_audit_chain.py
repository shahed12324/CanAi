"""Phase 37a: the server copy of the desktop agent's hash-chained audit log.  Rows are produced by the agent's REAL Audit class, so the SQL hash must agree with Python byte for byte."""
import json
import sys
from pathlib import Path

import psycopg
import pytest
from psycopg.types.json import Jsonb

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "apps" / "pc-agent"))
from jarvis_agent.taskengine import Audit  # noqa: E402

from conftest import as_admin  # noqa: E402
from test_pc_control import clean, device  # noqa: E402,F401  (clean = the autouse fixture that empties the pc_* tables after each test)
from test_memory import second_user  # noqa: E402,F401

ZERO = "0" * 64


def rows(tmp_path, items):
    a = Audit(tmp_path / "audit.jsonl", clock=lambda: 1_700_000_000)
    for it in items:
        a.record(*it)
    return [json.loads(x) for x in (tmp_path / "audit.jsonl").read_text(encoding="utf-8").splitlines()]


def ingest(app, world, dev, start, rs, who="a"):
    return app.execute("SELECT pc_audit_ingest(%s,%s,%s,%s)", (world[who]["tenant"], dev, start, Jsonb(rs))).fetchone()[0]


def head(app, world, dev, who="a"):
    return app.execute("SELECT pc_audit_head(%s,%s)", (world[who]["tenant"], dev)).fetchone()[0]


TRICKY = [("t1", "", "task_started", "3 ধাপ"), ("t1", "s1", "act", 'quote " backslash \\ newline \n tab \t'), ("t1", "s1", "verify", "ok: emoji 😀 and ü and 日本語"),
          ("t1", "s1", "act_failed", "control \x01 char"), ("t1", "", "task_completed", ""), ("t2", "s9", "approved", "x" * 300)]


def test_sql_hash_equals_python_hash_for_awkward_text(app, owner, world, tmp_path):
    dev, _ = device(app, world)
    rs = rows(tmp_path, TRICKY)
    assert ingest(app, world, dev, 0, rs) == {"status": "ok", "seq": len(rs), "h": rs[-1]["h"]}
    assert head(app, world, dev) == {"status": "ok", "seq": len(rs), "h": rs[-1]["h"]}


def test_empty_head_and_continuing_in_batches(app, owner, world, tmp_path):
    dev, _ = device(app, world)
    assert head(app, world, dev) == {"status": "ok", "seq": 0, "h": ZERO}
    rs = rows(tmp_path, TRICKY)
    assert ingest(app, world, dev, 0, rs[:2])["seq"] == 2
    assert ingest(app, world, dev, 2, rs[2:])["status"] == "ok"
    assert head(app, world, dev)["h"] == rs[-1]["h"]


def test_wrong_start_is_refused_with_the_real_head(app, owner, world, tmp_path):
    dev, _ = device(app, world)
    rs = rows(tmp_path, TRICKY)
    ingest(app, world, dev, 0, rs[:3])
    r = ingest(app, world, dev, 1, rs[1:4])                           # a repeat of rows the server already has
    assert r["status"] == "seq_mismatch" and r["seq"] == 3 and r["h"] == rs[2]["h"]
    assert ingest(app, world, dev, 5, rs[3:])["status"] == "seq_mismatch"          # a gap
    assert head(app, world, dev)["seq"] == 3


def test_changed_row_missing_row_and_forged_link_are_refused_and_nothing_is_stored(app, owner, world, tmp_path):
    dev, _ = device(app, world)
    rs = rows(tmp_path, TRICKY)
    bad = [dict(r) for r in rs]; bad[2]["detail"] = "changed"                                   # content changed, old hash kept
    r = ingest(app, world, dev, 0, bad)
    assert r["status"] == "chain_broken" and r["at"] == 2 and head(app, world, dev)["seq"] == 0   # all or nothing
    assert ingest(app, world, dev, 0, [rs[0], rs[2]])["status"] == "chain_broken"              # a removed row
    forged = [dict(r) for r in rs[:2]]; forged[1]["prev"] = "f" * 64
    assert ingest(app, world, dev, 0, forged)["status"] == "chain_broken"
    assert ingest(app, world, dev, 0, rs[1:])["status"] == "chain_broken"                        # must start from the stored head (zeros)
    assert head(app, world, dev)["seq"] == 0


def test_a_later_batch_must_continue_from_the_stored_head(app, owner, world, tmp_path):
    dev, _ = device(app, world)
    rs = rows(tmp_path, TRICKY)
    ingest(app, world, dev, 0, rs[:2])
    a2 = Audit(tmp_path / "other.jsonl", clock=lambda: 5)
    a2.record("z", "", "task_started", "another history")
    alien = json.loads((tmp_path / "other.jsonl").read_text().splitlines()[0])
    assert ingest(app, world, dev, 2, [alien])["status"] == "chain_broken"                       # a different history cannot be spliced on


def test_malformed_input_is_bad_input(app, owner, world, tmp_path):
    dev, _ = device(app, world)
    rs = rows(tmp_path, TRICKY)
    good = rs[0]
    for bad in ([], [good] * 201, "x", [5], [{**good, "extra": 1}], [{k: v for k, v in good.items() if k != "step"}], [{**good, "t": "1700"}], [{**good, "t": -1}], [{**good, "t": 1.5}],
                [{**good, "event": ""}], [{**good, "event": "e" * 41}], [{**good, "detail": "d" * 401}], [{**good, "task": "t" * 65}], [{**good, "h": "ABC"}], [{**good, "prev": 7}], [{**good, "detail": None}]):
        assert ingest(app, world, dev, 0, bad)["status"] == "bad_input", bad
    assert ingest(app, world, dev, -1, [good])["status"] == "bad_input"
    assert head(app, world, dev)["seq"] == 0


def test_other_workspace_and_revoked_devices_cannot_use_a_device(app, owner, world, tmp_path):
    dev, _ = device(app, world)
    rs = rows(tmp_path, TRICKY)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):                                     # another workspace: the tenant check itself refuses
        ingest(app, world, dev, 0, rs[:1], who="b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        head(app, world, dev, who="b")
    ingest(app, world, dev, 0, rs[:1])
    assert app.execute("SELECT pc_device_revoke(%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], dev)).fetchone()[0] == "ok"
    assert ingest(app, world, dev, 1, rs[1:2])["status"] == "not_found"


def test_stored_rows_cannot_be_changed_or_deleted_or_truncated_even_by_the_owner(app, owner, world, tmp_path):
    dev, _ = device(app, world)
    ingest(app, world, dev, 0, rows(tmp_path, TRICKY[:2]))
    as_admin(owner)
    for sql in ("UPDATE pc_audit_chain SET detail = 'x'", "DELETE FROM pc_audit_chain", "TRUNCATE pc_audit_chain"):
        with pytest.raises(psycopg.errors.Error):
            owner.execute(sql)
    with pytest.raises(psycopg.errors.Error):
        app.execute("INSERT INTO pc_audit_chain (tenant_id, device_id, seq, t, task, step, event, detail, prev, h) VALUES (%s,%s,0,1,'','','e','',%s,%s)", (world["a"]["tenant"], dev, ZERO, ZERO))


def test_a_workspace_sees_only_its_own_stored_rows(app, owner, world, tmp_path):
    dev, _ = device(app, world)
    ingest(app, world, dev, 0, rows(tmp_path, TRICKY[:2]))
    from test_accounting import ctx
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM pc_audit_chain").fetchone()[0] == 0
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM pc_audit_chain").fetchone()[0] == 2


def test_37a_down_refuses_when_rows_exist_and_rolls_back_cleanly_when_empty(app, owner, world, tmp_path):
    from conftest import _exec_file
    dev, _ = device(app, world)
    ingest(app, world, dev, 0, rows(tmp_path, TRICKY[:1]))
    with pytest.raises(psycopg.errors.Error, match="cannot roll back"):
        _exec_file(owner, "phase37a.down.sql")
    assert owner.execute("SELECT to_regclass('pc_audit_chain') IS NOT NULL").fetchone()[0] is True


def test_37a_rolls_back_and_forward_on_a_clean_database(owner):
    from conftest import _exec_file
    _exec_file(owner, "phase37a.down.sql")
    assert owner.execute("SELECT to_regclass('pc_audit_chain') IS NULL").fetchone()[0] is True
    assert owner.execute("SELECT count(*) FROM pg_proc WHERE proname LIKE 'pc_audit_%'").fetchone()[0] == 0
    _exec_file(owner, "phase37a.up.sql")
    assert owner.execute("SELECT pc_audit_hash(1, 'a', 'b', 'c', 'd', %s)", ("0" * 64,)).fetchone()[0] != ""
