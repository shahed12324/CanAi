"""The SQL strings in AdminBilling.php and PgBillingRepo.php, executed for real with the parameter shapes PHP passes."""
import json

import pytest

from conftest import as_admin, set_tenant, ulid
from test_billing import coupon, order, package, plan, prices, settle  # noqa: F401  (prices is an autouse fixture)
from test_lead_php_sql import sql_of

ADMIN = "app/Billing/AdminBilling.php"
REPO = "app/Billing/PgBillingRepo.php"


def run_admin(admin, marker, params=()):
    as_admin(admin)
    return admin.execute(sql_of(ADMIN, marker), params)


def test_revenue_mrr_plans_churn_and_gateways(app, admin, world):
    t = world["a"]["tenant"]
    settle(app, order(app, t))
    settle(app, order(app, world["b"]["tenant"], gw="bkash"), gw="bkash")
    rows = run_admin(admin, "date_trunc('month', paid_at", (12,)).fetchall()
    assert rows and rows[0][1] == "BDT" and rows[0][2] >= 2 and rows[0][3] >= 230000 and rows[0][4] >= 30000
    mrr = run_admin(admin, "as mrr_minor").fetchone()
    assert mrr[0] >= 200000 and mrr[1] >= 2
    assert any(r[0] == "Pro" and r[1] == "active" and r[2] >= 2 for r in run_admin(admin, "from subscriptions s join plans p").fetchall())
    c = run_admin(admin, "as cancelled").fetchone()
    assert c[2] >= 2
    g = {r[0]: r for r in run_admin(admin, "count(*) filter (where status in ('failed', 'cancelled')) as failed").fetchall()}
    assert g["bkash"][2] >= 1 and g["sslcommerz"][5] >= 115000


def test_order_lists_and_review_queue(app, admin, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    settle(app, oid, amount=5)
    q = run_admin(admin, "o.status = 'review' order by o.created_at").fetchall()
    mine = [r for r in q if r[0].strip() == oid]
    assert mine and mine[0][7] == 5 and mine[0][8] == "BDT"          # what was received vs what was due (115000)
    assert any(r[0].strip() == oid for r in run_admin(admin, "from orders o join tenants t on t.id = o.tenant_id where (? = ''", ("review", "review")).fetchall())
    assert run_admin(admin, "from orders o join tenants t on t.id = o.tenant_id where (? = ''", ("", "")).fetchall()


def test_coupon_admin_statements(admin, app, world):
    as_admin(admin)
    cid = ulid()
    admin.execute(sql_of(ADMIN, "insert into coupons"), (cid, "SAVE10", "ten off", "percent", 1000, None, None, "any", 5, 1, None, None))
    row = [r for r in admin.execute(sql_of(ADMIN, "from coupons order by created_at")).fetchall() if r[0].strip() == cid][0]
    assert row[1] == "SAVE10" and row[3] == "percent" and row[13] == "active"
    admin.execute(sql_of(ADMIN, "update coupons set status"), ("disabled", cid))
    assert admin.execute("SELECT status FROM coupons WHERE id=%s", (cid,)).fetchone()[0] == "disabled"


def test_refund_and_close_review_statements(admin, app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    oid = order(app, t)
    settle(app, oid)
    as_admin(admin)
    rid = admin.execute(sql_of(ADMIN, "billing_record_refund"), (oid, 1000, "goodwill", None, "false", "false", u)).fetchone()[0]
    assert rid and admin.execute(sql_of(ADMIN, "from refunds r join tenants")).fetchall()
    bad = order(app, t)
    settle(app, bad, amount=1)
    admin.execute(sql_of(ADMIN, "billing_close_review"), (bad, u))
    assert admin.execute("SELECT status FROM orders WHERE id=%s", (bad,)).fetchone()[0] == "cancelled"


def test_repo_statements_follow_a_whole_purchase(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    pro = plan(app, "pro")
    q = app.execute(sql_of(REPO, "billing_quote"), (t, "subscription", pro, "month", "BDT", None))
    cols = [c.name for c in q.description]
    assert dict(zip(cols, q.fetchone()))["total_minor"] == 115000
    oid = app.execute(sql_of(REPO, "billing_create_order"), (t, "subscription", pro, "month", "BDT", None, "sslcommerz", u)).fetchone()[0]
    row = app.execute(sql_of(REPO, "from orders where id"), (oid,)).fetchone()
    assert (row[2], row[3], row[4], row[7]) == ("sslcommerz", "BDT", 115000, "pending")
    app.execute(sql_of(REPO, "billing_set_gateway_ref"), (oid, "SESSION-1"))
    app.execute("SELECT set_config('app.tenant_id','',false)")
    assert app.execute(sql_of(REPO, "billing_order_tenant"), (oid,)).fetchone()[0] == t
    assert app.execute(sql_of(REPO, "billing_order_by_ref"), ("sslcommerz", "SESSION-1")).fetchone()[0] == oid
    set_tenant(app, t)
    r = app.execute(sql_of(REPO, "from billing_settle"), (oid, "sslcommerz", "T1", 115000, "BDT", "E1", json.dumps({"a": 1}))).fetchone()
    assert r[0] == "applied" and r[1]
    r = app.execute(sql_of(REPO, "from billing_settle"), (oid, "sslcommerz", "T1", 115000, "BDT", "E1", json.dumps({}))).fetchone()
    assert r[0] == "duplicate"
    app.execute(sql_of(REPO, "billing_note_event"), (oid, "sslcommerz", None, json.dumps({"why": "x"})))
    other = app.execute(sql_of(REPO, "billing_create_order"), (t, "subscription", pro, "month", "BDT", None, "sslcommerz", u)).fetchone()[0]
    assert app.execute(sql_of(REPO, "billing_fail_order"), (other, "sslcommerz", None, "failed", json.dumps({}))).fetchone()[0] == "failed"


def test_repo_subscription_actions_and_lifecycle(app, world):
    t = world["a"]["tenant"]
    settle(app, order(app, t))
    assert app.execute(sql_of(REPO, "billing_cancel_subscription"), (t, "false")).fetchone()[0] == "cancel_scheduled"
    app.execute(sql_of(REPO, "billing_resume_subscription"), (t,))
    row = app.execute(sql_of(REPO, "from billing_lifecycle")).fetchone()
    assert len(row) == 5


def test_admin_settings_statements(admin, world):
    as_admin(admin)
    assert {r[0] for r in admin.execute(sql_of(ADMIN, "where key like")).fetchall()} >= {"billing.vat_bp", "billing.grace_days", "billing.seller"}
    admin.execute(sql_of(ADMIN, "insert into system_settings"), ("billing.grace_days", json.dumps(10), world["a"]["user"]))
    assert admin.execute("SELECT value FROM system_settings WHERE key='billing.grace_days'").fetchone()[0] == 10
    admin.execute(sql_of(ADMIN, "insert into system_settings"), ("billing.grace_days", json.dumps(7), world["a"]["user"]))
