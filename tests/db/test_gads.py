import itertools
import json
from decimal import Decimal

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx

_n = itertools.count(1)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM gads_accounts")
    owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
    owner.execute("DELETE FROM emergency_events")
    owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")


def cust():
    return str(1000000000 + next(_n))


def acct(app, world, who="a", name="আমার গুগল", customer=None, login=None, cur="BDT", ct="CT1", ver=1):
    ctx(app, world, who)
    customer = customer or cust()
    return app.execute("SELECT gads_account_save(%s,%s,%s,%s,%s,%s,%s,%s)", (ulid(), world[who]["tenant"], name, customer, login, cur, ct, ver)).fetchone()[0], customer


def store(admin, world, a, camps, ins, who="a"):
    as_admin(admin)
    return admin.execute("SELECT gads_sync_store(%s,%s,%s::jsonb,%s::jsonb)", (a, world[who]["tenant"], json.dumps(camps), json.dumps(ins))).fetchone()[0]


def test_connect_and_reconnect_keep_one_row(app, world, owner):
    a, c = acct(app, world, login="1234567890")
    assert len(a) == 26
    b, _ = acct(app, world, name="নতুন", customer=c, ct="CT2", ver=2)
    assert b == a
    as_admin(owner)
    assert owner.execute("SELECT count(*), max(name), max(credentials_ct), max(key_version), max(login_customer_id) FROM gads_accounts").fetchone() == (1, "নতুন", "CT2", 2, None)


def test_bad_input_and_limit(app, world):
    for kw in ({"name": "  "}, {"customer": "12345"}, {"customer": "123-456-7890"}, {"login": "abc"}, {"cur": "bdt"}, {"ct": " "}):
        with pytest.raises(psycopg.errors.InvalidParameterValue):
            acct(app, world, **kw)
        app.rollback()
    for _ in range(5):
        acct(app, world)
    assert acct(app, world)[0] == "limit"


def test_the_app_role_cannot_read_the_sealed_token(app, world):
    acct(app, world)
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM gads_accounts").fetchone()[0] == 1
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT credentials_ct FROM gads_accounts")
    app.rollback()
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO gads_accounts (id,tenant_id,name,customer_id,currency,credentials_ct,key_version) VALUES (%s,%s,'x','1234567890','BDT','c',1)", (ulid(), world["a"]["tenant"]))
    app.rollback()


def test_accounts_are_private_and_workers_admin_only(app, world):
    a, _ = acct(app, world, "a")
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM gads_accounts").fetchone()[0] == 0
    assert app.execute("SELECT gads_account_delete(%s,%s)", (a, world["b"]["tenant"])).fetchone()[0] == "not_found"
    ctx(app, world, "a")
    for sql, args in (("SELECT * FROM gads_sync_due(5,60)", ()), ("SELECT gads_sync_store(%s,%s,'[]','[]')", (a, world["a"]["tenant"])), ("SELECT gads_sync_fail(%s,%s,'x',false)", (a, world["a"]["tenant"]))):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        app.rollback()


def test_sync_due_once_per_interval_and_skips_invalid(app, world, admin):
    a, c = acct(app, world, login="1234567890")
    as_admin(admin)
    rows = admin.execute("SELECT account_id, customer_id, login_customer_id, credentials_ct, key_version FROM gads_sync_due(5,60)").fetchall()
    assert rows == [(a, c, "1234567890", "CT1", 1)]
    assert admin.execute("SELECT count(*) FROM gads_sync_due(5,60)").fetchone()[0] == 0
    assert admin.execute("SELECT gads_sync_fail(%s,%s,'টোকেন নেয়নি',true)", (a, world["a"]["tenant"])).fetchone()[0] == "ok"
    assert admin.execute("SELECT status, sync_error FROM gads_accounts").fetchone() == ("invalid", "টোকেন নেয়নি")
    acct(app, world, customer=c, ct="NEW", ver=2)
    as_admin(admin)
    assert admin.execute("SELECT status, sync_error FROM gads_accounts").fetchone() == ("active", None)


def test_store_upserts_and_skips_malformed_rows(app, world, admin, owner):
    a, _ = acct(app, world)
    camps = [{"id": "12345678901", "name": "ঈদ", "channel": "SEARCH", "status": "ENABLED", "daily_budget": "500.00"}, {"id": "bad", "name": "x", "status": "ENABLED"}, {"id": "765", "name": "দ্বিতীয়", "status": "PAUSED", "daily_budget": "-5"}]
    ins = [{"campaign": "12345678901", "day": "2026-11-01", "cost": "12.34", "impressions": "1000", "clicks": "20", "conversions": "3.5"},
           {"campaign": "12345678901", "day": "2026-11-02", "cost": "1e3", "impressions": "1", "clicks": "1", "conversions": "1"},
           {"campaign": "12345678901", "day": "2026-13-45x", "cost": "1", "impressions": "1", "clicks": "1"},
           {"campaign": "zz", "day": "2026-11-01", "cost": "1", "impressions": "1", "clicks": "1"},
           {"campaign": "765", "day": "2026-11-01", "cost": "0", "impressions": "", "clicks": "", "conversions": ""}]
    assert store(admin, world, a, camps, ins) == "ok"
    as_admin(owner)
    assert owner.execute("SELECT external_id, name, channel, daily_budget FROM gads_campaigns ORDER BY external_id").fetchall() == [("12345678901", "ঈদ", "SEARCH", 500), ("765", "দ্বিতীয়", None, None)]
    assert owner.execute("SELECT count(*) FROM gads_campaigns").fetchone()[0] == 2
    assert owner.execute("SELECT campaign_ext, day::text, cost, impressions, clicks, conversions FROM gads_insights_daily ORDER BY campaign_ext").fetchall() == [("12345678901", "2026-11-01", Decimal("12.34"), 1000, 20, Decimal("3.50")), ("765", "2026-11-01", 0, 0, 0, 0)]
    camps[0]["name"] = "ঈদ নতুন"
    ins[0]["cost"] = "20"
    assert store(admin, world, a, camps[:1], ins[:1]) == "ok"
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM gads_campaigns").fetchone()[0] == 2
    assert owner.execute("SELECT name FROM gads_campaigns WHERE external_id='12345678901'").fetchone()[0] == "ঈদ নতুন"
    assert owner.execute("SELECT cost FROM gads_insights_daily WHERE campaign_ext='12345678901' AND day='2026-11-01'").fetchone()[0] == 20


def test_store_checks_account_and_shape(app, world, admin):
    a, _ = acct(app, world)
    assert store(admin, world, ulid(), [], []) == "not_found"
    assert store(admin, world, a, [], [], who="b") == "not_found"
    as_admin(admin)
    for c, i in (("{}", "[]"), ("[]", "null"), (json.dumps([{}] * 501), "[]")):
        assert admin.execute("SELECT gads_sync_store(%s,%s,%s::jsonb,%s::jsonb)", (a, world["a"]["tenant"], c, i)).fetchone()[0] == "bad_input"


def test_disconnect_forgets_everything(app, world, admin, owner):
    a, _ = acct(app, world)
    store(admin, world, a, [{"id": "1234567", "name": "x", "status": "ENABLED"}], [{"campaign": "1234567", "day": "2026-11-01", "cost": "1", "impressions": "1", "clicks": "1"}])
    ctx(app, world, "a")
    assert app.execute("SELECT gads_account_delete(%s,%s)", (a, world["a"]["tenant"])).fetchone()[0] == "ok"
    as_admin(owner)
    assert owner.execute("SELECT (SELECT count(*) FROM gads_accounts)+(SELECT count(*) FROM gads_campaigns)+(SELECT count(*) FROM gads_insights_daily)").fetchone()[0] == 0


def test_emergency_stop_pauses_sync_until_resumed(app, world, admin, owner):
    acct(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user) VALUES (%s,'stop',%s)", (t, u))
    as_admin(admin)
    assert admin.execute("SELECT count(*) FROM gads_sync_due(5,60)").fetchone()[0] == 0
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user) VALUES (%s,'resume',%s)", (t, u))
    as_admin(admin)
    assert admin.execute("SELECT count(*) FROM gads_sync_due(5,60)").fetchone()[0] == 1
