"""Phase 10: orders, settlement, coupons, VAT invoices, subscription lifecycle, refunds - on a real PostgreSQL."""
import json
import random
import string

import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid

Q = "SELECT (billing_quote(%s,%s,%s,%s,%s,%s)).*"


def plan(conn, code):
    return conn.execute("SELECT id FROM plans WHERE code=%s", (code,)).fetchone()[0]


@pytest.fixture(autouse=True)
def prices(owner):
    as_admin(owner)
    for code, month, year in (("pro", "1000.00", "10000.00"), ("ultra", "3000.00", "30000.00")):
        pid = plan(owner, code)
        for cur, m, y in (("BDT", month, year), ("USD", str(int(float(month) / 100)), str(int(float(year) / 100)))):
            for iv, amt in (("month", m), ("year", y)):
                owner.execute(
                    "INSERT INTO plan_prices (plan_id,currency,interval,amount) VALUES (%s,%s,%s,%s) "
                    "ON CONFLICT (plan_id,currency,interval) DO UPDATE SET amount=EXCLUDED.amount", (pid, cur, iv, amt))
    owner.execute("UPDATE plans SET included_credits=0, monthly_credit_refill=false WHERE code IN ('pro','ultra')")
    owner.execute("UPDATE plans SET sort=20 WHERE code='pro'")
    owner.execute("UPDATE plans SET sort=30 WHERE code='ultra'")
    yield
    as_admin(owner)                                                     # prices are the owner's call: leave the catalog as seeded
    owner.execute("DELETE FROM plan_prices")
    owner.execute("UPDATE plans SET included_credits=0, monthly_credit_refill=false")


def code():
    return "T" + "".join(random.choices(string.ascii_uppercase + string.digits, k=8))


def coupon(owner, **kw):
    as_admin(owner)
    c = code()
    d = dict(kind="percent", percent_bp=1000, fixed_minor=None, currency=None, applies_to="any", max_redemptions=None, per_tenant_limit=1,
             valid_from=None, valid_until=None, status="active")
    d.update(kw)
    owner.execute(
        "INSERT INTO coupons (id,code,kind,percent_bp,fixed_minor,currency,applies_to,max_redemptions,per_tenant_limit,valid_from,valid_until,status) "
        "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
        (ulid(), c, d["kind"], d["percent_bp"], d["fixed_minor"], d["currency"], d["applies_to"], d["max_redemptions"], d["per_tenant_limit"],
         d["valid_from"], d["valid_until"], d["status"]))
    return c


def package(owner, price="500.00", credits="100", bonus="10", cur="BDT", days=None):
    as_admin(owner)
    i = ulid()
    owner.execute("INSERT INTO credit_packages (id,name,credits,bonus_credits,price,currency,expires_after_days,status) VALUES (%s,%s,%s,%s,%s,%s,%s,'active')",
                  (i, "pk" + i, credits, bonus, price, cur, days))
    return i


def order(app, t, kind="subscription", ref=None, interval="month", cur="BDT", cpn=None, gw="sslcommerz"):
    set_tenant(app, t)
    ref = ref or plan(app, "pro")
    return app.execute("SELECT billing_create_order(%s,%s,%s,%s,%s,%s,%s,NULL)", (t, kind, ref, interval, cur, cpn, gw)).fetchone()[0]


def total(app, oid):
    return app.execute("SELECT total_minor FROM orders WHERE id=%s", (oid,)).fetchone()[0]


def settle(app, oid, txn=None, amount=None, cur="BDT", gw="sslcommerz", ev=None, raw=None):
    amount = total(app, oid) if amount is None else amount
    txn = txn or ("TX" + oid)
    return app.execute("SELECT outcome, invoice_id FROM billing_settle(%s,%s,%s,%s,%s,%s,%s::jsonb)",
                       (oid, gw, txn, amount, cur, ev, json.dumps(raw or {}))).fetchone()


def sub(conn, t):
    as_admin(conn)
    return conn.execute("SELECT status, plan_id, interval, current_period_start, current_period_end, cancel_at, grace_ends_at, pending_plan_id "
                        "FROM subscriptions WHERE tenant_id=%s ORDER BY created_at DESC LIMIT 1", (t,)).fetchone()


def err(excinfo):
    return str(excinfo.value).splitlines()[0]


# ---------------------------------------------------------------- quote
def test_bdt_quote_adds_15_percent_vat(app, world):
    t = world["a"]["tenant"]; set_tenant(app, t)
    q = app.execute(Q, (t, "subscription", plan(app, "pro"), "month", "BDT", None)).fetchone()
    d = dict(zip([c.name for c in app.execute(Q, (t, "subscription", plan(app, "pro"), "month", "BDT", None)).description], q))
    assert (d["list_minor"], d["vat_bp"], d["vat_minor"], d["total_minor"], d["change_kind"]) == (100000, 1500, 15000, 115000, "new")


def test_usd_quote_has_no_vat(app, world):
    t = world["a"]["tenant"]; set_tenant(app, t)
    r = app.execute("SELECT (billing_quote(%s,'subscription',%s,'year','USD',NULL)).total_minor", (t, plan(app, "pro"))).fetchone()
    assert r[0] == 10000


@pytest.mark.parametrize("ref_code,cur,msg", [("free", "BDT", "plan_unavailable"), ("pro", "EUR", "price_not_set")])
def test_quote_rejects_unsellable_things(app, world, ref_code, cur, msg):
    t = world["a"]["tenant"]; set_tenant(app, t)
    with pytest.raises(psycopg.errors.RaiseException) as e:
        app.execute(Q, (t, "subscription", plan(app, ref_code), "month", cur, None))
    assert msg in err(e)


def test_a_workspace_cannot_quote_or_order_for_another(app, world):
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(Q, (world["b"]["tenant"], "subscription", plan(app, "pro"), "month", "BDT", None))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT billing_create_order(%s,'subscription',%s,'month','BDT',NULL,'bkash',NULL)", (world["b"]["tenant"], plan(app, "pro")))


# ---------------------------------------------------------------- coupons
def test_percent_coupon_rounds_half_up(app, owner, world):
    t = world["a"]["tenant"]
    pk = package(owner, price="1.01")                                  # 101 paisa
    c = coupon(owner, percent_bp=5000)                                 # 50.5 paisa off -> 51
    set_tenant(app, t)
    r = app.execute("SELECT (billing_quote(%s,'credits',%s,NULL,'BDT',%s)).discount_minor", (t, pk, c)).fetchone()
    assert r[0] == 51


def test_fixed_coupon_never_goes_below_zero_and_needs_the_free_gateway(app, owner, world):
    t = world["a"]["tenant"]
    c = coupon(owner, kind="fixed", percent_bp=None, fixed_minor=99999999, currency="BDT")
    set_tenant(app, t)
    q = app.execute("SELECT (billing_quote(%s,'subscription',%s,'month','BDT',%s)).total_minor", (t, plan(app, "pro"), c)).fetchone()
    assert q[0] == 0
    with pytest.raises(psycopg.errors.RaiseException):                  # a paid gateway with a zero total is refused
        app.execute("SELECT billing_create_order(%s,'subscription',%s,'month','BDT',%s,'bkash',NULL)", (t, plan(app, "pro"), c))
    oid = app.execute("SELECT billing_create_order(%s,'subscription',%s,'month','BDT',%s,'free',NULL)", (t, plan(app, "pro"), c)).fetchone()[0]
    assert settle(app, oid, txn="free:" + oid, amount=0, gw="free")[0] == "applied"
    assert sub(app, t)[0] == "active"


@pytest.mark.parametrize("kw,msg", [
    (dict(status="disabled"), "coupon_invalid"),
    (dict(valid_until="2000-01-01"), "coupon_invalid"),
    (dict(valid_from="2999-01-01"), "coupon_invalid"),
    (dict(applies_to="credits"), "coupon_invalid"),
    (dict(kind="fixed", percent_bp=None, fixed_minor=100, currency="USD"), "coupon_invalid"),
    (dict(max_redemptions=1, redeemed=1), "coupon_exhausted"),
])
def test_bad_coupons(app, owner, world, kw, msg):
    t = world["a"]["tenant"]
    redeemed = kw.pop("redeemed", 0)
    c = coupon(owner, **kw)
    if redeemed:
        owner.execute("UPDATE coupons SET redeemed_count=%s WHERE code=%s", (redeemed, c))
    set_tenant(app, t)
    with pytest.raises(psycopg.errors.RaiseException) as e:
        app.execute(Q, (t, "subscription", plan(app, "pro"), "month", "BDT", c))
    assert msg in err(e)
    with pytest.raises(psycopg.errors.RaiseException) as e:
        app.execute(Q, (t, "subscription", plan(app, "pro"), "month", "BDT", "NOSUCHCODE"))
    assert "coupon_invalid" in err(e)


def test_coupon_is_booked_only_when_paid_and_once_per_workspace(app, owner, world):
    t = world["a"]["tenant"]
    c = coupon(owner, max_redemptions=5)
    oid = order(app, t, cpn=c)
    as_admin(owner)
    assert owner.execute("SELECT redeemed_count FROM coupons WHERE code=%s", (c,)).fetchone()[0] == 0
    assert settle(app, oid)[0] == "applied"
    assert owner.execute("SELECT redeemed_count FROM coupons WHERE code=%s", (c,)).fetchone()[0] == 1
    settle(app, oid)                                                    # duplicate IPN does not redeem twice
    assert owner.execute("SELECT redeemed_count FROM coupons WHERE code=%s", (c,)).fetchone()[0] == 1
    with pytest.raises(psycopg.errors.RaiseException) as e:
        app.execute(Q, (t, "credits", package(owner), None, "BDT", c))
    assert "coupon_used" in err(e)


def test_coupon_codes_cannot_be_listed_by_the_app(app, world):
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT code FROM coupons")


# ---------------------------------------------------------------- orders are tamper-proof
def test_app_cannot_write_orders_or_subscriptions_directly(app, owner, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    for stmt in ("UPDATE orders SET status='paid'", "DELETE FROM orders", "INSERT INTO subscriptions (id,tenant_id,plan_id,status) VALUES ('x','x','x','active')",
                 "UPDATE subscriptions SET status='active'"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(stmt)
    as_admin(owner)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):           # even the owner cannot change an amount afterwards
        owner.execute("UPDATE orders SET total_minor=1 WHERE id=%s", (oid,))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        owner.execute("DELETE FROM orders WHERE id=%s", (oid,))


def test_orders_are_private_to_their_workspace(app, world):
    oid = order(app, world["a"]["tenant"])
    set_tenant(app, world["b"]["tenant"])
    assert app.execute("SELECT count(*) FROM orders WHERE id=%s", (oid,)).fetchone()[0] == 0


def test_too_many_pending_orders(app, world):
    t = world["a"]["tenant"]
    for _ in range(10):
        order(app, t)
    with pytest.raises(psycopg.errors.RaiseException) as e:
        order(app, t)
    assert "too_many_pending" in err(e)


# ---------------------------------------------------------------- settlement
def test_payment_activates_subscription_and_issues_a_vat_invoice(app, owner, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    outcome, inv = settle(app, oid, raw={"tran_id": "T1"})
    assert outcome == "applied" and inv
    s = sub(owner, t)
    assert s[0] == "active" and s[2] == "month" and s[1] == plan(owner, "pro")
    assert 27 <= (s[4] - s[3]).days <= 31
    r = app.execute("SELECT number, kind, currency, subtotal_minor, vat_bp, vat_minor, total_minor FROM invoices WHERE id=%s", (inv,)).fetchone()
    assert r[0].startswith("INV-") and r[0].endswith("-000001") or r[0].startswith("INV-")
    assert r[1:] == ("invoice", "BDT", 100000, 1500, 15000, 115000)
    assert app.execute("SELECT status, gateway_txn_id FROM orders WHERE id=%s", (oid,)).fetchone() == ("paid", "TX" + oid)
    assert app.execute("SELECT enabled, limit_value FROM effective_feature(%s,'limit.members')", (t,)).fetchone()[1] == 10


def test_duplicate_ipn_changes_nothing(app, owner, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    first = settle(app, oid, ev="E1")
    before = sub(owner, t)
    for _ in range(3):
        assert settle(app, oid, ev="E1")[0] == "duplicate"
    assert sub(owner, t) == before
    assert app.execute("SELECT count(*) FROM invoices WHERE order_id=%s", (oid,)).fetchone()[0] == 1
    assert settle(app, oid)[1] == first[1]
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM payment_events WHERE order_id=%s AND outcome='applied'", (oid,)).fetchone()[0] == 1
    assert owner.execute("SELECT count(*) FROM payment_events WHERE order_id=%s AND outcome='duplicate'", (oid,)).fetchone()[0] >= 3


def test_amount_mismatch_is_never_applied(app, owner, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    assert settle(app, oid, amount=total(app, oid) - 1)[0] == "amount_mismatch"
    assert sub(owner, t) is None
    assert app.execute("SELECT status FROM orders WHERE id=%s", (oid,)).fetchone()[0] == "review"
    assert settle(app, oid, txn="TXN-2")[0] == "review"                 # a later "correct" callback does not silently resurrect it
    assert sub(owner, t) is None


def test_currency_mismatch_is_never_applied(app, owner, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    assert settle(app, oid, cur="USD")[0] == "currency_mismatch"
    assert sub(owner, t) is None


def test_fake_redirect_cannot_pay_an_order(app, owner, world):
    t = world["a"]["tenant"]
    oid = order(app, t, gw="bkash")
    assert settle(app, oid, gw="sslcommerz")[0] == "gateway_mismatch"      # paid on a different gateway than the order's
    assert sub(owner, t) is None
    with pytest.raises(psycopg.errors.RaiseException) as e:
        settle(app, ulid(), amount=1)
    assert "order_not_found" in err(e)
    set_tenant(app, world["b"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):             # another workspace cannot settle it either
        app.execute("SELECT * FROM billing_settle(%s,'bkash','X',%s,'BDT',NULL,'{}')", (oid, 115000))


def test_one_transaction_cannot_pay_two_orders(app, owner, world):
    t = world["a"]["tenant"]
    o1, o2 = order(app, t), order(app, t)
    assert settle(app, o1, txn="SAME")[0] == "applied"
    assert settle(app, o2, txn="SAME")[0] == "duplicate_txn"
    assert app.execute("SELECT status FROM orders WHERE id=%s", (o2,)).fetchone()[0] == "pending"


def test_second_payment_for_a_paid_order_is_flagged(app, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    settle(app, oid, txn="A")
    assert settle(app, oid, txn="B")[0] == "double_payment"


def test_late_but_correct_payment_for_expired_order_is_honoured(app, owner, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    as_admin(owner)
    owner.execute("UPDATE orders SET status='expired' WHERE id=%s", (oid,))
    assert settle(app, oid)[0] == "applied"


def test_credit_package_purchase_grants_credits_once(app, owner, world):
    t = world["a"]["tenant"]
    pk = package(owner, price="500.00", credits="100", bonus="10", days=30)
    oid = order(app, t, kind="credits", ref=pk, interval=None)
    assert total(app, oid) == 57500
    settle(app, oid); settle(app, oid)
    assert app.execute("SELECT balance FROM wallets WHERE tenant_id=%s", (t,)).fetchone()[0] == 110
    row = app.execute("SELECT type, amount, reference_type, reference_id, expires_at IS NOT NULL FROM credit_transactions WHERE tenant_id=%s", (t,)).fetchone()
    assert row == ("purchase", 110, "order", oid, True)


def test_failed_callbacks_close_pending_orders_but_never_paid_ones(app, world):
    t = world["a"]["tenant"]
    o1, o2 = order(app, t), order(app, t)
    assert app.execute("SELECT billing_fail_order(%s,'sslcommerz','F1','failed','{}')", (o1,)).fetchone()[0] == "failed"
    settle(app, o2)
    assert app.execute("SELECT billing_fail_order(%s,'sslcommerz','F2','failed','{}')", (o2,)).fetchone()[0] == "ignored"
    assert app.execute("SELECT status FROM orders WHERE id=%s", (o2,)).fetchone()[0] == "paid"


def test_invoice_numbers_are_sequential_and_invoices_are_immutable(app, owner, world):
    nums = []
    for k in ("a", "b"):
        t = world[k]["tenant"]
        nums.append(app.execute("SELECT number FROM invoices WHERE id=%s", (settle(app, order(app, t))[1],)).fetchone()[0])
    a, b = [int(n.rsplit("-", 1)[1]) for n in nums]
    assert b == a + 1
    as_admin(owner)
    for stmt in ("UPDATE invoices SET total_minor=1", "DELETE FROM invoices"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            owner.execute(stmt)


def test_seller_and_buyer_are_snapshotted_on_the_invoice(app, owner, world):
    t = world["a"]["tenant"]
    as_admin(owner)
    owner.execute("UPDATE system_settings SET value=%s::jsonb WHERE key='billing.seller'", (json.dumps({"name": "Jarvis Ltd", "bin": "123"}),))
    set_tenant(app, t)
    app.execute("INSERT INTO billing_profiles (tenant_id,legal_name,tax_id) VALUES (%s,'Buyer Co','TIN9')", (t,))
    inv = settle(app, order(app, t))[1]
    seller, buyer = app.execute("SELECT seller, buyer FROM invoices WHERE id=%s", (inv,)).fetchone()
    assert seller["name"] == "Jarvis Ltd" and buyer["name"] == "Buyer Co" and buyer["tax_id"] == "TIN9"
    owner.execute("UPDATE system_settings SET value='{\"name\":\"\"}'::jsonb WHERE key='billing.seller'")


# ---------------------------------------------------------------- subscription lifecycle
def test_early_renewal_extends_from_the_old_period_end(app, owner, world):
    t = world["a"]["tenant"]
    settle(app, order(app, t), txn="R1")
    first = sub(owner, t)
    o2 = order(app, t)
    assert app.execute("SELECT change_kind FROM orders WHERE id=%s", (o2,)).fetchone()[0] == "renew"
    settle(app, o2, txn="R2")
    second = sub(owner, t)
    assert second[3] == first[3] and second[4] > first[4]
    assert 55 <= (second[4] - second[3]).days <= 62


def test_upgrade_credits_the_unused_time_and_downgrade_waits(app, owner, world):
    t = world["a"]["tenant"]
    settle(app, order(app, t), txn="U1")
    as_admin(owner)
    owner.execute("UPDATE subscriptions SET current_period_start=now()-interval '15 days', current_period_end=now()+interval '15 days' WHERE tenant_id=%s", (t,))
    ultra = plan(app, "ultra")
    d = app.execute("SELECT (billing_quote(%s,'subscription',%s,'month','BDT',NULL)).*", (t, ultra))
    row = dict(zip([c.name for c in d.description], d.fetchone()))
    assert row["change_kind"] == "upgrade" and row["list_minor"] == 300000
    assert 49000 <= row["proration_minor"] <= 51000                        # about half of the old 1000.00 is left
    assert row["total_minor"] == int(round((300000 - row["proration_minor"]) * 1.15)) or abs(row["total_minor"] - (300000 - row["proration_minor"]) * 1.15) < 2
    oid = order(app, t, ref=ultra)
    settle(app, oid, txn="U2")
    s = sub(owner, t)
    assert s[1] == ultra and s[0] == "active"
    pro = plan(app, "pro")
    with pytest.raises(psycopg.errors.RaiseException) as e:
        app.execute(Q, (t, "subscription", pro, "month", "BDT", None))
    assert "use_schedule_downgrade" in err(e)
    app.execute("SELECT billing_schedule_downgrade(%s,%s,'month')", (t, pro))
    assert sub(owner, t)[7] == pro and sub(owner, t)[1] == ultra            # nothing changes until the period ends
    with pytest.raises(psycopg.errors.RaiseException) as e:
        app.execute("SELECT billing_schedule_downgrade(%s,%s,'month')", (t, ultra))
    assert "not_a_downgrade" in err(e)
    owner.execute("UPDATE subscriptions SET current_period_end=now()-interval '1 minute' WHERE tenant_id=%s", (t,))
    app.execute("SELECT * FROM billing_lifecycle()")
    s = sub(owner, t)
    assert s[0] == "grace" and s[1] == pro and s[7] is None                 # the lower plan applies from the period end
    oid = order(app, t, ref=pro)
    assert app.execute("SELECT change_kind FROM orders WHERE id=%s", (oid,)).fetchone()[0] == "renew"
    settle(app, oid, txn="U3")
    assert sub(owner, t)[0] == "active"


def test_grace_then_expiry_then_free(app, owner, world):
    t = world["a"]["tenant"]
    settle(app, order(app, t))
    as_admin(owner)
    owner.execute("UPDATE subscriptions SET current_period_end=now()-interval '1 hour' WHERE tenant_id=%s", (t,))
    r = app.execute("SELECT * FROM billing_lifecycle()").fetchone()
    assert r[1] >= 1
    s = sub(owner, t)
    assert s[0] == "grace" and 6 <= (s[6] - s[4]).days <= 7
    assert app.execute("SELECT limit_value FROM effective_feature(%s,'limit.members')", (t,)).fetchone()[0] == 10   # still entitled
    owner.execute("UPDATE subscriptions SET grace_ends_at=now()-interval '1 minute' WHERE tenant_id=%s", (t,))
    app.execute("SELECT * FROM billing_lifecycle()")
    assert sub(owner, t)[0] == "expired"
    assert app.execute("SELECT limit_value FROM effective_feature(%s,'limit.members')", (t,)).fetchone()[0] == 3
    oid = order(app, t)                                                     # can subscribe again afterwards
    assert app.execute("SELECT change_kind FROM orders WHERE id=%s", (oid,)).fetchone()[0] == "new"
    assert settle(app, oid, txn="N2")[0] == "applied"


def test_paying_during_grace_restarts_the_period(app, owner, world):
    t = world["a"]["tenant"]
    settle(app, order(app, t))
    as_admin(owner)
    owner.execute("UPDATE subscriptions SET current_period_end=now()-interval '1 hour' WHERE tenant_id=%s", (t,))
    app.execute("SELECT * FROM billing_lifecycle()")
    settle(app, order(app, t), txn="G2")
    s = sub(owner, t)
    assert s[0] == "active" and s[6] is None and (s[4] - s[3]).days >= 27


def test_cancel_at_period_end_then_resume_then_cancel_for_real(app, owner, world):
    t = world["a"]["tenant"]
    settle(app, order(app, t))
    assert app.execute("SELECT billing_cancel_subscription(%s,false)", (t,)).fetchone()[0] == "cancel_scheduled"
    s = sub(owner, t)
    assert s[0] == "active" and s[5] == s[4]                                # still usable until the end
    app.execute("SELECT billing_resume_subscription(%s)", (t,))
    assert sub(owner, t)[5] is None
    app.execute("SELECT billing_cancel_subscription(%s,false)", (t,))
    as_admin(owner)
    owner.execute("UPDATE subscriptions SET current_period_end=now()-interval '1 minute', cancel_at=now()-interval '1 minute' WHERE tenant_id=%s", (t,))
    app.execute("SELECT * FROM billing_lifecycle()")
    assert sub(owner, t)[0] == "cancelled"                                  # no grace for a deliberate cancel
    assert app.execute("SELECT limit_value FROM effective_feature(%s,'limit.members')", (t,)).fetchone()[0] == 3
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("SELECT billing_cancel_subscription(%s,true)", (t,))


def test_immediate_cancel(app, owner, world):
    t = world["a"]["tenant"]
    settle(app, order(app, t))
    assert app.execute("SELECT billing_cancel_subscription(%s,true)", (t,)).fetchone()[0] == "cancelled"
    assert sub(owner, t)[0] == "cancelled"


def test_lifecycle_expires_stale_pending_orders(app, owner, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    as_admin(owner)
    owner.execute("UPDATE orders SET expires_at=now()-interval '1 minute' WHERE id=%s", (oid,))
    assert app.execute("SELECT orders_expired FROM billing_lifecycle()").fetchone()[0] >= 1
    assert app.execute("SELECT status FROM orders WHERE id=%s", (oid,)).fetchone()[0] == "expired"


def test_plan_credits_granted_on_payment_and_monthly_refill_for_yearly(app, owner, world):
    t = world["a"]["tenant"]
    as_admin(owner)
    owner.execute("UPDATE plans SET included_credits=50, monthly_credit_refill=true WHERE code='pro'")
    oid = order(app, t, interval="year")
    settle(app, oid)
    assert app.execute("SELECT balance FROM wallets WHERE tenant_id=%s", (t,)).fetchone()[0] == 50
    app.execute("SELECT * FROM billing_lifecycle()")                        # too early: nothing
    assert app.execute("SELECT balance FROM wallets WHERE tenant_id=%s", (t,)).fetchone()[0] == 50
    owner.execute("UPDATE subscriptions SET last_refill_at=now()-interval '40 days' WHERE tenant_id=%s", (t,))
    assert app.execute("SELECT refills FROM billing_lifecycle()").fetchone()[0] >= 1
    assert app.execute("SELECT balance FROM wallets WHERE tenant_id=%s", (t,)).fetchone()[0] == 100
    app.execute("SELECT * FROM billing_lifecycle()")                        # same month again: still 100
    assert app.execute("SELECT balance FROM wallets WHERE tenant_id=%s", (t,)).fetchone()[0] == 100


# ---------------------------------------------------------------- refunds
def test_refund_issues_credit_note_and_updates_the_order(app, admin, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    settle(app, oid)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):               # customers cannot refund themselves
        app.execute("SELECT billing_record_refund(%s,1000,'x',NULL,false,false,%s)", (oid, world["a"]["user"]))
    app.rollback() if not app.autocommit else None
    as_admin(admin)
    admin.execute("SELECT billing_record_refund(%s,%s,'duplicate charge','GW-R1',false,false,%s)", (oid, 23000, world["a"]["user"]))
    row = admin.execute("SELECT status, refunded_minor FROM orders WHERE id=%s", (oid,)).fetchone()
    assert row == ("partially_refunded", 23000)
    cn = admin.execute("SELECT number, subtotal_minor, vat_minor, total_minor FROM invoices WHERE order_id=%s AND kind='credit_note'", (oid,)).fetchone()
    assert cn[0].startswith("CN-") and cn[3] == 23000 and cn[2] == 3000 and cn[1] == 20000
    with pytest.raises(psycopg.errors.RaiseException) as e:
        admin.execute("SELECT billing_record_refund(%s,%s,'too much',NULL,false,false,%s)", (oid, 92001, world["a"]["user"]))
    assert "invalid_amount" in err(e)
    admin.execute("SELECT billing_record_refund(%s,%s,'rest',NULL,false,false,%s)", (oid, 92000, world["a"]["user"]))
    assert admin.execute("SELECT status FROM orders WHERE id=%s", (oid,)).fetchone()[0] == "refunded"
    with pytest.raises(psycopg.errors.RaiseException):
        admin.execute("SELECT billing_record_refund(%s,1,'again',NULL,false,false,%s)", (oid, world["a"]["user"]))


def test_refund_can_take_credits_back_and_end_the_subscription(app, admin, owner, world):
    t = world["a"]["tenant"]
    pk = package(owner, price="500.00", credits="100", bonus="0")
    oid = order(app, t, kind="credits", ref=pk, interval=None)
    settle(app, oid)
    as_admin(admin)
    admin.execute("SELECT billing_record_refund(%s,%s,'half',NULL,true,false,%s)", (oid, 28750, world["a"]["user"]))
    assert admin.execute("SELECT balance FROM wallets WHERE tenant_id=%s", (t,)).fetchone()[0] == 50
    assert admin.execute("SELECT credits_clawed_back FROM refunds WHERE order_id=%s", (oid,)).fetchone()[0] == 50

    t2 = world["b"]["tenant"]
    o2 = order(app, t2)
    settle(app, o2)
    admin.execute("SELECT billing_record_refund(%s,%s,'cancel',NULL,false,true,%s)", (o2, 115000, world["b"]["user"]))
    assert sub(owner, t2)[0] == "cancelled"


def test_review_orders_are_closed_by_admins_only(app, admin, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    settle(app, oid, amount=5)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT billing_close_review(%s,%s)", (oid, world["a"]["user"]))
    as_admin(admin)
    admin.execute("SELECT billing_close_review(%s,%s)", (oid, world["a"]["user"]))
    assert admin.execute("SELECT status FROM orders WHERE id=%s", (oid,)).fetchone()[0] == "cancelled"


def test_payment_events_are_admin_only_and_append_only(app, admin, world):
    t = world["a"]["tenant"]
    settle(app, order(app, t), raw={"x": "y" * 30000})
    set_tenant(app, t)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM payment_events")
    as_admin(admin)
    assert admin.execute("SELECT raw FROM payment_events WHERE tenant_id=%s", (t,)).fetchone()[0] == {"truncated": True}   # oversized payloads are not kept
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        admin.execute("DELETE FROM payment_events")


def test_order_tenant_lookup_works_without_a_login_context(app, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    app.execute("SELECT billing_set_gateway_ref(%s,'SESS-1')", (oid,))
    app.execute("SELECT set_config('app.tenant_id','',false)")
    assert app.execute("SELECT billing_order_tenant(%s)", (oid,)).fetchone()[0] == t
    assert app.execute("SELECT billing_order_by_ref('sslcommerz','SESS-1')").fetchone()[0] == oid
    assert app.execute("SELECT billing_order_tenant(%s)", (ulid(),)).fetchone()[0] is None
    set_tenant(app, t)
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("SELECT billing_set_gateway_ref(%s,'OTHER')", (oid,))               # write-once


def test_vat_rounds_half_up(app, owner, world):
    t = world["a"]["tenant"]
    pk = package(owner, price="0.10")                                   # 10 paisa x 15 % = 1.5 paisa -> 2
    set_tenant(app, t)
    r = app.execute("SELECT (billing_quote(%s,'credits',%s,NULL,'BDT',NULL)).vat_minor", (t, pk)).fetchone()
    assert r[0] == 2


def test_unverifiable_callbacks_are_noted_without_changing_the_order(app, admin, world):
    t = world["a"]["tenant"]
    oid = order(app, t)
    app.execute("SELECT billing_note_event(%s,'sslcommerz','V1','{\"why\":\"not valid\"}')", (oid,))
    assert app.execute("SELECT status FROM orders WHERE id=%s", (oid,)).fetchone()[0] == "pending"
    as_admin(admin)
    assert admin.execute("SELECT outcome FROM payment_events WHERE order_id=%s", (oid,)).fetchone()[0] == "ignored"
    set_tenant(app, world["b"]["tenant"])
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT billing_note_event(%s,'sslcommerz','V2','{}')", (oid,))
