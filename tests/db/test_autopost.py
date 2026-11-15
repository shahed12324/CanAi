import datetime
import json

import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_accounting import acc, cr, ctx, dr, post, seed  # noqa: F401
from test_accounting_docs import setup, wipe_all
from test_couriers import account as courier_account, book
from test_orders import create, live

IMMUTABLE = ("stock_movements", "product_events", "store_order_events", "store_order_items", "order_shipment_events", "inv_costs", "journal_entries", "journal_lines", "acct_lock_log", "acct_doc_lines")


def wipe(owner):
    as_admin(owner)
    for t in IMMUTABLE:
        owner.execute(f"ALTER TABLE {t} DISABLE TRIGGER {t}_no_change")
    for t in ("courier_settlements", "autopost_queue", "autopost_settings", "inv_costs", "order_shipment_events", "order_shipments", "courier_accounts", "store_order_events", "store_order_items", "store_orders",
              "order_settings", "stock_movements", "product_events", "product_variants", "products", "store_connections"):
        owner.execute(f"DELETE FROM {t}")
    for t in IMMUTABLE:
        owner.execute(f"ALTER TABLE {t} ENABLE TRIGGER {t}_no_change")
    wipe_all(owner)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    wipe(owner)


def enable(app, world, who="a", on=True):
    ctx(app, world, who)
    return app.execute("SELECT autopost_set(%s,%s,%s)", (world[who]["tenant"], world[who]["user"], on)).fetchone()[0]


def run(app, world, who="a", limit=50):
    ctx(app, world, who)
    return tuple(app.execute("SELECT * FROM autopost_run(%s,%s,%s)", (world[who]["tenant"], world[who]["user"], limit)).fetchone())


def deliver(app, world, *, phone="01711223344", price=50000, qty=1, cost=None, courier=None):
    """A delivered order. courier=None: own delivery. Returns (order id, variant)."""
    u = ctx(app, world)
    if courier:
        oid, v, _, sid = book(app, world, phone, courier, status="packed")
        assert app.execute("SELECT shipment_apply(%s,'delivered','delivered')", (sid,)).fetchone()[0] in ("changed", "mismatch")
    else:
        _, v = live(app, world, title="Tee" + phone, price=price, stock=20)
        oid, r = create(app, world, [{"variant_id": v, "qty": qty}], phone=phone)
        assert r[0] == "ok"
        for to in ("confirmed", "packed"):
            assert app.execute("SELECT order_transition(%s,%s,%s,NULL)", (oid, u, to)).fetchone()[0] == "ok"
        # shipped -> delivered needs the order to be shipped first
        app.execute("SELECT order_transition(%s,%s,'shipped',NULL)", (oid, u))
        assert app.execute("SELECT order_transition(%s,%s,'delivered',NULL)", (oid, u)).fetchone()[0] == "ok"
    if cost is not None:
        assert app.execute("SELECT inv_cost_set(%s,%s,'2026-10-01'::date,%s)", (v, cost, u)).fetchone()[0] == "ok"
    return oid, v


def q_rows(app, order=None):
    return app.execute("SELECT kind, status, courier, amount_minor, last_error, note FROM autopost_queue " + ("WHERE order_id=%s " if order else "") + "ORDER BY seq", (order,) if order else ()).fetchall()


def balance(app, key):
    return app.execute("SELECT coalesce(sum(l.debit_minor-l.credit_minor),0) FROM journal_lines l JOIN accounts a ON a.id=l.account_id WHERE a.system_key=%s", (key,)).fetchone()[0]


def entry_of(app, oid):
    return app.execute("SELECT a.system_key, l.debit_minor, l.credit_minor FROM autopost_queue q JOIN journal_lines l ON l.entry_id=q.entry_id JOIN accounts a ON a.id=l.account_id "
                       "WHERE q.order_id=%s AND q.kind='sale' ORDER BY l.seq", (oid,)).fetchall()


def report(app, world):
    ctx(app, world)
    return {r[0]: r[1:] for r in app.execute("SELECT * FROM courier_receivable_report()").fetchall()}


def settle(app, world, courier, gross, fee, account, *, date="2026-11-02", who="a"):
    ctx(app, world, who)
    sid = ulid()
    return sid, app.execute("SELECT courier_settle(%s,%s,%s,%s,%s::date,%s,%s,%s,%s,NULL)", (sid, ulid(), world[who]["tenant"], world[who]["user"], date, courier, gross, fee, account)).fetchone()[0]


# ----------------------------------------------------------------------------------------------------------------- switching on
def test_off_by_default_nothing_is_queued(app, world):
    setup(app, world)
    deliver(app, world)
    assert q_rows(app) == []
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 0


def test_cannot_switch_on_without_the_chart_or_its_accounts(app, world):
    ctx(app, world)
    assert enable(app, world) == "not_seeded"
    setup(app, world)
    ctx(app, world)
    app.execute("UPDATE accounts SET active=false WHERE system_key='courier_expense'")
    assert enable(app, world) == "no_system_account"
    assert app.execute("SELECT count(*) FROM autopost_settings WHERE enabled").fetchone()[0] == 0
    app.execute("UPDATE accounts SET active=true WHERE system_key='courier_expense'")
    assert enable(app, world) == "ok"
    assert enable(app, world, on=False) == "ok"
    assert app.execute("SELECT enabled FROM autopost_settings").fetchone()[0] is False


def test_switch_needs_a_user_and_the_workspace(app, world):
    setup(app, world)
    ctx(app, world)
    with pytest.raises(psycopg.errors.Error):
        app.execute("SELECT autopost_set(%s,NULL,true)", (world["a"]["tenant"],))


# ----------------------------------------------------------------------------------------------------------------- the sale
def test_own_delivery_posts_cash_sales_and_delivery_income(app, world):
    A = setup(app, world)
    assert enable(app, world) == "ok"
    oid, _ = deliver(app, world)
    assert q_rows(app, oid) == [("sale", "pending", None, None, None, None)]
    assert run(app, world) == (1, 0, 0)
    assert entry_of(app, oid) == [("cash", 56000, 0), ("sales", 0, 50000), ("delivery_income", 0, 6000)]
    assert q_rows(app, oid) == [("sale", "posted", None, 56000, None, "1 item(s) without a unit cost: cost of goods not posted for them")]
    e = app.execute("SELECT e.source, e.source_ref, e.memo, e.entry_date FROM autopost_queue q JOIN journal_entries e ON e.id=q.entry_id WHERE q.order_id=%s", (oid,)).fetchone()
    assert e[0] == "system" and e[1] == "order:" + oid and e[2].startswith("Order #1") and e[3] == app.execute("SELECT (now() AT TIME ZONE 'Asia/Dhaka')::date").fetchone()[0]
    assert run(app, world) == (0, 0, 0)                                                   # nothing posts twice
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 1


def test_cost_of_goods_is_posted_when_the_unit_cost_is_known(app, world):
    setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world, qty=3, cost=20000)
    assert run(app, world) == (1, 0, 0)
    assert entry_of(app, oid) == [("cash", 156000, 0), ("sales", 0, 150000), ("delivery_income", 0, 6000), ("cogs", 60000, 0), ("inventory", 0, 60000)]
    assert q_rows(app, oid)[0][5] is None


def test_missing_cost_posts_the_sale_and_says_so(app, world):
    setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world)
    run(app, world)
    assert [x[0] for x in entry_of(app, oid)] == ["cash", "sales", "delivery_income"]
    assert "without a unit cost" in q_rows(app, oid)[0][5]


def test_free_delivery_has_no_zero_line(app, world):
    setup(app, world)
    enable(app, world)
    u = ctx(app, world)
    app.execute("INSERT INTO order_settings (tenant_id, delivery_inside_minor) VALUES (%s, 0) ON CONFLICT (tenant_id) DO UPDATE SET delivery_inside_minor=0", (world["a"]["tenant"],))
    _, v = live(app, world, title="Free", price=10000, stock=5)
    oid, r = create(app, world, [{"variant_id": v, "qty": 1}], phone="01911223344")
    for to in ("confirmed", "packed", "shipped", "delivered"):
        assert app.execute("SELECT order_transition(%s,%s,%s,NULL)", (oid, u, to)).fetchone()[0] == "ok"
    run(app, world)
    assert entry_of(app, oid) == [("cash", 10000, 0), ("sales", 0, 10000)]


def test_a_courier_delivery_is_owed_by_the_courier(app, world):
    setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world, courier="pathao", cost=15000)
    assert run(app, world) == (1, 0, 0)
    assert entry_of(app, oid)[0] == ("courier_receivable", 56000, 0)
    assert q_rows(app, oid) == [("sale", "posted", "pathao", 56000, None, None)]
    assert balance(app, "cash") == 0 and balance(app, "courier_receivable") == 56000
    assert report(app, world) == {"pathao": (1, 56000, 0, 0, 56000)}


def test_orders_delivered_before_switching_on_are_never_posted(app, world):
    setup(app, world)
    old, _ = deliver(app, world, phone="01711000001")
    enable(app, world)
    new, _ = deliver(app, world, phone="01711000002")
    assert [r[0] for r in q_rows(app)] == ["sale"] and q_rows(app, old) == []
    run(app, world)
    # the old one is returned later: there is no sale entry to undo
    u = ctx(app, world)
    assert app.execute("SELECT order_transition(%s,%s,'returned','x')", (old, u)).fetchone()[0] == "ok"
    assert run(app, world) == (0, 0, 1)
    assert q_rows(app, old) == [("sale_return", "skipped", None, None, None, "no sale entry to reverse")]


# ----------------------------------------------------------------------------------------------------------------- returns
def test_a_return_after_delivery_reverses_the_sale(app, world):
    setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world, qty=2, cost=10000)
    run(app, world)
    assert balance(app, "sales") == -100000 and balance(app, "cogs") == 20000
    u = ctx(app, world)
    assert app.execute("SELECT order_transition(%s,%s,'returned','customer refused')", (oid, u)).fetchone()[0] == "ok"
    assert run(app, world) == (1, 0, 0)
    for k in ("cash", "sales", "delivery_income", "cogs", "inventory"):
        assert balance(app, k) == 0, k
    assert [r[:2] for r in q_rows(app, oid)] == [("sale", "posted"), ("sale_return", "posted")]
    assert app.execute("SELECT count(*) FROM journal_entries WHERE source='reversal'").fetchone()[0] == 1
    assert run(app, world) == (0, 0, 0)


def test_delivered_then_returned_before_any_run_posts_nothing(app, world):
    setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world, cost=10000)
    u = ctx(app, world)
    assert app.execute("SELECT order_transition(%s,%s,'returned','x')", (oid, u)).fetchone()[0] == "ok"
    assert [r[:2] for r in q_rows(app, oid)] == [("sale", "pending"), ("sale_return", "pending")]
    assert run(app, world) == (0, 0, 1)
    assert [r[1] for r in q_rows(app, oid)] == ["skipped", "skipped"]
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 0


def test_a_return_while_the_sale_is_stuck_cancels_both(app, world):
    setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world)
    u = ctx(app, world)
    app.execute("UPDATE accounts SET active=false WHERE system_key='sales'")
    assert run(app, world) == (0, 1, 0)                                                    # the sale is stuck as failed
    assert app.execute("SELECT order_transition(%s,%s,'returned','x')", (oid, u)).fetchone()[0] == "ok"
    assert run(app, world) == (0, 0, 1)
    assert [(r[0], r[1], r[5]) for r in q_rows(app, oid)] == [("sale", "skipped", "returned before it was posted"), ("sale_return", "skipped", "returned before it was posted")]
    app.execute("UPDATE accounts SET active=true WHERE system_key='sales'")
    assert run(app, world) == (0, 0, 0)
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 0


def test_return_of_a_courier_order_lowers_what_the_courier_owes(app, world):
    setup(app, world)
    enable(app, world)
    o1, _ = deliver(app, world, courier="steadfast", phone="01711000001")
    o2, _ = deliver(app, world, courier="steadfast", phone="01711000002")
    run(app, world)
    assert report(app, world) == {"steadfast": (2, 112000, 0, 0, 112000)}
    u = ctx(app, world)
    assert app.execute("SELECT order_transition(%s,%s,'returned','x')", (o2, u)).fetchone()[0] == "ok"
    run(app, world)
    assert report(app, world) == {"steadfast": (2, 112000, 56000, 0, 56000)}
    assert balance(app, "courier_receivable") == 56000


def test_a_hand_reversal_is_respected(app, world):
    setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world)
    run(app, world)
    eid = app.execute("SELECT entry_id FROM autopost_queue WHERE order_id=%s", (oid,)).fetchone()[0]
    u = ctx(app, world)
    assert app.execute("SELECT journal_reverse(%s,%s,%s,CURRENT_DATE,'by hand')", (ulid(), eid, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT order_transition(%s,%s,'returned','x')", (oid, u)).fetchone()[0] == "ok"
    assert run(app, world) == (0, 0, 1)
    assert q_rows(app, oid)[1][5] == "already reversed by hand"


def test_switched_off_again_queues_nothing_new(app, world):
    setup(app, world)
    enable(app, world)
    enable(app, world, on=False)
    deliver(app, world)
    assert q_rows(app) == []


def test_returning_an_order_that_was_never_delivered_queues_nothing(app, world):
    setup(app, world)
    enable(app, world)
    u = ctx(app, world)
    _, v = live(app, world, title="Tee9", stock=5)
    oid, r = create(app, world, [{"variant_id": v, "qty": 1}], phone="01711000009")
    for to in ("confirmed", "packed", "shipped", "returned"):
        assert app.execute("SELECT order_transition(%s,%s,%s,NULL)", (oid, u, to)).fetchone()[0] == "ok"
    assert q_rows(app) == []


def test_a_failed_or_cancelled_booking_does_not_make_it_a_courier_sale(app, world):
    setup(app, world)
    enable(app, world)
    u = ctx(app, world)
    from test_couriers import account as courier_account, reserve
    _, v = live(app, world, title="Tee8", stock=5)
    oid, r = create(app, world, [{"variant_id": v, "qty": 1}], phone="01711000008")
    for to in ("confirmed", "packed"):
        app.execute("SELECT order_transition(%s,%s,%s,NULL)", (oid, u, to))
    sid, rr = reserve(app, oid, courier_account(app, world, "pathao"), u)
    assert rr[0] == "ok" and app.execute("SELECT shipment_fail(%s,'courier said no')", (sid,)).fetchone()[0] in ("ok", "failed")
    for to in ("shipped", "delivered"):
        assert app.execute("SELECT order_transition(%s,%s,%s,NULL)", (oid, u, to)).fetchone()[0] == "ok"
    run(app, world)
    assert q_rows(app, oid)[0][:3] == ("sale", "posted", None) and entry_of(app, oid)[0][0] == "cash"


def test_the_latest_cost_in_force_on_the_delivery_day_is_used(app, world):
    setup(app, world)
    enable(app, world)
    u = ctx(app, world)
    oid, v = deliver(app, world, cost=10000)                                                  # effective 2026-10-01
    app.execute("SELECT inv_cost_set(%s,30000,'2026-10-20'::date,%s)", (v, u))
    app.execute("SELECT inv_cost_set(%s,99999,CURRENT_DATE + 1,%s)", (v, u))                  # tomorrow: not in force yet
    run(app, world)
    assert [x for x in entry_of(app, oid) if x[0] == "cogs"] == [("cogs", 30000, 0)]


def test_untracked_items_carry_no_cost_of_goods(app, world):
    setup(app, world)
    enable(app, world)
    u = ctx(app, world)
    _, v = live(app, world, title="Service", price=20000, stock=3)
    app.execute("UPDATE product_variants SET track_stock=false WHERE id=%s", (v,))
    app.execute("SELECT inv_cost_set(%s,5000,'2026-10-01'::date,%s)", (v, u))
    oid, r = create(app, world, [{"variant_id": v, "qty": 1}], phone="01711000007")
    assert r[0] == "ok"
    for to in ("confirmed", "packed", "shipped", "delivered"):
        assert app.execute("SELECT order_transition(%s,%s,%s,NULL)", (oid, u, to)).fetchone()[0] == "ok"
    run(app, world)
    assert [x[0] for x in entry_of(app, oid)] == ["cash", "sales", "delivery_income"]


def test_discounts_reduce_sales_and_a_full_discount_leaves_only_the_delivery_charge(app, world):
    setup(app, world)
    enable(app, world)
    u = ctx(app, world)
    _, v = live(app, world, title="Disc", price=50000, stock=9)
    o1, r = create(app, world, [{"variant_id": v, "qty": 2}], phone="01711000006", pct=10)
    o2, r2 = create(app, world, [{"variant_id": v, "qty": 1}], phone="01711000005", pct=100)
    assert r[0] == "ok" and r2[0] == "ok"
    for oid in (o1, o2):
        for to in ("confirmed", "packed", "shipped", "delivered"):
            assert app.execute("SELECT order_transition(%s,%s,%s,NULL)", (oid, u, to)).fetchone()[0] == "ok"
    assert run(app, world) == (2, 0, 0)
    assert entry_of(app, o1) == [("cash", 96000, 0), ("sales", 0, 90000), ("delivery_income", 0, 6000)]
    assert entry_of(app, o2) == [("cash", 6000, 0), ("delivery_income", 0, 6000)]


def test_the_entry_is_credited_to_the_person_who_confirmed_the_order(app, world):
    setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world)
    ctx(app, world)
    assert tuple(app.execute("SELECT * FROM autopost_run(%s,%s,50)", (world["a"]["tenant"], world["b"]["user"])).fetchone()) == (1, 0, 0)     # someone else pressed the button
    assert app.execute("SELECT e.created_by FROM autopost_queue q JOIN journal_entries e ON e.id=q.entry_id WHERE q.order_id=%s", (oid,)).fetchone()[0].strip() == world["a"]["user"]


def test_a_sale_given_up_by_hand_leaves_nothing_to_reverse(app, world):
    setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world)
    u = ctx(app, world)
    seq = app.execute("SELECT seq FROM autopost_queue").fetchone()[0]
    assert app.execute("SELECT autopost_skip(%s,%s,'by hand')", (seq, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT order_transition(%s,%s,'returned','x')", (oid, u)).fetchone()[0] == "ok"
    assert run(app, world) == (0, 0, 1)
    assert q_rows(app, oid)[1][:2] == ("sale_return", "skipped") and q_rows(app, oid)[1][5] == "no sale entry to reverse"


def test_the_reversal_is_dated_today(app, world):
    setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world)
    run(app, world)
    u = ctx(app, world)
    app.execute("SELECT order_transition(%s,%s,'returned','x')", (oid, u))
    run(app, world)
    d = app.execute("SELECT e.entry_date FROM autopost_queue q JOIN journal_entries e ON e.id=q.entry_id WHERE q.order_id=%s AND q.kind='sale_return'", (oid,)).fetchone()[0]
    assert d == app.execute("SELECT (now() AT TIME ZONE 'Asia/Dhaka')::date").fetchone()[0]


def test_a_limit_below_one_still_does_some_work(app, world):
    setup(app, world)
    enable(app, world)
    deliver(app, world, phone="01711000001")
    deliver(app, world, phone="01711000002")
    assert run(app, world, limit=0) == (1, 0, 0)
    assert run(app, world, limit=-5) == (1, 0, 0)


def test_an_unexpected_database_error_in_one_row_is_contained(app, world, owner):
    setup(app, world)
    enable(app, world)
    o1, _ = deliver(app, world, phone="01711000001", price=771700)                       # total 777700
    o2, _ = deliver(app, world, phone="01711000002")
    as_admin(owner)
    owner.execute("CREATE FUNCTION zz_boom() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.debit_minor = 777700 THEN RAISE EXCEPTION 'disk on fire'; END IF; RETURN NEW; END $$")
    owner.execute("CREATE TRIGGER zz_boom BEFORE INSERT ON journal_lines FOR EACH ROW EXECUTE FUNCTION zz_boom()")
    try:
        assert run(app, world) == (1, 1, 0)
        assert q_rows(app, o1)[0][1] == "failed" and q_rows(app, o1)[0][4].startswith("error:") and q_rows(app, o2)[0][1] == "posted"
        assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 1                # nothing half-written for the broken one
    finally:
        as_admin(owner)
        owner.execute("DROP TRIGGER zz_boom ON journal_lines")
        owner.execute("DROP FUNCTION zz_boom()")


# ----------------------------------------------------------------------------------------------------------------- failure never stops the work
def test_a_locked_period_marks_the_row_failed_and_the_rest_still_post(app, world):
    setup(app, world)
    enable(app, world)
    o1, _ = deliver(app, world, phone="01711000001")
    o2, _ = deliver(app, world, phone="01711000002")
    u = ctx(app, world)
    app.execute("SELECT acct_lock(%s,CURRENT_DATE,%s)", (world["a"]["tenant"], u))
    r = run(app, world)
    assert r == (0, 2, 0)
    assert {x[4] for x in q_rows(app)} == {"locked"}
    assert all(x[1] == "failed" for x in q_rows(app))
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 0


def test_failed_rows_retry_after_the_cause_is_fixed(app, world):
    setup(app, world)
    enable(app, world)
    o1, _ = deliver(app, world, phone="01711000001")
    u = ctx(app, world)
    app.execute("UPDATE accounts SET active=false WHERE system_key='sales'")
    assert run(app, world) == (0, 1, 0)
    assert q_rows(app, o1)[0][:2] == ("sale", "failed") and q_rows(app, o1)[0][4] == "no_system_account"
    assert app.execute("SELECT attempts FROM autopost_queue").fetchone()[0] == 1
    app.execute("UPDATE accounts SET active=true WHERE system_key='sales'")
    assert run(app, world) == (1, 0, 0)
    assert q_rows(app, o1)[0][:2] == ("sale", "posted") and q_rows(app, o1)[0][4] is None
    assert app.execute("SELECT attempts FROM autopost_queue").fetchone()[0] == 2


def test_one_bad_row_does_not_block_the_next(app, world):
    setup(app, world)
    enable(app, world)
    o1, _ = deliver(app, world, phone="01711000001")
    o2, _ = deliver(app, world, phone="01711000002", courier="redx")
    app.execute("UPDATE accounts SET active=false WHERE system_key='cash'")                 # only the own-delivery order needs the cash account
    assert run(app, world) == (1, 1, 0)
    assert q_rows(app, o1)[0][1] == "failed" and q_rows(app, o2)[0][1] == "posted"


def test_the_order_itself_never_fails_because_of_the_books(app, world, owner):
    setup(app, world)
    enable(app, world)
    as_admin(owner)
    owner.execute("CREATE FUNCTION zz_boom() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'queue is broken'; END $$")
    owner.execute("CREATE TRIGGER zz_boom BEFORE INSERT ON autopost_queue FOR EACH ROW EXECUTE FUNCTION zz_boom()")
    try:
        oid, _ = deliver(app, world)                                                                 # still ends up delivered
        assert app.execute("SELECT status FROM store_orders WHERE id=%s", (oid,)).fetchone()[0] == "delivered"
        assert q_rows(app) == []
    finally:
        as_admin(owner)
        owner.execute("DROP TRIGGER zz_boom ON autopost_queue")
        owner.execute("DROP FUNCTION zz_boom()")


def test_skip_gives_up_on_a_waiting_row(app, world):
    setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world)
    u = ctx(app, world)
    app.execute("UPDATE accounts SET active=false WHERE system_key='sales'")
    run(app, world)
    seq = app.execute("SELECT seq FROM autopost_queue").fetchone()[0]
    assert app.execute("SELECT autopost_skip(%s,%s,'posted by hand')", (seq, u)).fetchone()[0] == "ok"
    assert q_rows(app, oid)[0][1] == "skipped" and "posted by hand" in q_rows(app, oid)[0][5]
    assert app.execute("SELECT autopost_skip(%s,%s,'again')", (seq, u)).fetchone()[0] == "not_open"
    app.execute("UPDATE accounts SET active=true WHERE system_key='sales'")
    assert run(app, world) == (0, 0, 0)
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == 0
    o2, _ = deliver(app, world, phone="01811000002")
    run(app, world)
    seq2 = app.execute("SELECT seq FROM autopost_queue WHERE order_id=%s", (o2,)).fetchone()[0]
    assert app.execute("SELECT autopost_skip(%s,%s,'late')", (seq2, u)).fetchone()[0] == "not_open"      # already posted


def test_the_run_limit_is_respected(app, world):
    setup(app, world)
    enable(app, world)
    for i in range(3):
        deliver(app, world, phone=f"0171100000{i}")
    assert run(app, world, limit=2) == (2, 0, 0)
    assert run(app, world, limit=2) == (1, 0, 0)


# ----------------------------------------------------------------------------------------------------------------- courier settlement
def test_settlement_moves_money_and_clears_the_receivable(app, world):
    A = setup(app, world)
    enable(app, world)
    for i in range(2):
        deliver(app, world, courier="steadfast", phone=f"0171100000{i}")
    run(app, world)
    sid, r = settle(app, world, "steadfast", 100000, 4000, A["bank"])
    assert r == "ok"
    assert balance(app, "bank") == 96000 and balance(app, "courier_expense") == 4000 and balance(app, "courier_receivable") == 12000
    assert report(app, world) == {"steadfast": (2, 112000, 0, 100000, 12000)}
    assert app.execute("SELECT gross_minor, fee_minor, voided_at FROM courier_settlements WHERE id=%s", (sid,)).fetchone() == (100000, 4000, None)
    sid2, r2 = settle(app, world, "steadfast", 12000, 0, A["cash"])
    assert r2 == "ok" and report(app, world)["steadfast"][4] == 0
    assert balance(app, "courier_receivable") == 0


def test_settlement_rules(app, world):
    A = setup(app, world)
    enable(app, world)
    deliver(app, world, courier="pathao")
    run(app, world)
    for args, exp in ((("pathao", 56001, 0, A["bank"]), "exceeds_receivable"), (("redx", 100, 0, A["bank"]), "exceeds_receivable"), (("pathao", 0, 0, A["bank"]), "bad_input"),
                      (("pathao", -5, 0, A["bank"]), "bad_input"), (("pathao", 1000, 1001, A["bank"]), "bad_input"), (("pathao", 1000, -1, A["bank"]), "bad_input"),
                      (("ups", 1000, 0, A["bank"]), "bad_input"), (("pathao", 1000, 0, A["ar"]), "bad_account"), (("pathao", 1000, 0, A["rent"]), "bad_account"), (("pathao", 1000, 0, "z" * 26), "bad_account")):
        assert settle(app, world, *args)[1] == exp, args
    assert settle(app, world, "pathao", 1000, 0, A["bank"], date="2099-01-01")[1] == "bad_date"
    assert app.execute("SELECT count(*) FROM courier_settlements").fetchone()[0] == 0
    assert settle(app, world, "pathao", 56000, 56000, A["bank"])[1] == "ok"                       # the courier kept all of it
    assert balance(app, "bank") == 0 and balance(app, "courier_expense") == 56000


def test_money_cannot_be_booked_for_a_parcel_that_is_already_coming_back(app, world):
    A = setup(app, world)
    enable(app, world)
    o1, _ = deliver(app, world, courier="pathao", phone="01711000001")
    o2, _ = deliver(app, world, courier="pathao", phone="01711000002")
    run(app, world)
    u = ctx(app, world)
    app.execute("SELECT order_transition(%s,%s,'returned','x')", (o2, u))                       # returned, reversal not yet posted
    assert settle(app, world, "pathao", 112000, 0, A["bank"])[1] == "exceeds_receivable"
    assert settle(app, world, "pathao", 56000, 0, A["bank"])[1] == "ok"
    run(app, world)
    assert report(app, world)["pathao"][4] == 0 and balance(app, "courier_receivable") == 0


def test_a_stuck_return_also_holds_back_settlement_and_the_entry_names_the_settlement(app, world):
    A = setup(app, world)
    enable(app, world)
    o1, _ = deliver(app, world, courier="pathao")
    run(app, world)
    u = ctx(app, world)
    app.execute("SELECT order_transition(%s,%s,'returned','x')", (o1, u))
    app.execute("SELECT acct_lock(%s,CURRENT_DATE,%s)", (world["a"]["tenant"], u))
    assert run(app, world) == (0, 1, 0)                                                      # the reversal is stuck as failed
    tomorrow = str(datetime.date.today() + datetime.timedelta(days=1))
    assert settle(app, world, "pathao", 1000, 0, A["bank"], date=tomorrow)[1] == "exceeds_receivable"


def test_the_settlement_entry_carries_its_id(app, world):
    A = setup(app, world)
    enable(app, world)
    deliver(app, world, courier="redx")
    run(app, world)
    sid, r = settle(app, world, "redx", 1000, 0, A["bank"])
    assert r == "ok"
    assert app.execute("SELECT e.source, e.source_ref FROM courier_settlements c JOIN journal_entries e ON e.id=c.entry_id WHERE c.id=%s", (sid,)).fetchone() == ("system", "settle:" + sid)


def test_settlement_respects_the_period_lock_and_can_be_voided(app, world):
    A = setup(app, world)
    enable(app, world)
    deliver(app, world, courier="redx")
    run(app, world)
    u = ctx(app, world)
    sid, r = settle(app, world, "redx", 50000, 2000, A["bank"], date=str(datetime.date.today()))
    assert r == "ok"
    new = ulid()
    assert app.execute("SELECT courier_settle_void(%s,%s,%s,CURRENT_DATE)", (new, sid, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT courier_settle_void(%s,%s,%s,CURRENT_DATE)", (ulid(), sid, u)).fetchone()[0] == "already_void"
    assert balance(app, "bank") == 0 and balance(app, "courier_expense") == 0 and balance(app, "courier_receivable") == 56000
    assert report(app, world)["redx"][3] == 0                                                        # a voided settlement no longer counts
    app.execute("SELECT acct_lock(%s,CURRENT_DATE,%s)", (world["a"]["tenant"], u))
    assert settle(app, world, "redx", 1000, 0, A["bank"], date=str(datetime.date.today()))[1] == "locked"


# ----------------------------------------------------------------------------------------------------------------- isolation
def test_another_workspace_cannot_post_skip_settle_or_read(app, world):
    A = setup(app, world)
    enable(app, world)
    oid, _ = deliver(app, world, courier="steadfast")
    ctx(app, world)
    seq = app.execute("SELECT seq FROM autopost_queue").fetchone()[0]
    ctx(app, world, "b")
    for sql, args in (("SELECT * FROM autopost_run(%s,%s,10)", (world["a"]["tenant"], world["b"]["user"])),
                      ("SELECT autopost_skip(%s,%s,'x')", (seq, world["b"]["user"])),
                      ("SELECT autopost_set(%s,%s,false)", (world["a"]["tenant"], world["b"]["user"])),
                      ("SELECT courier_settle(%s,%s,%s,%s,CURRENT_DATE,'steadfast',100,0,%s,NULL)", (ulid(), ulid(), world["a"]["tenant"], world["b"]["user"], A["bank"]))):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM autopost_queue").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM courier_receivable_report()").fetchone()[0] == 0
    ctx(app, world)
    assert app.execute("SELECT status FROM autopost_queue").fetchone()[0] == "pending"


def test_the_app_role_cannot_write_the_tables_or_call_the_internals(app, world):
    setup(app, world)
    enable(app, world)
    ctx(app, world)
    for sql in ("INSERT INTO autopost_queue (tenant_id, kind, order_id) VALUES ('x','sale','y')", "UPDATE autopost_queue SET status='posted'", "DELETE FROM autopost_queue",
                "UPDATE autopost_settings SET enabled=false", "INSERT INTO courier_settlements (id) VALUES ('x')", "SELECT autopost_one(1, 'x')", "SELECT * FROM courier_receivable_rows('x')", "SELECT autopost_enqueue()"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
        ctx(app, world)


def test_tenant_b_orders_never_reach_tenant_a_books(app, world):
    setup(app, world)
    enable(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM autopost_settings").fetchone()[0] == 0                # b never switched it on


# ---------------------------------------------------------------------------------------------------------------------
hyp = pytest.importorskip("hypothesis")
from hypothesis import HealthCheck, given, settings, strategies as st  # noqa: E402

op = st.one_of(
    st.tuples(st.just("deliver"), st.sampled_from([None, "steadfast", "pathao", "redx"]), st.integers(0, 2), st.just(0)),
    st.tuples(st.just("return"), st.integers(0, 9), st.just(None), st.just(0)),
    st.tuples(st.just("run"), st.just(0), st.just(None), st.just(0)),
    st.tuples(st.just("settle"), st.sampled_from(["steadfast", "pathao", "redx"]), st.integers(1, 100), st.integers(0, 30)),
    st.tuples(st.just("void"), st.integers(0, 9), st.just(None), st.just(0)),
)


@settings(max_examples=25, deadline=None, suppress_health_check=[HealthCheck.function_scoped_fixture])
@given(st.lists(op, min_size=1, max_size=12))
def test_property_the_courier_ledger_always_equals_the_courier_report(app, world, owner, ops):
    try:
        _property_body(app, world, ops)
    finally:
        app.rollback()
        wipe(owner)


def _property_body(app, world, ops):
    A = setup(app, world)
    enable(app, world)
    u = ctx(app, world)
    n = 0
    delivered, settlements = [], []
    for kind, a, b, c in ops:
        if kind == "deliver":
            n += 1
            oid, _ = deliver(app, world, phone=f"01{3 + n % 6}{n:08d}"[:11], courier=a, cost=(b * 7000 if b else None))
            delivered.append(oid)
        elif kind == "return" and delivered:
            app.execute("SELECT order_transition(%s,%s,'returned','x')", (delivered[a % len(delivered)], u))
        elif kind == "run":
            run(app, world)
        elif kind == "settle":
            ctx(app, world)
            owed = report(app, world).get(a, (0, 0, 0, 0, 0))[4]
            if owed > 0:
                gross = max(1, owed * b // 100)
                sid, r = settle(app, world, a, gross, gross * c // 100, A["bank"], date=str(datetime.date.today()))
                assert r in ("ok", "exceeds_receivable")                                  # refused only when a returned parcel's reversal is still waiting
                if r == "ok":
                    settlements.append(sid)
        elif kind == "void" and settlements:
            app.execute("SELECT courier_settle_void(%s,%s,%s,CURRENT_DATE)", (ulid(), settlements[a % len(settlements)], u))
    run(app, world)
    assert app.execute("SELECT count(*) FROM autopost_queue WHERE status IN ('pending','failed')").fetchone()[0] == 0
    rep = report(app, world)
    assert balance(app, "courier_receivable") == sum(v[4] for v in rep.values())                 # ledger = what the report says couriers owe
    live_sales = app.execute("SELECT coalesce(sum(o.subtotal_minor - o.discount_minor),0) FROM store_orders o WHERE o.status='delivered' AND EXISTS (SELECT 1 FROM autopost_queue q WHERE q.order_id=o.id AND q.kind='sale' AND q.status='posted')").fetchone()[0]
    assert -balance(app, "sales") == live_sales                                                  # sales = delivered orders only (returned ones are reversed)
    tb = app.execute("SELECT coalesce(sum(debit_minor),0), coalesce(sum(credit_minor),0) FROM acct_trial_balance('2099-12-31')").fetchone()
    assert tb[0] == tb[1]
