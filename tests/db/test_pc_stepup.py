"""Phase 28g: high / critical computer actions must be approved on the phone, with a live PIN step-up."""
import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)
from test_mobile import HASH, device as phone, pin_ok
from test_pc_control import ROOTS, device, nxt, policy, req, status


@pytest.fixture(autouse=True)
def clean28g(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
    owner.execute("DELETE FROM emergency_events")
    owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")
    owner.execute("ALTER TABLE pc_actions DISABLE TRIGGER pc_actions_guard_trg")
    for t in ("pc_metrics", "pc_actions", "pc_policies", "pc_pairings", "pc_devices", "mobile_devices"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE pc_actions ENABLE TRIGGER pc_actions_guard_trg")


def web_decide(app, world, aid, how="approve", who="a"):
    ctx(app, world, who)
    return app.execute("SELECT pc_action_decide(%s,%s,%s,%s)", (world[who]["tenant"], world[who]["user"], aid, how)).fetchone()[0]


def m_decide(app, world, ph, aid, how="approve", who="a", user=None):
    ctx(app, world, who)
    return app.execute("SELECT mobile_pc_decide(%s,%s,%s,%s)", (ph, user or world[who]["user"], aid, how)).fetchone()[0]


def m_list(app, world, ph, who="a", user=None):
    ctx(app, world, who)
    return app.execute("SELECT id, kind, risk, device FROM mobile_pc_pending(%s,%s)", (ph, user or world[who]["user"])).fetchall()


def with_pin(app, world, who="a"):
    u = ctx(app, world, who)
    ph = phone(app, world, who)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (ph, u, HASH))
    return ph


def step_up(app, world, ph, who="a"):
    assert pin_ok(app, ph, ctx(app, world, who)) == "ok"


def setup(app, world, who="a", modes=None):
    dev, _ = device(app, world, who)
    policy(app, world, dev, who, modes=modes)
    return dev


def high(app, world, dev, who="a"):
    r = req(app, world, dev, "delete_file", {"path": "/home/me/work/old.txt"}, who)
    assert r["status"] == "pending" and r["risk"] == "high"
    return r["id"].strip()


def low(app, world, dev):
    r = req(app, world, dev, "read_file", {"path": "/home/me/work/a.txt"})
    assert r["status"] == "pending"
    return r["id"].strip()


def test_web_cannot_approve_a_high_risk_action_but_can_reject_it(app, owner, world):
    dev = setup(app, world)
    a = high(app, world, dev)
    assert web_decide(app, world, a) == "mobile_required"
    assert status(owner, a) == ("pending", None)
    assert web_decide(app, world, a, "reject") == "ok"
    assert status(owner, a)[0] == "rejected"


def test_critical_actions_also_need_the_phone(app, owner, world):
    dev = setup(app, world)
    r = req(app, world, dev, "power", {"action": "shutdown"})
    assert r["status"] == "pending" and r["risk"] == "critical"
    assert web_decide(app, world, r["id"].strip()) == "mobile_required"


def test_low_and_medium_actions_still_approve_on_the_web(app, owner, world):
    dev = setup(app, world)
    a = low(app, world, dev)
    assert web_decide(app, world, a) == "ok"
    as_admin(owner)
    assert owner.execute("SELECT decided_via FROM pc_actions WHERE id=%s", (a,)).fetchone()[0] == "web"
    r = req(app, world, dev, "write_file", {"path": "/home/me/work/n.txt", "content": "x"})
    assert web_decide(app, world, r["id"].strip()) == "ok"


def test_phone_approval_needs_a_live_pin_step_up(app, owner, world):
    dev = setup(app, world)
    ph = with_pin(app, world)
    a = high(app, world, dev)
    assert m_decide(app, world, ph, a) == "step_up_required"
    assert status(owner, a)[0] == "pending"
    step_up(app, world, ph)
    assert m_decide(app, world, ph, a) == "ok"
    assert status(owner, a)[0] == "approved"
    as_admin(owner)
    assert owner.execute("SELECT decided_via, decided_by FROM pc_actions WHERE id=%s", (a,)).fetchone() == ("mobile", world["a"]["user"])


def test_phone_reject_needs_no_step_up(app, owner, world):
    dev = setup(app, world)
    ph = with_pin(app, world)
    a = high(app, world, dev)
    assert m_decide(app, world, ph, a, "reject") == "ok"
    assert status(owner, a)[0] == "rejected"


def test_agent_gets_a_phone_approved_high_risk_action_only(app, owner, world):
    dev = setup(app, world)
    ph = with_pin(app, world)
    a = high(app, world, dev)
    step_up(app, world, ph)
    assert m_decide(app, world, ph, a) == "ok"
    got = nxt(app, world, dev)
    assert got["id"].strip() == a and got["risk"] == "high"
    assert nxt(app, world, dev) is None


def test_defence_in_depth_a_web_approved_high_risk_row_never_runs(app, owner, world):
    dev = setup(app, world)
    a = high(app, world, dev)
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):                      # the table itself refuses the shape
        owner.execute("UPDATE pc_actions SET status='approved', decided_by=%s, decided_via='web' WHERE id=%s", (world["a"]["user"], a))
    owner.rollback()
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE pc_actions SET status='approved', decided_by=%s WHERE id=%s", (world["a"]["user"], a))
    owner.rollback()


def test_agent_pull_skips_an_approved_high_risk_row_without_phone_proof(app, owner, world):
    dev = setup(app, world)
    a = high(app, world, dev)
    as_admin(owner)
    owner.execute("ALTER TABLE pc_actions DISABLE TRIGGER USER")
    owner.execute("ALTER TABLE pc_actions DROP CONSTRAINT pc_actions_high_via_phone")        # simulate a forged / legacy row
    owner.execute("UPDATE pc_actions SET status='approved', decided_by=%s, decided_via='web', expires_at=now()+interval '5 minutes' WHERE id=%s", (world["a"]["user"], a))
    owner.execute("ALTER TABLE pc_actions ENABLE TRIGGER USER")
    assert nxt(app, world, dev) is None
    owner.execute("ALTER TABLE pc_actions DISABLE TRIGGER USER")
    owner.execute("UPDATE pc_actions SET decided_via='mobile' WHERE id=%s", (a,))
    owner.execute("ALTER TABLE pc_actions ENABLE TRIGGER USER")
    assert nxt(app, world, dev)["id"].strip() == a
    owner.execute("ALTER TABLE pc_actions ADD CONSTRAINT pc_actions_high_via_phone CHECK (NOT (risk IN ('high', 'critical') AND status IN ('approved', 'running', 'done', 'failed') AND decided_via IS DISTINCT FROM 'mobile')) NOT VALID")


def test_decided_via_cannot_be_rewritten(app, owner, world):
    dev = setup(app, world)
    a = low(app, world, dev)
    assert web_decide(app, world, a) == "ok"
    as_admin(owner)
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        owner.execute("UPDATE pc_actions SET decided_via='mobile' WHERE id=%s", (a,))
    owner.rollback()


def test_phone_list_shows_only_my_pending_actions(app, owner, world):
    dev = setup(app, world)
    ph = with_pin(app, world)
    a = high(app, world, dev)
    b = low(app, world, dev)
    rows = m_list(app, world, ph)
    assert {r[0].strip() for r in rows} == {a, b}
    assert {r[2] for r in rows} == {"high", "low"} and {r[3] for r in rows} == {"Laptop"}
    assert m_decide(app, world, ph, b, "reject") == "ok"
    assert {r[0].strip() for r in m_list(app, world, ph)} == {a}


def test_phone_list_hides_expired_and_other_people_and_other_workspaces(app, owner, world, second_user):
    dev = setup(app, world)
    ph = with_pin(app, world)
    a = high(app, world, dev)
    as_admin(owner)
    owner.execute("UPDATE pc_actions SET expires_at = now() - interval '1 second' WHERE id=%s", (a,))
    assert m_list(app, world, ph) == []
    # a second workspace has its own computer, action and phone
    dev_b = setup(app, world, "b")
    ph_b = with_pin(app, world, "b")
    b = high(app, world, dev_b, "b")
    assert [r[0].strip() for r in m_list(app, world, ph_b, "b")] == [b]
    assert [r[0].strip() for r in m_list(app, world, ph)] == []


def test_phone_cannot_decide_someone_elses_or_another_workspaces_action(app, owner, world):
    dev_b = setup(app, world, "b")
    b = high(app, world, dev_b, "b")
    dev = setup(app, world)
    ph = with_pin(app, world)
    step_up(app, world, ph)
    assert m_decide(app, world, ph, b) == "not_found"                      # workspace b's action
    assert status(owner, b)[0] == "pending"
    a = high(app, world, dev)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):              # a phone only acts for its own user
        m_decide(app, world, ph, a, user=world["b"]["user"])
    app.rollback()


def test_phone_decision_edge_cases(app, owner, world):
    dev = setup(app, world)
    ph = with_pin(app, world)
    a = high(app, world, dev)
    step_up(app, world, ph)
    assert m_decide(app, world, ph, a, "maybe") == "bad_input"
    assert m_decide(app, world, ph, a, None) == "bad_input"
    assert m_decide(app, world, ph, ulid()) == "not_found"
    assert m_decide(app, world, ph, a) == "ok"
    assert m_decide(app, world, ph, a) == "not_pending"
    assert m_decide(app, world, ph, a, "reject") == "not_pending"


def test_phone_approval_of_an_expired_action_marks_it_expired(app, owner, world):
    dev = setup(app, world)
    ph = with_pin(app, world)
    a = high(app, world, dev)
    step_up(app, world, ph)
    as_admin(owner)
    owner.execute("UPDATE pc_actions SET expires_at = now() - interval '1 second' WHERE id=%s", (a,))
    assert m_decide(app, world, ph, a) == "expired"
    assert status(owner, a)[0] == "expired"


def test_emergency_stop_beats_a_phone_approval(app, owner, world):
    dev = setup(app, world)
    ph = with_pin(app, world)
    a = high(app, world, dev)
    step_up(app, world, ph)
    ctx(app, world)
    app.execute("SELECT mobile_emergency(%s,%s,true,'test')", (ph, world["a"]["user"]))
    assert m_decide(app, world, ph, a) == "emergency_stop"
    assert status(owner, a) == ("stopped", "emergency_stop")


def test_revoked_phone_or_computer_is_refused(app, owner, world):
    dev = setup(app, world)
    ph = with_pin(app, world)
    a = high(app, world, dev)
    step_up(app, world, ph)
    ctx(app, world)
    assert app.execute("SELECT pc_device_revoke(%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], dev)).fetchone()[0] == "ok"
    assert m_decide(app, world, ph, a) == "not_found"
    as_admin(owner)
    owner.execute("UPDATE mobile_devices SET revoked_at = now() WHERE id=%s", (ph,))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        m_list(app, world, ph)
    app.rollback()


def test_new_functions_are_not_callable_by_public(app, owner, world):
    as_admin(owner)
    for f in ("mobile_pc_pending(char, char)", "mobile_pc_decide(char, char, char, text)"):
        assert owner.execute("SELECT has_function_privilege('public', %s::regprocedure, 'EXECUTE'), has_function_privilege('jarvis_app_rw', %s::regprocedure, 'EXECUTE')", (f, f)).fetchone() == (False, True)


def test_phone_never_sees_or_decides_a_colleagues_computer_action(app, owner, world, second_user):
    dev2, _ = device(app, world, "a", user=second_user)
    assert policy(app, world, dev2, user=second_user) == "ok"
    r = req(app, world, dev2, "delete_file", {"path": "/home/me/work/x.txt"}, user=second_user)
    assert r["status"] == "pending"
    ph = with_pin(app, world)
    step_up(app, world, ph)
    assert m_list(app, world, ph) == []
    assert m_decide(app, world, ph, r["id"].strip()) == "not_found"
    assert status(owner, r["id"].strip())[0] == "pending"


def test_a_person_in_two_workspaces_cannot_cross_them_from_one_phone(app, owner, world):
    as_admin(owner)
    owner.execute("INSERT INTO tenant_users (id,tenant_id,user_id,joined_at) VALUES (%s,%s,%s,now())", (ulid(), world["b"]["tenant"], world["a"]["user"]))
    dev_b, _ = device(app, world, "b", user=world["a"]["user"])
    assert policy(app, world, dev_b, "b", user=world["a"]["user"]) == "ok"
    r = req(app, world, dev_b, "delete_file", {"path": "/home/me/work/x.txt"}, "b", user=world["a"]["user"])
    assert r["status"] == "pending"
    ph = with_pin(app, world)                                              # phone registered in workspace a
    step_up(app, world, ph)
    assert m_list(app, world, ph) == []
    assert m_decide(app, world, ph, r["id"].strip()) == "not_found"
    as_admin(owner)
    owner.execute("DELETE FROM tenant_users WHERE tenant_id=%s AND user_id=%s", (world["b"]["tenant"], world["a"]["user"]))


def test_the_exact_query_php_runs_for_the_phone_list(app, owner, world):
    dev = setup(app, world)
    ph = with_pin(app, world)
    a = high(app, world, dev)
    ctx(app, world)
    rows = app.execute("select id, kind, risk, device, params::text as params, created_at::text as created_at, expires_at::text as expires_at from mobile_pc_pending(%s, %s)", (ph, world["a"]["user"])).fetchall()
    assert len(rows) == 1 and rows[0][0].strip() == a and rows[0][1] == "delete_file" and '"/home/me/work/old.txt"' in rows[0][4]
