"""Phase 26d: plans, per-step approvals, and HMAC-signed hand-over to the agent."""
import hashlib
import hmac
import json
import time

import pytest
from psycopg.types.json import Jsonb

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401
from test_mobile import HASH, device as phone, pin_ok  # noqa: F401
from test_pc_control import clean, device, h, policy, ROOTS  # noqa: F401
from test_pc_stepup import step_up, with_pin  # noqa: F401

W = "/home/me/work"


def plan(*steps):
    return {"steps": list(steps)}


def S(i, kind, **params):
    return {"id": f"s{i}", "kind": kind, "params": params}


GOOD = plan(S(1, "read_file", path=W + "/a.txt"), S(2, "create_xlsx", path=W + "/r.xlsx", sheets={"S": [["A", 1]]}), S(3, "trash_file", path=W + "/old.txt"))


def submit(app, world, dev, p, goal="make a report", who="a", user=None):
    u = ctx(app, world, who)
    return app.execute("SELECT pc_plan_submit(%s,%s,%s,%s,%s)", (world[who]["tenant"], user or u, dev, goal, Jsonb(p))).fetchone()[0]


def decide(app, world, pid, how="approve", who="a", user=None):
    u = ctx(app, world, who)
    return app.execute("SELECT pc_plan_decide(%s,%s,%s,%s)", (world[who]["tenant"], user or u, pid, how)).fetchone()[0]


def step_ok(app, world, pid, step, who="a", user=None):
    u = ctx(app, world, who)
    return app.execute("SELECT pc_plan_step_approve(%s,%s,%s,%s)", (world[who]["tenant"], user or u, pid, step)).fetchone()[0]


def m_step(app, world, ph, pid, step, who="a", user=None):
    ctx(app, world, who)
    return app.execute("SELECT mobile_pc_plan_step_approve(%s,%s,%s,%s)", (ph, user or world[who]["user"], pid, step)).fetchone()[0]


def pull(app, world, dev, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT pc_plan_next(%s,%s)", (world[who]["tenant"], dev)).fetchone()[0]


def report(app, world, dev, pid, state="done", result="ok", who="a"):
    ctx(app, world, who)
    return app.execute("SELECT pc_plan_report(%s,%s,%s,%s,%s)", (world[who]["tenant"], dev, pid, state, result)).fetchone()[0]


@pytest.fixture(autouse=True)
def clean_plans(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE pc_plans DISABLE TRIGGER pc_plans_guard_trg")
    owner.execute("ALTER TABLE pc_plan_step_approvals DISABLE TRIGGER pc_plan_approvals_guard_trg")
    owner.execute("DELETE FROM pc_plan_step_approvals")
    owner.execute("DELETE FROM pc_plans")
    owner.execute("DELETE FROM mobile_devices")
    owner.execute("ALTER TABLE pc_plans ENABLE TRIGGER pc_plans_guard_trg")
    owner.execute("ALTER TABLE pc_plan_step_approvals ENABLE TRIGGER pc_plan_approvals_guard_trg")


def mkdev(app, world, owner):
    dev, tok = device(app, world)
    policy(app, world, dev)
    return dev, tok


# ---- crypto: RFC 4231 test case 2, and agreement with Python's hmac for long keys
def test_hmac_matches_rfc4231_and_python(app, owner):
    q = lambda k, m: owner.execute("SELECT pc_hmac_sha256(%s,%s)", (k, m)).fetchone()[0]
    assert q("Jefe", "what do ya want for nothing?") == "5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843"
    for k, m in [("k" * 64, "x"), ("k" * 100, "hello wörld"), ("", "empty key"), ("abc", "")]:
        assert q(k, m) == hmac.new(k.encode(), m.encode(), hashlib.sha256).hexdigest()


# ---- submit rules
def test_submit_counts_risk_and_lists_steps_that_need_their_own_approval(app, owner, world):
    dev, _ = mkdev(app, world, owner)
    r = submit(app, world, dev, GOOD)
    assert r["status"] == "pending" and r["needs"] == ["s3"]
    r2 = submit(app, world, dev, plan(S(1, "copy_file", src=W + "/a", dst=W + "/b", overwrite=True)))
    assert r2["needs"] == ["s1"]


@pytest.mark.parametrize("p,why", [
    (plan(S(1, "read_file", path="/etc/passwd")), "outside_scope:s1"),
    (plan(S(1, "read_file", path=W + "/a"), S(2, "copy_file", src=W + "/a", dst="/etc/x")), "outside_scope:s2"),
    (plan(S(1, "format_disk", path=W)), "unknown_kind:s1"),
    (plan(S(1, "read_file", path=W + "/../x")), "bad_params:s1"),
    (plan({"id": "s1", "kind": "read_file", "params": {"path": W + "/a"}}, {"id": "s1", "kind": "list_dir", "params": {"path": W}}), "bad_params:s1"),
    (plan({"id": "S 1", "kind": "read_file", "params": {"path": W}}), "bad_params:?"),
    (plan(S(1, "write_file", path=W + "/{{s0.out}}/x", content="c")), "bad_params:s1"),
])
def test_one_bad_step_blocks_the_whole_plan(app, owner, world, p, why):
    dev, _ = mkdev(app, world, owner)
    r = submit(app, world, dev, p)
    assert r["status"] == "blocked" and r["reason"] == why, r


def test_verify_file_steps_are_scoped_like_reads(app, owner, world):
    dev, _ = mkdev(app, world, owner)
    assert submit(app, world, dev, plan(S(1, "verify_file", type="file_exists", path=W + "/a")))["status"] == "pending"
    assert submit(app, world, dev, plan(S(1, "verify_file", type="file_exists", path="/etc/shadow")))["reason"] == "outside_scope:s1"


def test_denied_kind_empty_steps_no_policy_and_foreign_device(app, owner, world, second_user):
    dev, _ = device(app, world)
    assert submit(app, world, dev, GOOD)["reason"] == "outside_scope:s1"            # a fresh device has an empty policy: nothing is allowed
    as_admin(owner)
    owner.execute("DELETE FROM pc_policies WHERE device_id = %s", (dev,))
    assert submit(app, world, dev, GOOD)["reason"] == "no_policy"
    policy(app, world, dev, modes={"create_xlsx": "deny"})
    assert submit(app, world, dev, GOOD)["reason"] == "denied_by_policy:s2"
    for bad in ({}, {"steps": []}, {"steps": "x"}, plan(*[S(i, "list_dir", path=W) for i in range(1, 22)])):
        assert submit(app, world, dev, bad)["reason"] == "bad_input"
    assert submit(app, world, dev, GOOD, goal="")["reason"] == "bad_input"
    assert submit(app, world, dev, GOOD, user=second_user)["reason"] == "not_found"


def test_emergency_stop_blocks_submit_and_stops_open_plans(app, owner, world):
    from test_pc_control import stop as emergency
    dev, _ = mkdev(app, world, owner)
    p1 = submit(app, world, dev, plan(S(1, "read_file", path=W + "/a")))["id"]
    p2 = submit(app, world, dev, plan(S(1, "read_file", path=W + "/b")))["id"]
    decide(app, world, p2)
    emergency(owner, world)
    assert submit(app, world, dev, GOOD)["reason"] == "emergency_stop"
    assert decide(app, world, p1) == "emergency_stop"
    assert pull(app, world, dev) is None
    as_admin(owner)
    assert {r[0] for r in owner.execute("SELECT status FROM pc_plans WHERE id IN (%s,%s)", (p1, p2)).fetchall()} == {"stopped"}


# ---- approval flow + signed hand-over
def test_full_flow_signatures_verify_with_the_device_secret(app, owner, world):
    dev, tok = mkdev(app, world, owner)
    r = submit(app, world, dev, GOOD)
    pid = r["id"]
    assert pull(app, world, dev) is None                                    # nothing approved yet
    assert decide(app, world, pid) == "ok" and decide(app, world, pid) == "not_pending"
    assert step_ok(app, world, pid, "s3") == "mobile_required"                  # trash_file is high risk: the website cannot approve it
    assert step_ok(app, world, pid, "s1") == "not_needed" and step_ok(app, world, pid, "zz") == "not_needed"
    ph = with_pin(app, world)
    assert m_step(app, world, ph, pid, "s3") == "step_up_required"             # PIN window not open yet
    step_up(app, world, ph)
    assert m_step(app, world, ph, pid, "s3") == "ok"
    got = pull(app, world, dev)
    assert got["id"] == pid and got["device_id"] == dev and got["needs"] == ["s3"]
    key = h(tok)                                                            # sha256(token): what the agent can compute
    exp = got["exp"]
    assert abs(exp - time.time() - 300) < 30
    want = hmac.new(key.encode(), f"canai-plan|{dev}|{pid}|{hashlib.sha256(got['plan_text'].encode()).hexdigest()}|{exp}".encode(), hashlib.sha256).hexdigest()
    assert got["plan_sig"] == want
    assert set(got["step_sigs"]) == {"s3"}                                  # only the separately approved step is signed
    stext = got["step_texts"]["s3"]
    want3 = hmac.new(key.encode(), f"canai-step|{dev}|{pid}|s3|{hashlib.sha256(stext.encode()).hexdigest()}|{exp}".encode(), hashlib.sha256).hexdigest()
    assert got["step_sigs"]["s3"] == want3
    assert json.loads(stext)["kind"] == "trash_file" and json.loads(got["plan_text"])["steps"][2] == json.loads(stext)
    assert pull(app, world, dev) is None                                    # handed out once
    assert report(app, world, dev, pid, "done", "3/3") == "ok" and report(app, world, dev, pid) == "not_running"
    as_admin(owner)
    assert owner.execute("SELECT status, result FROM pc_plans WHERE id=%s", (pid,)).fetchone() == ("done", "3/3")


def test_a_different_devices_secret_cannot_verify(app, owner, world):
    dev, tok = mkdev(app, world, owner)
    pid = submit(app, world, dev, plan(S(1, "read_file", path=W + "/a")))["id"]
    decide(app, world, pid)
    got = pull(app, world, dev)
    other = hmac.new(h("another token").encode(), f"canai-plan|{dev}|{pid}|{hashlib.sha256(got['plan_text'].encode()).hexdigest()}|{got['exp']}".encode(), hashlib.sha256).hexdigest()
    assert got["plan_sig"] != other


def test_reject_expire_and_ownership(app, owner, world, second_user):
    dev, _ = mkdev(app, world, owner)
    pid = submit(app, world, dev, GOOD)["id"]
    assert decide(app, world, pid, user=second_user) == "not_found"
    assert step_ok(app, world, pid, "s3", user=second_user) == "not_found"
    assert decide(app, world, pid, "maybe") == "bad_input"
    assert decide(app, world, pid, "reject") == "ok"
    assert pull(app, world, dev) is None and step_ok(app, world, pid, "s3") == "not_open"
    pid2 = submit(app, world, dev, GOOD)["id"]
    as_admin(owner)
    owner.execute("UPDATE pc_plans SET expires_at = now() - interval '1 second' WHERE id = %s", (pid2,))
    assert decide(app, world, pid2) == "expired"


def test_approved_plan_expires_before_pull(app, owner, world):
    dev, _ = mkdev(app, world, owner)
    pid = submit(app, world, dev, plan(S(1, "read_file", path=W + "/a")))["id"]
    decide(app, world, pid)
    as_admin(owner)
    owner.execute("UPDATE pc_plans SET expires_at = now() - interval '1 second' WHERE id = %s", (pid,))
    assert pull(app, world, dev) is None
    assert owner.execute("SELECT status FROM pc_plans WHERE id=%s", (pid,)).fetchone()[0] == "expired"


def test_plans_are_immutable_and_undeletable_and_secrets_unreadable(app, owner, world):
    dev, _ = mkdev(app, world, owner)
    pid = submit(app, world, dev, GOOD)["id"]
    as_admin(owner)
    import psycopg
    for sql in ("UPDATE pc_plans SET plan_text = '{}' WHERE id = %s", "UPDATE pc_plans SET status = 'done' WHERE id = %s", "DELETE FROM pc_plans WHERE id = %s"):
        with pytest.raises(psycopg.errors.Error):
            owner.execute(sql, (pid,))
        owner.rollback()
        as_admin(owner)
    ctx(app, world)
    for sql in ("UPDATE pc_plans SET status='approved'", "DELETE FROM pc_plans", "INSERT INTO pc_plan_step_approvals (plan_id,tenant_id,step_id,approved_by) VALUES ('x','y','z','w')"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
        app.rollback()
        ctx(app, world)
    assert app.execute("SELECT count(*) FROM pc_plans").fetchone()[0] == 1


def test_tenant_isolation_and_wrong_device_pull(app, owner, world):
    dev, _ = mkdev(app, world, owner)
    pid = submit(app, world, dev, plan(S(1, "read_file", path=W + "/a")))["id"]
    decide(app, world, pid)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM pc_plans").fetchone()[0] == 0
    assert pull(app, world, dev, who="b") is None                            # another tenant cannot pull it
    assert report(app, world, dev, pid, who="b") == "not_running"
    assert pull(app, world, dev) is not None


def test_report_validates_state(app, owner, world):
    dev, _ = mkdev(app, world, owner)
    assert report(app, world, dev, "x" * 26, "exploded") == "bad_input"


def test_overwrite_only_step_can_be_approved_on_the_web_and_is_signed(app, owner, world):
    dev, tok = mkdev(app, world, owner)
    pid = submit(app, world, dev, plan(S(1, "copy_file", src=W + "/a", dst=W + "/b", overwrite=True)))["id"]
    decide(app, world, pid)
    assert step_ok(app, world, pid, "s1") == "ok"
    assert set(pull(app, world, dev)["step_sigs"]) == {"s1"}


def test_a_high_risk_step_approved_by_any_other_path_is_never_signed(app, owner, world):
    dev, tok = mkdev(app, world, owner)
    pid = submit(app, world, dev, plan(S(1, "trash_file", path=W + "/old")))["id"]
    decide(app, world, pid)
    as_admin(owner)
    owner.execute("INSERT INTO pc_plan_step_approvals (plan_id, tenant_id, step_id, approved_by, via) VALUES (%s,%s,'s1',%s,'web')", (pid, world["a"]["tenant"], world["a"]["user"]))
    assert pull(app, world, dev)["step_sigs"] == {}                          # defence in depth, mirrors pc_action_next


def test_phone_approval_is_owner_only_and_tenant_scoped(app, owner, world, second_user):
    dev, _ = mkdev(app, world, owner)
    pid = submit(app, world, dev, plan(S(1, "trash_file", path=W + "/old")))["id"]
    ph = with_pin(app, world)
    step_up(app, world, ph)
    assert m_step(app, world, ph, pid, "zz") == "not_needed"
    decide(app, world, pid, "reject")
    assert m_step(app, world, ph, pid, "s1") == "not_open"


def test_a_plan_with_needs_when_and_make_report_is_accepted_and_every_step_is_still_checked(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    p = plan(S(1, "list_dir", path=W),
             {"id": "s2", "kind": "make_report", "params": {"src": W + "/s.csv", "dst": W + "/r.xlsx"}, "needs": ["s1"], "when": {"step": "s1", "contains": "s.csv"}})
    r = submit(app, world, dev, p)
    assert r["status"] == "pending", r
    p["steps"][1]["params"]["dst"] = "/etc/r.xlsx"
    r = submit(app, world, dev, p)
    assert r["status"] == "blocked" and "outside_scope" in r["reason"]
