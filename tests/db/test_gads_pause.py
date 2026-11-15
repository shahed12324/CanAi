import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_gads import acct, clean as _clean, store  # noqa: F401  (autouse cleanup fixture)


@pytest.fixture(autouse=True)
def clean_pause(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM gads_pause_requests")


def active(admin, world, a, ext="900000001", status="ENABLED", who="a"):
    assert store(admin, world, a, [{"id": ext, "name": "ক্যাম্পেইন", "channel": "SEARCH", "status": status, "daily_budget": None}], []) == "ok"
    return ext


def request(app, world, a, ext, who="a", reason="খরচ আছে ফল নেই"):
    ctx(app, world, who)
    return app.execute("SELECT gads_pause_request(%s,%s,%s,%s,%s,%s)", (ulid(), world[who]["tenant"], world[who]["user"], a, ext, reason)).fetchone()[0]


def claim(admin, n=5):
    as_admin(admin)
    return admin.execute("SELECT req_id, tenant_id, account_id, customer_id, login_customer_id, campaign_ext, credentials_ct FROM gads_pause_claim(%s)", (n,)).fetchall()


def done(admin, world, rid, ok, err=None, who="a"):
    as_admin(admin)
    return admin.execute("SELECT gads_pause_done(%s,%s,%s,%s)", (rid, world[who]["tenant"], ok, err)).fetchone()[0]


def camp_status(owner, a, ext):
    as_admin(owner)
    return owner.execute("SELECT status FROM gads_campaigns WHERE account_id=%s AND external_id=%s", (a, ext)).fetchone()[0]


def test_only_active_campaigns_can_be_paused(app, world, admin):
    a, _ = acct(app, world)
    e = active(admin, world, a)
    assert request(app, world, a, "900000002") == "not_active"
    active(admin, world, a, "900000003", "PAUSED")
    assert request(app, world, a, "900000003") == "not_active"
    assert request(app, world, a, e) == "ok"
    assert request(app, world, a, e) == "exists"


def test_bad_inputs_and_other_workspaces(app, world, admin):
    a, _ = acct(app, world)
    e = active(admin, world, a)
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        request(app, world, a, "abc")
    app.rollback()
    ctx(app, world, "b")
    assert app.execute("SELECT gads_pause_request(%s,%s,%s,%s,%s,%s)", (ulid(), world["b"]["tenant"], world["b"]["user"], a, e, "")).fetchone()[0] == "account"
    ctx(app, world)
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT gads_pause_request(%s,%s,NULL,%s,%s,%s)", (ulid(), world["a"]["tenant"], a, e, ""))
    app.rollback()


def test_worker_functions_are_not_callable_by_the_app_role(app, world, admin):
    a, _ = acct(app, world)
    ctx(app, world)
    for sql, args in (("SELECT * FROM gads_pause_claim(%s)", (1,)), ("SELECT gads_pause_done(%s,%s,true,NULL)", (ulid(), world["a"]["tenant"]))):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        app.rollback()
        ctx(app, world)


def test_claim_run_and_success_marks_campaign_paused(app, world, admin, owner):
    a, _ = acct(app, world, ct="CTX")
    e = active(admin, world, a)
    assert request(app, world, a, e) == "ok"
    rows = claim(admin)
    assert len(rows) == 1 and rows[0][5] == e and rows[0][6] == "CTX"
    assert claim(admin) == []
    assert request(app, world, a, e) == "exists"
    assert done(admin, world, rows[0][0], True) == "ok"
    assert camp_status(owner, a, e) == "PAUSED"
    assert done(admin, world, rows[0][0], True) == "not_found"
    assert request(app, world, a, e) == "not_active"


def test_failure_keeps_campaign_status_and_allows_asking_again(app, world, admin, owner):
    a, _ = acct(app, world)
    e = active(admin, world, a)
    request(app, world, a, e)
    rid = claim(admin)[0][0]
    assert done(admin, world, rid, False, "Google: no") == "ok"
    assert camp_status(owner, a, e) == "ENABLED"
    assert request(app, world, a, e) == "ok"


def test_stale_running_request_becomes_failed(app, world, admin, owner):
    a, _ = acct(app, world)
    e = active(admin, world, a)
    request(app, world, a, e)
    claim(admin)
    as_admin(owner)
    owner.execute("UPDATE gads_pause_requests SET started_at = now() - interval '20 minutes'")
    assert claim(admin) == []
    as_admin(owner)
    assert owner.execute("SELECT status FROM gads_pause_requests").fetchone()[0] == "failed"
    assert request(app, world, a, e) == "ok"


def test_invalid_account_is_not_claimed_and_tenants_cannot_see_each_others_requests(app, world, admin, owner):
    a, _ = acct(app, world)
    e = active(admin, world, a)
    request(app, world, a, e)
    as_admin(owner)
    owner.execute("UPDATE gads_accounts SET status='invalid'")
    assert claim(admin) == []
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM gads_pause_requests").fetchone()[0] == 0
    ctx(app, world)
    assert app.execute("SELECT count(*) FROM gads_pause_requests").fetchone()[0] == 1
