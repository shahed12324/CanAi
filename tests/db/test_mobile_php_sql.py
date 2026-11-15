"""The SQL strings in PgMobileRepo.php / PgMobileAdminRepo.php, executed for real with the parameter shapes PHP passes."""
import pytest

from conftest import as_admin, set_tenant, ulid
from test_lead_php_sql import sql_of
from test_outreach import account, campaign, message

REPO = "app/Mobile/PgMobileRepo.php"
ADM = "app/Mobile/PgMobileAdminRepo.php"
HASH = "$2y$10$" + "a" * 53


def q(marker):
    return sql_of(REPO, marker)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
    for t in ("emergency_events", "inbox_notifications", "mobile_devices", "outreach_messages", "outreach_campaigns", "email_accounts"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")


def register(app, w, who="a", token=11, push="ExponentPushToken[abcdefghij]"):
    t, u = w[who]["tenant"], w[who]["user"]
    set_tenant(app, t)
    r = app.execute(q("insert into mobile_devices"), (ulid(), t, u, token, "Pixel", "android", push, "1.0.0")).fetchone()
    return t, u, r[0].strip()


def test_register_is_an_upsert_per_token_and_revives_a_revoked_device(app, world):
    t, u, d1 = register(app, world)
    d2 = app.execute(q("insert into mobile_devices"), (ulid(), t, u, 11, "Pixel 2", "android", None, "1.1.0")).fetchone()[0].strip()
    assert d1 == d2
    row = app.execute(q("from mobile_devices where tenant_id"), (t, u, 11)).fetchone()
    assert row[0].strip() == d1 and row[1] is None and row[3] is None
    app.execute(q("set revoked_at = now(), push_token = null"), (d1,))
    assert app.execute(q("from mobile_devices where tenant_id"), (t, u, 11)).fetchone() is None
    assert app.execute(q("insert into mobile_devices"), (ulid(), t, u, 11, "Pixel", "android", None, "1.1.0")).fetchone()[0].strip() == d1


def test_pin_step_up_and_decision_statements(app, world):
    t, u, d = register(app, world)
    app.execute(q("mobile_set_pin"), (d, u, HASH))
    assert app.execute(q("from mobile_devices where tenant_id"), (t, u, 11)).fetchone()[1] == HASH
    assert app.execute(q("mobile_stepup_active"), (d, u)).fetchone()[0] is False
    assert app.execute(q("mobile_pin_attempt"), (d, u, "true")).fetchone()[0] == "ok"
    assert app.execute(q("mobile_stepup_active"), (d, u)).fetchone()[0] is True
    m = message(app, world, campaign(app, world, account(app, world)))
    rows = app.execute(q("from mobile_approvals"), (10,)).fetchall()
    assert [r[1].strip() for r in rows] == [m]
    assert app.execute(q("mobile_decide"), (d, u, "outreach_message", m, "approve")).fetchone()[0] == "approved"
    assert app.execute(q("from mobile_approvals"), (10,)).fetchall() == []


def test_emergency_statements(app, world):
    t, u, d = register(app, world)
    app.execute(q("mobile_set_pin"), (d, u, HASH))
    assert app.execute(q("from emergency_events where tenant_id"), (t,)).fetchone() is None
    assert app.execute(q("mobile_emergency"), (d, u, "true", "x")).fetchone()[0] == "stopped"
    row = app.execute(q("from emergency_events where tenant_id"), (t,)).fetchone()
    assert row[0] == "stop" and row[2] == "x"
    app.execute(q("mobile_pin_attempt"), (d, u, "true"))
    assert app.execute(q("mobile_emergency"), (d, u, "false", "")).fetchone()[0] == "resumed"
    assert app.execute(q("from emergency_events where tenant_id"), (t,)).fetchone()[0] == "resume"


def test_notifications_roundtrip_and_mark_read(app, world):
    t, u, d = register(app, world)
    ids = []
    for i in range(3):
        n = ulid()
        ids.append(n)
        app.execute(q("insert into inbox_notifications"), (n, t, u, "x", f"title {i}", "body", None, None))
    other = world["b"]["user"]
    assert app.execute(q("count(*) as n from inbox_notifications"), (u,)).fetchone()[0] == 3
    assert len(app.execute(q("from inbox_notifications\n            where user_id"), (u, "false", 50)).fetchall()) == 3
    assert app.execute(q("id = any(string_to_array"), (u, ",".join(ids[:2]))).rowcount == 2
    assert app.execute(q("count(*) as n from inbox_notifications"), (u,)).fetchone()[0] == 1
    assert len(app.execute(q("from inbox_notifications\n            where user_id"), (u, "true", 50)).fetchall()) == 1
    assert app.execute(q("id = any(string_to_array"), (other, ids[2])).rowcount == 0         # someone else's id changes nothing
    assert app.execute(q("set read_at = now() where user_id = ? and read_at is null"), (u,)).rowcount == 1       # mark all


def test_device_users_and_logout(app, world):
    t, u, d = register(app, world)
    assert [r[0].strip() for r in app.execute(q("select distinct user_id"), (t,)).fetchall()] == [u]
    assert app.execute(q("mobile_revoke_token"), (u, 11)).fetchone()[0] == 1
    assert app.execute(q("select distinct user_id"), (t,)).fetchall() == []


def test_admin_statements_see_all_workspaces(admin, world):
    for who in ("a", "b"):
        w = world[who]
        as_admin(admin)
        admin.execute("INSERT INTO mobile_devices (id,tenant_id,user_id,token_id,name,platform,push_token) VALUES (%s,%s,%s,5,'p','ios','ExponentPushToken[zzzzzzzzzz]')", (ulid(), w["tenant"], w["user"]))
        acc = account(admin, world, who, f"{who}@agency.test")
        message(admin, world, campaign(admin, world, acc, who), f"{who}@x.test", who)
    rows = admin.execute(sql_of(ADM, "group by tenant_id"), (10,)).fetchall()
    assert {r[0].strip() for r in rows} == {world["a"]["tenant"], world["b"]["tenant"]} and all(r[1] == 1 for r in rows)
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert [r[0].strip() for r in admin.execute(sql_of(ADM, "select distinct user_id"), (t,)).fetchall()] == [u]
    assert admin.execute(sql_of(ADM, "kind = ? and read_at is null"), (t, u, "approvals_pending")).fetchone() is None
    n = ulid()
    admin.execute(sql_of(ADM, "insert into inbox_notifications"), (n, t, u, "approvals_pending", "t", "b", "approvals"))
    assert admin.execute(sql_of(ADM, "kind = ? and read_at is null"), (t, u, "approvals_pending")).fetchone() is not None
    rows = admin.execute(sql_of(ADM, "join mobile_devices d"), (10,)).fetchall()
    assert [(r[0].strip(), r[7]) for r in rows] == [(n, "ExponentPushToken[zzzzzzzzzz]")]
    admin.execute(sql_of(ADM, "update inbox_notifications set pushed_at"), (None, None, n))
    assert admin.execute(sql_of(ADM, "join mobile_devices d"), (10,)).fetchall() == []
    admin.execute(sql_of(ADM, "update mobile_devices set push_token = null"), ("ExponentPushToken[zzzzzzzzzz]",))
    assert admin.execute("SELECT count(*) FROM mobile_devices WHERE push_token IS NOT NULL").fetchone()[0] == 0


def test_dead_logins_are_retired_by_the_push_worker(admin, owner, world):
    # personal_access_tokens comes from Sanctum's own migration, so this test stands one in
    as_admin(owner)
    owner.execute("CREATE TABLE IF NOT EXISTS personal_access_tokens (id bigserial PRIMARY KEY, expires_at timestamptz)")
    owner.execute("GRANT SELECT ON personal_access_tokens TO jarvis_app_rw")
    owner.execute("INSERT INTO personal_access_tokens (id, expires_at) VALUES (1, NULL), (2, now() + interval '1 day'), (3, now() - interval '1 day')")
    ids = {}
    for tok in (1, 2, 3, 4):                      # 4 = deleted token
        ids[tok] = ulid()
        owner.execute("INSERT INTO mobile_devices (id,tenant_id,user_id,token_id,name,platform,push_token) VALUES (%s,%s,%s,%s,'p','ios','ExponentPushToken[zzzzzzzzzz]')",
                      (ids[tok], world["a"]["tenant"], world["a"]["user"], tok))
    as_admin(admin)
    assert admin.execute(sql_of(ADM, "personal_access_tokens")).rowcount == 2
    rows = dict(owner.execute("SELECT token_id, revoked_at IS NOT NULL FROM mobile_devices").fetchall())
    assert rows == {1: False, 2: False, 3: True, 4: True}
    assert owner.execute("SELECT count(*) FROM mobile_devices WHERE push_token IS NULL").fetchone()[0] == 2
    owner.execute("DROP TABLE personal_access_tokens")
