import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_outreach import account, campaign, message

HASH = "$2y$10$" + "a" * 53


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
    for t in ("outreach_suppressions", "emergency_events", "inbox_notifications", "mobile_devices", "outreach_messages", "outreach_campaigns", "email_accounts"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")


def device(conn, w, who="a", token=1, platform="android"):
    i = ulid()
    conn.execute("INSERT INTO mobile_devices (id,tenant_id,user_id,token_id,name,platform) VALUES (%s,%s,%s,%s,'Phone',%s)",
                 (i, w[who]["tenant"], w[who]["user"], token, platform))
    return i


def ctx(app, w, who="a"):
    set_tenant(app, w[who]["tenant"])
    return w[who]["user"]


def pin_ok(app, d, u):
    return app.execute("SELECT mobile_pin_attempt(%s,%s,true)", (d, u)).fetchone()[0]


def setup_msg(app, w, who="a", to="x@client.test"):
    return message(app, w, campaign(app, w, account(app, w, who, f"me-{ulid()}@agency.test"), who), to, who)


def test_device_columns_for_pin_are_not_writable_by_the_app(app, world):
    u = ctx(app, world)
    d = device(app, world)
    for col, val in (("pin_hash", HASH), ("stepup_until", "2099-01-01"), ("pin_locked_until", None)):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(f"UPDATE mobile_devices SET {col}=%s WHERE id=%s", (val, d))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO mobile_devices (id,tenant_id,user_id,token_id,name,platform,stepup_until) VALUES (%s,%s,%s,9,'x','ios',now()+interval '1 day')",
                    (ulid(), world["a"]["tenant"], u))
    app.execute("UPDATE mobile_devices SET push_token='ExponentPushToken[abc]' WHERE id=%s", (d,))


def test_platform_and_token_are_unique_and_checked(app, world):
    ctx(app, world)
    device(app, world)
    with pytest.raises(psycopg.errors.UniqueViolation):
        device(app, world)
    with pytest.raises(psycopg.errors.CheckViolation):
        device(app, world, token=2, platform="windows")


def test_devices_are_isolated_between_workspaces(app, world):
    ctx(app, world, "b")
    db = device(app, world, "b")
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM mobile_devices").fetchone()[0] == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):                # tenant A cannot drive B's device
        app.execute("SELECT mobile_pin_attempt(%s,%s,true)", (db, world["b"]["user"]))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):                # nor impersonate its owner on its own device
        d = device(app, world)
        app.execute("SELECT mobile_pin_attempt(%s,%s,true)", (d, world["b"]["user"]))


def test_pin_flow_wrong_then_right_and_lockout(app, world, owner):
    u = ctx(app, world)
    d = device(app, world)
    assert app.execute("SELECT mobile_pin_attempt(%s,%s,true)", (d, u)).fetchone()[0] == "no_pin"
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    assert app.execute("SELECT mobile_stepup_active(%s,%s)", (d, u)).fetchone()[0] is False
    for _ in range(4):
        assert app.execute("SELECT mobile_pin_attempt(%s,%s,false)", (d, u)).fetchone()[0] == "bad"
    assert app.execute("SELECT mobile_pin_attempt(%s,%s,false)", (d, u)).fetchone()[0] == "locked"
    # locked: even the right PIN is refused until the lock ends
    assert app.execute("SELECT mobile_pin_attempt(%s,%s,true)", (d, u)).fetchone()[0] == "locked"
    assert app.execute("SELECT mobile_stepup_active(%s,%s)", (d, u)).fetchone()[0] is False
    as_admin(owner)
    owner.execute("UPDATE mobile_devices SET pin_locked_until = now() - interval '1 second' WHERE id=%s", (d,))
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    assert pin_ok(app, d, u) == "ok"
    assert app.execute("SELECT mobile_stepup_active(%s,%s)", (d, u)).fetchone()[0] is True


def test_wrong_pin_closes_an_open_step_up(app, world):
    u = ctx(app, world)
    d = device(app, world)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    assert pin_ok(app, d, u) == "ok"
    app.execute("SELECT mobile_pin_attempt(%s,%s,false)", (d, u))
    assert app.execute("SELECT mobile_stepup_active(%s,%s)", (d, u)).fetchone()[0] is False


def test_changing_the_pin_clears_step_up_and_rejects_junk(app, world):
    u = ctx(app, world)
    d = device(app, world)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    pin_ok(app, d, u)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    assert app.execute("SELECT mobile_stepup_active(%s,%s)", (d, u)).fetchone()[0] is False
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("SELECT mobile_set_pin(%s,%s,'123456')", (d, u))


def test_step_up_expires(app, world, owner):
    u = ctx(app, world)
    d = device(app, world)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    pin_ok(app, d, u)
    as_admin(owner)
    owner.execute("UPDATE mobile_devices SET stepup_until = now() - interval '1 second' WHERE id=%s", (d,))
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    assert app.execute("SELECT mobile_stepup_active(%s,%s)", (d, u)).fetchone()[0] is False


def test_revoked_device_is_refused(app, world):
    u = ctx(app, world)
    d = device(app, world)
    app.execute("UPDATE mobile_devices SET revoked_at = now() WHERE id=%s", (d,))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT mobile_pin_attempt(%s,%s,true)", (d, u))


def test_approvals_list_shows_only_pending_in_this_workspace(app, world):
    ctx(app, world, "b")
    setup_msg(app, world, "b", "b@client.test")
    ctx(app, world, "a")
    m1 = setup_msg(app, world, "a", "p1@client.test")
    m2 = setup_msg(app, world, "a", "p2@client.test")
    app.execute("UPDATE outreach_messages SET status='rejected' WHERE id=%s", (m2,))
    rows = app.execute("SELECT kind, ref, title, summary FROM mobile_approvals()").fetchall()
    assert [(r[0], r[1].strip()) for r in rows] == [("outreach_message", m1)]
    assert "p1@client.test" in rows[0][3]


def test_approve_needs_step_up_then_works_once(app, world):
    u = ctx(app, world)
    d = device(app, world)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    m = setup_msg(app, world)
    q = "SELECT mobile_decide(%s,%s,'outreach_message',%s,%s)"
    assert app.execute(q, (d, u, m, "approve")).fetchone()[0] == "step_up_required"
    assert app.execute("SELECT status FROM outreach_messages WHERE id=%s", (m,)).fetchone()[0] == "pending_approval"
    pin_ok(app, d, u)
    assert app.execute(q, (d, u, m, "approve")).fetchone()[0] == "approved"
    row = app.execute("SELECT status, approved_by FROM outreach_messages WHERE id=%s", (m,)).fetchone()
    assert row[0] == "approved" and row[1].strip() == u
    assert app.execute(q, (d, u, m, "approve")).fetchone()[0] == "not_pending"


def test_reject_needs_no_step_up(app, world):
    u = ctx(app, world)
    d = device(app, world)
    m = setup_msg(app, world)
    assert app.execute("SELECT mobile_decide(%s,%s,'outreach_message',%s,'reject')", (d, u, m)).fetchone()[0] == "rejected"
    assert app.execute("SELECT mobile_decide(%s,%s,'outreach_message',%s,'reject')", (d, u, m)).fetchone()[0] == "not_pending"


def test_cannot_decide_another_workspaces_item(app, world):
    ctx(app, world, "b")
    mb = setup_msg(app, world, "b", "z@client.test")
    u = ctx(app, world, "a")
    d = device(app, world)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    pin_ok(app, d, u)
    for dec in ("approve", "reject"):
        assert app.execute("SELECT mobile_decide(%s,%s,'outreach_message',%s,%s)", (d, u, mb, dec)).fetchone()[0] == "not_pending"
    ctx(app, world, "b")
    assert app.execute("SELECT status FROM outreach_messages WHERE id=%s", (mb,)).fetchone()[0] == "pending_approval"


def test_unknown_kind_and_bad_decision(app, world):
    u = ctx(app, world)
    d = device(app, world)
    assert app.execute("SELECT mobile_decide(%s,%s,'invoice',%s,'reject')", (d, u, ulid())).fetchone()[0] == "unknown_kind"
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("SELECT mobile_decide(%s,%s,'outreach_message',%s,'maybe')", (d, u, ulid()))


def test_suppressed_recipient_cannot_be_approved(app, world):
    pytest.importorskip('psycopg')
    u = ctx(app, world)
    d = device(app, world)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    m = setup_msg(app, world, to="blocked@client.test")
    app.execute("INSERT INTO outreach_suppressions (tenant_id,email,reason) VALUES (%s,'blocked@client.test','manual')", (world["a"]["tenant"],))
    pin_ok(app, d, u)
    assert app.execute("SELECT mobile_decide(%s,%s,'outreach_message',%s,'approve')", (d, u, m)).fetchone()[0] == "suppressed"
    assert app.execute("SELECT status FROM outreach_messages WHERE id=%s", (m,)).fetchone()[0] == "pending_approval"


def test_emergency_stop_is_instant_and_blocks_approval(app, world):
    u = ctx(app, world)
    d = device(app, world)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    m = setup_msg(app, world)
    t = world["a"]["tenant"]
    assert app.execute("SELECT emergency_active(%s)", (t,)).fetchone()[0] is False
    assert app.execute("SELECT mobile_emergency(%s,%s,true,'test')", (d, u)).fetchone()[0] == "stopped"   # no PIN needed
    assert app.execute("SELECT emergency_active(%s)", (t,)).fetchone()[0] is True
    assert app.execute("SELECT mobile_emergency(%s,%s,true,'again')", (d, u)).fetchone()[0] == "unchanged"
    pin_ok(app, d, u)
    assert app.execute("SELECT mobile_decide(%s,%s,'outreach_message',%s,'approve')", (d, u, m)).fetchone()[0] == "emergency"
    assert app.execute("SELECT status FROM outreach_messages WHERE id=%s", (m,)).fetchone()[0] == "pending_approval"


def test_resume_needs_step_up(app, world, owner):
    u = ctx(app, world)
    d = device(app, world)
    app.execute("SELECT mobile_set_pin(%s,%s,%s)", (d, u, HASH))
    app.execute("SELECT mobile_emergency(%s,%s,true,'')", (d, u))
    assert app.execute("SELECT mobile_emergency(%s,%s,false,'')", (d, u)).fetchone()[0] == "step_up_required"
    assert app.execute("SELECT emergency_active(%s)", (world["a"]["tenant"],)).fetchone()[0] is True
    pin_ok(app, d, u)
    assert app.execute("SELECT mobile_emergency(%s,%s,false,'')", (d, u)).fetchone()[0] == "resumed"
    assert app.execute("SELECT emergency_active(%s)", (world["a"]["tenant"],)).fetchone()[0] is False
    assert app.execute("SELECT mobile_emergency(%s,%s,false,'')", (d, u)).fetchone()[0] == "unchanged"


def test_emergency_is_per_workspace(app, world):
    u = ctx(app, world)
    d = device(app, world)
    app.execute("SELECT mobile_emergency(%s,%s,true,'')", (d, u))
    ctx(app, world, "b")
    assert app.execute("SELECT emergency_active(%s)", (world["b"]["tenant"],)).fetchone()[0] is False


def test_emergency_events_cannot_be_edited_or_forged(app, world):
    u = ctx(app, world)
    d = device(app, world)
    app.execute("SELECT mobile_emergency(%s,%s,true,'')", (d, u))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE emergency_events SET action='resume'")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM emergency_events")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO emergency_events (tenant_id,action,by_user) VALUES (%s,'resume',%s)", (world["a"]["tenant"], u))


def test_notifications_are_private_to_the_workspace(app, world):
    ctx(app, world, "b")
    app.execute("INSERT INTO inbox_notifications (id,tenant_id,user_id,kind,title) VALUES (%s,%s,%s,'x','b only')", (ulid(), world["b"]["tenant"], world["b"]["user"]))
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM inbox_notifications").fetchone()[0] == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO inbox_notifications (id,tenant_id,user_id,kind,title) VALUES (%s,%s,%s,'x','forged')", (ulid(), world["b"]["tenant"], world["b"]["user"]))


def test_logging_out_revokes_the_phone_everywhere_and_drops_push(app, world, owner):
    u = world["a"]["user"]
    ids = []
    for who_t in ("a",):
        set_tenant(app, world[who_t]["tenant"])
        ids.append(device(app, world, token=77))
        app.execute("UPDATE mobile_devices SET push_token='ExponentPushToken[x]' WHERE id=%s", (ids[0],))
    other = device(app, world, token=78)
    assert app.execute("SELECT mobile_revoke_token(%s,77)", (u,)).fetchone()[0] == 1
    rows = dict(app.execute("SELECT id, revoked_at IS NOT NULL FROM mobile_devices").fetchall())
    assert rows[ids[0]] is True and rows[other] is False
    assert app.execute("SELECT push_token FROM mobile_devices WHERE id=%s", (ids[0],)).fetchone()[0] is None
    assert app.execute("SELECT mobile_revoke_token(%s,77)", (u,)).fetchone()[0] == 0
