import json

import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_catalog import ctx, go_live, product

ADDR = "House 12, Road 5, Dhanmondi, Dhaka 1209"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for trg in ("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change", "ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_no_change", "ALTER TABLE product_events DISABLE TRIGGER product_events_no_change",
                "ALTER TABLE store_order_events DISABLE TRIGGER store_order_events_no_change", "ALTER TABLE store_order_items DISABLE TRIGGER store_order_items_no_change"):
        owner.execute(trg)
    for t in ("store_order_events", "store_order_items", "store_orders", "order_settings", "stock_movements", "product_events", "product_variants", "products",
              "store_connections", "emergency_events"):
        owner.execute(f"DELETE FROM {t}") if t != "emergency_events" else None
    for trg in ("ALTER TABLE stock_movements ENABLE TRIGGER stock_movements_no_change", "ALTER TABLE product_events ENABLE TRIGGER product_events_no_change",
                "ALTER TABLE store_order_events ENABLE TRIGGER store_order_events_no_change", "ALTER TABLE store_order_items ENABLE TRIGGER store_order_items_no_change"):
        owner.execute(trg)


def live(app, world, title="Tee", price=50000, stock=10):
    p, v = product(app, world, title=title, price=price, stock=stock)
    go_live(app, world, p)
    return p, v.strip() if isinstance(v, str) else v


def create(app, world, items, *, source="staff", phone="01711223344", pct=0, zone="inside", name="রহিম", addr=ADDR, conv=None, user=True):
    t = world["a"]["tenant"]
    oid = ulid()
    r = app.execute("SELECT * FROM order_create(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s::jsonb,%s::numeric)",
                    (oid, t, world["a"]["user"] if user else None, source, conv, name, phone, addr, zone, None, json.dumps(items), pct)).fetchone()
    return oid, r


def stock(app, v):
    return app.execute("SELECT stock_qty FROM product_variants WHERE id=%s", (v,)).fetchone()[0]


def test_price_comes_from_the_live_copy_and_totals_add_up(app, world):
    ctx(app, world)
    p, v = live(app, world, price=50000, stock=10)
    oid, r = create(app, world, [{"variant_id": v, "qty": 2, "price_minor": 1}])         # a forged price is simply ignored
    assert r[0] == "ok" and r[3] == 2 * 50000 + 6000
    o = app.execute("SELECT subtotal_minor, discount_minor, delivery_minor, total_minor, status, phone, number FROM store_orders WHERE id=%s", (oid,)).fetchone()
    assert o == (100000, 0, 6000, 106000, "pending", "+8801711223344", 1)
    assert app.execute("SELECT unit_price_minor, qty, line_total_minor, tracked FROM store_order_items WHERE order_id=%s", (oid,)).fetchall() == [(50000, 2, 100000, True)]
    assert stock(app, v) == 8
    assert app.execute("SELECT delta, reason FROM stock_movements WHERE variant_id=%s ORDER BY seq DESC LIMIT 1", (v,)).fetchone() == (-2, "sale")
    _, r2 = create(app, world, [{"variant_id": v, "qty": 1}], phone="01811223344")
    assert r2[0] == "ok" and app.execute("SELECT number FROM store_orders WHERE id=%s", (r2[1],)).fetchone()[0] == 2


def test_editing_the_draft_price_does_not_change_what_customers_pay(app, world):
    ctx(app, world)
    p, v = live(app, world, price=50000)
    app.execute("UPDATE products SET price_minor=1 WHERE id=%s", (p,))                    # draft edit; live copy untouched
    _, r = create(app, world, [{"variant_id": v, "qty": 1}])
    assert r[0] == "ok" and r[3] == 56000


def test_unpublished_archived_foreign_and_malformed_items_are_refused(app, world):
    ctx(app, world)
    p, v = product(app, world, stock=5)                                                      # never published
    assert create(app, world, [{"variant_id": v, "qty": 1}])[1][0] == "unavailable"
    p2, v2 = live(app, world, title="Live", stock=5)
    assert create(app, world, [{"variant_id": "x" * 26, "qty": 1}])[1][0] == "unavailable"
    assert create(app, world, [{"variant_id": v2, "qty": 0}])[1][0] == "bad_item"
    assert create(app, world, [{"variant_id": v2, "qty": "abc"}])[1][0] == "bad_item"
    assert create(app, world, [])[1][0] == "no_items"
    assert create(app, world, {"variant_id": v2})[1][0] == "no_items"
    assert stock(app, v2) == 5 and app.execute("SELECT count(*) FROM store_orders").fetchone()[0] == 0
    set_tenant(app, world["b"]["tenant"])
    oid = ulid()
    r = app.execute("SELECT result FROM order_create(%s,%s,%s,'staff',NULL,'x y',%s,%s,'inside',NULL,%s::jsonb,0)",
                    (oid, world["b"]["tenant"], world["b"]["user"], "01711223344", ADDR, json.dumps([{"variant_id": v2, "qty": 1}]))).fetchone()
    assert r[0] == "unavailable"                                                            # another workspace's variant is invisible


def test_oversell_is_impossible_and_failure_undoes_every_hold(app, world):
    ctx(app, world)
    _, a = live(app, world, title="A", stock=5)
    _, b = live(app, world, title="B", stock=1)
    _, r = create(app, world, [{"variant_id": a, "qty": 3}, {"variant_id": b, "qty": 2}])
    assert r[0] == "insufficient_stock"
    assert stock(app, a) == 5 and stock(app, b) == 1                                          # A's hold was rolled back with the failed order
    assert app.execute("SELECT count(*) FROM store_orders").fetchone()[0] == 0
    assert create(app, world, [{"variant_id": a, "qty": 5}])[1][0] == "ok" and stock(app, a) == 0
    assert create(app, world, [{"variant_id": a, "qty": 1}], phone="01911223344")[1][0] == "insufficient_stock"


def test_same_variant_twice_is_merged_and_untracked_stock_is_not_held(app, world):
    ctx(app, world)
    _, v = live(app, world, stock=10)
    _, r = create(app, world, [{"variant_id": v, "qty": 1}, {"variant_id": v, "qty": 2}])
    assert r[0] == "ok" and stock(app, v) == 7
    p, v2 = live(app, world, title="Service", stock=None)
    app.execute("UPDATE product_variants SET track_stock=false WHERE id=%s", (v2,))
    _, r2 = create(app, world, [{"variant_id": v2, "qty": 50}], phone="01611223344")
    assert r2[0] == "ok" and app.execute("SELECT tracked FROM store_order_items WHERE variant_id=%s", (v2,)).fetchone()[0] is False


def test_discount_limit_binds_the_agent_but_not_a_person(app, world):
    t = ctx(app, world)
    _, v = live(app, world, price=100000, stock=20)
    assert create(app, world, [{"variant_id": v, "qty": 1}], source="agent", pct=5, user=False)[1][0] == "discount_too_high"      # limit is 0 by default
    app.execute("INSERT INTO order_settings (tenant_id, max_discount_pct) VALUES (%s, 10)", (world["a"]["tenant"],))
    oid, r = create(app, world, [{"variant_id": v, "qty": 1}], source="agent", pct=10, user=False)
    assert r[0] == "ok" and app.execute("SELECT discount_minor, total_minor, source FROM store_orders WHERE id=%s", (oid,)).fetchone() == (10000, 96000, "agent")
    assert create(app, world, [{"variant_id": v, "qty": 1}], source="agent", pct=10.5, user=False, phone="01811223344")[1][0] == "discount_too_high"
    assert create(app, world, [{"variant_id": v, "qty": 1}], source="staff", pct=30, phone="01911223344")[1][0] == "ok"
    assert create(app, world, [{"variant_id": v, "qty": 1}], source="staff", pct=101, phone="01611223344")[1][0] == "discount_too_high"
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE order_settings SET tenant_id=tenant_id WHERE true")


def test_bad_customer_details_are_refused(app, world):
    ctx(app, world)
    _, v = live(app, world, stock=9)
    it = [{"variant_id": v, "qty": 1}]
    assert create(app, world, it, phone="12345")[1][0] == "bad_phone"
    assert create(app, world, it, phone="01011223344")[1][0] == "bad_phone"
    assert create(app, world, it, name="  ")[1][0] == "bad_name"
    assert create(app, world, it, addr="Dhaka")[1][0] == "bad_address"
    assert create(app, world, it, zone="mars")[1][0] == "bad_zone"
    for ph in ("+8801711223344", "8801711223344", "01711223344", "১১৭১১২২৩৩৪৪"):
        pass
    assert app.execute("SELECT order_phone_norm(%s), order_phone_norm(%s), order_phone_norm(%s), order_phone_norm(%s)", ("+880 1711-223344", "১৭১১২২৩৩৪৪", "01711223344", "017")).fetchone() == ("+8801711223344", "+8801711223344", "+8801711223344", None)
    assert app.execute("SELECT count(*) FROM store_orders").fetchone()[0] == 0 and stock(app, v) == 9


def test_agent_order_without_user_and_staff_order_without_user(app, world):
    ctx(app, world)
    _, v = live(app, world, stock=9)
    with pytest.raises(psycopg.errors.RaiseException):
        create(app, world, [{"variant_id": v, "qty": 1}], source="staff", user=False)


def test_risk_score_rules(app, world):
    u = ctx(app, world)
    _, v = live(app, world, price=10000, stock=100)
    it = [{"variant_id": v, "qty": 1}]
    oid, r = create(app, world, it, phone="01733333333", addr="short addr 1")
    assert set(app.execute("SELECT risk_reasons FROM store_orders WHERE id=%s", (oid,)).fetchone()[0]) == {"fake_phone", "short_address"} and r[4] == 35 and r[5] is False
    _, r = create(app, world, it, phone="01733333333", addr="short addr 1")
    assert "dup_open" in app.execute("SELECT risk_reasons FROM store_orders ORDER BY number DESC LIMIT 1").fetchone()[0] and r[4] == 55 and r[5] is True      # >= 50 -> review
    oid3, r3 = create(app, world, [{"variant_id": v, "qty": 12}], phone="01712121212", addr=ADDR)
    assert r3[4] == 15 and "big_qty" in app.execute("SELECT risk_reasons FROM store_orders WHERE id=%s", (oid3,)).fetchone()[0]
    # a customer who cancelled twice is risky; one who received an order is trusted
    for i in range(2):
        o, _r = create(app, world, it, phone="01759182736", addr=ADDR)
        assert app.execute("SELECT order_transition(%s,%s,'cancelled','x')", (o, u)).fetchone()[0] == "ok"
    _, rb = create(app, world, it, phone="01759182736", addr=ADDR)
    assert rb[4] == 40
    oid_g, _ = create(app, world, it, phone="01764829315", addr=ADDR)
    for to in ("confirmed", "packed", "shipped", "delivered"):
        assert app.execute("SELECT order_transition(%s,%s,%s,NULL)", (oid_g, u, to)).fetchone()[0] == "ok"
    oid_h, _ = create(app, world, it, phone="01764829315", addr="short addr 1")
    sc, rs = app.execute("SELECT risk_score, risk_reasons FROM store_orders WHERE id=%s", (oid_h,)).fetchone()
    assert sc == 0 and "short_address" in rs and "trusted" in rs


def test_too_many_open_orders_from_one_phone(app, world):
    ctx(app, world)
    _, v = live(app, world, stock=50)
    for _ in range(3):
        assert create(app, world, [{"variant_id": v, "qty": 1}], phone="01788888881")[1][0] == "ok"
    assert create(app, world, [{"variant_id": v, "qty": 1}], phone="01788888881")[1][0] == "too_many_open"


def test_status_path_is_fixed_logged_and_stock_returns_exactly_once(app, world):
    u = ctx(app, world)
    _, v = live(app, world, stock=10)
    oid, _ = create(app, world, [{"variant_id": v, "qty": 4}])
    tr = lambda to, user=u: app.execute("SELECT order_transition(%s,%s,%s,'note')", (oid, user, to)).fetchone()[0]
    assert tr("shipped") == "bad_transition" and tr("delivered") == "bad_transition" and tr("pending") == "bad_transition"
    assert tr("confirmed") == "ok" and tr("confirmed") == "bad_transition"
    assert app.execute("SELECT confirmed_by, confirmed_at IS NOT NULL FROM store_orders WHERE id=%s", (oid,)).fetchone() == (u, True)
    assert tr("cancelled") == "ok" and stock(app, v) == 10
    assert tr("cancelled") == "bad_transition" and tr("confirmed") == "bad_transition" and tr("returned") == "bad_transition" and stock(app, v) == 10   # final, no second return
    assert app.execute("SELECT to_status FROM store_order_events WHERE order_id=%s ORDER BY seq", (oid,)).fetchall() == [("pending",), ("confirmed",), ("cancelled",)]
    o2, _ = create(app, world, [{"variant_id": v, "qty": 3}], phone="01811111112")
    for to in ("confirmed", "packed", "shipped", "returned"):
        assert app.execute("SELECT order_transition(%s,%s,%s,NULL)", (o2, u, to)).fetchone()[0] == "ok"
    assert stock(app, v) == 10
    assert app.execute("SELECT order_transition(%s,NULL,'confirmed',NULL)", (o2,)).fetchone()[0] == "user_required"


def test_emergency_stop_blocks_new_orders_and_confirmation(app, world, owner):
    u = ctx(app, world)
    _, v = live(app, world, stock=10)
    oid, _ = create(app, world, [{"variant_id": v, "qty": 1}])
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user, reason) VALUES (%s,'stop',%s,'t')", (world["a"]["tenant"], u))
    try:
        ctx(app, world)
        assert create(app, world, [{"variant_id": v, "qty": 1}], phone="01899999991")[1][0] == "emergency"
        assert app.execute("SELECT order_transition(%s,%s,'confirmed',NULL)", (oid, u)).fetchone()[0] == "emergency"
        assert app.execute("SELECT order_transition(%s,%s,'cancelled',NULL)", (oid, u)).fetchone()[0] == "ok"      # cancelling is always allowed
    finally:
        owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
        owner.execute("DELETE FROM emergency_events")
        owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")


def test_orders_are_isolated_and_cannot_be_written_directly(app, world):
    u = ctx(app, world)
    _, v = live(app, world, stock=10)
    oid, _ = create(app, world, [{"variant_id": v, "qty": 1}])
    for sql in ("UPDATE store_orders SET total_minor=1", "DELETE FROM store_orders", "INSERT INTO store_order_items (tenant_id) VALUES ('x')", "UPDATE store_order_items SET qty=9", "DELETE FROM store_order_events"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
    set_tenant(app, world["b"]["tenant"])
    assert app.execute("SELECT count(*) FROM store_orders").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM store_order_items").fetchone()[0] == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT order_transition(%s,%s,'cancelled',NULL)", (oid, world["b"]["user"]))


def test_conversation_must_be_in_this_workspace(app, world):
    ctx(app, world)
    _, v = live(app, world, stock=10)
    assert create(app, world, [{"variant_id": v, "qty": 1}], conv=ulid())[1][0] == "conversation_not_found"
