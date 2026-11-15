import json

import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_catalog import ctx
from test_orders import create, live, stock


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for trg in ("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change", "ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_no_change", "ALTER TABLE product_events DISABLE TRIGGER product_events_no_change",
                "ALTER TABLE store_order_events DISABLE TRIGGER store_order_events_no_change", "ALTER TABLE store_order_items DISABLE TRIGGER store_order_items_no_change",
                "ALTER TABLE order_shipment_events DISABLE TRIGGER order_shipment_events_no_change"):
        owner.execute(trg)
    for t in ("order_reports", "order_shipment_events", "order_shipments", "courier_accounts", "store_order_events", "store_order_items", "store_orders", "order_settings", "stock_movements", "product_events",
              "product_variants", "products", "store_connections"):
        owner.execute(f"DELETE FROM {t}")
    for trg in ("ALTER TABLE stock_movements ENABLE TRIGGER stock_movements_no_change", "ALTER TABLE product_events ENABLE TRIGGER product_events_no_change",
                "ALTER TABLE store_order_events ENABLE TRIGGER store_order_events_no_change", "ALTER TABLE store_order_items ENABLE TRIGGER store_order_items_no_change",
                "ALTER TABLE order_shipment_events ENABLE TRIGGER order_shipment_events_no_change"):
        owner.execute(trg)


def account(app, world, courier="steadfast", who="a", settings=None):
    aid = ulid()
    got = app.execute("SELECT courier_account_save(%s,%s,%s,%s,%s,%s::jsonb)", (aid, world[who]["tenant"], courier, "sealed-blob", 1, json.dumps(settings or {}))).fetchone()[0]
    return got.strip()


def confirmed_order(app, world, phone="01711223344", status="confirmed"):
    u = ctx(app, world)
    _, v = live(app, world, title="Tee" + phone, stock=10)
    oid, r = create(app, world, [{"variant_id": v, "qty": 1}], phone=phone)
    assert r[0] == "ok"
    for to in ("confirmed", "packed")[: 1 if status == "confirmed" else 2]:
        assert app.execute("SELECT order_transition(%s,%s,%s,NULL)", (oid, u, to)).fetchone()[0] == "ok"
    return oid, v, u


def reserve(app, oid, acc, u):
    sid = ulid()
    r = app.execute("SELECT * FROM shipment_reserve(%s,%s,%s,%s)", (sid, oid, acc, u)).fetchone()
    return sid, r


def order_status(app, oid):
    return app.execute("SELECT status FROM store_orders WHERE id=%s", (oid,)).fetchone()[0]


def book(app, world, phone="01711223344", courier="steadfast", status="confirmed"):
    oid, v, u = confirmed_order(app, world, phone, status)
    acc = account(app, world, courier)
    sid, r = reserve(app, oid, acc, u)
    assert r[0] == "ok"
    assert app.execute("SELECT shipment_booked(%s,%s,%s,%s)", (sid, "C-" + sid, "TRK-1", "pending")).fetchone()[0] == "ok"
    return oid, v, u, sid


def test_secrets_are_not_readable_by_the_app_role(app, world):
    ctx(app, world)
    acc = account(app, world)
    assert [(r[0].strip(), r[1], r[2]) for r in app.execute("SELECT id, courier, status FROM courier_accounts").fetchall()] == [(acc, "steadfast", "active")]
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT credentials_ct FROM courier_accounts")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE courier_accounts SET status='disabled'")
    assert app.execute("SELECT credentials_ct, key_version, courier FROM courier_credentials(%s)", (acc,)).fetchone() == ("sealed-blob", 1, "steadfast")


def test_account_save_is_one_per_courier_and_validates_settings(app, world):
    ctx(app, world)
    a1 = account(app, world, "pathao", settings={"store_id": "123", "sandbox": True})
    a2 = account(app, world, "pathao", settings={"store_id": "456"})
    assert a1 == a2                                                    # saving again replaces, never duplicates
    assert app.execute("SELECT settings->>'store_id' FROM courier_accounts WHERE id=%s", (a1,)).fetchone()[0] == "456"
    for bad in ({"base_url": "http://evil"}, {"store_id": "12x"}, {"sandbox": "yes"}):
        with pytest.raises(psycopg.errors.InvalidParameterValue):
            account(app, world, "redx", settings=bad)
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        account(app, world, "dhl")


def test_booking_moves_a_confirmed_order_to_shipped_through_packed(app, world):
    oid, v, u, sid = book(app, world)
    assert order_status(app, oid) == "shipped"
    assert [r[0] for r in app.execute("SELECT to_status FROM store_order_events WHERE order_id=%s ORDER BY seq", (oid,)).fetchall()] == ["pending", "confirmed", "packed", "shipped"]
    s = app.execute("SELECT status, consignment_id, tracking_code, cod_minor, courier FROM order_shipments WHERE id=%s", (sid,)).fetchone()
    assert s == ("booked", "C-" + sid, "TRK-1", 56000, "steadfast")        # COD = the order total, taken by the database


def test_packed_order_can_be_booked_too(app, world):
    oid, *_ = book(app, world, status="packed")
    assert order_status(app, oid) == "shipped"


def test_only_confirmed_or_packed_orders_and_one_live_shipment(app, world):
    u = ctx(app, world)
    _, v = live(app, world, stock=10)
    oid, _ = create(app, world, [{"variant_id": v, "qty": 1}])
    acc = account(app, world)
    assert reserve(app, oid, acc, u)[1][0] == "bad_status"              # still pending
    app.execute("SELECT order_transition(%s,%s,'confirmed',NULL)", (oid, u))
    sid, r = reserve(app, oid, acc, u)
    assert r[0] == "ok"
    assert reserve(app, oid, acc, u)[1][0] == "already_booked"          # a second click while the first is in flight
    assert app.execute("SELECT shipment_fail(%s,'courier down')", (sid,)).fetchone()[0] == "ok"
    assert order_status(app, oid) == "confirmed"                        # a failed booking changes nothing about the order
    sid2, r2 = reserve(app, oid, acc, u)
    assert r2[0] == "ok"                                                # …and the order can be tried again


def test_booked_or_failed_steps_only_work_on_a_booking_row(app, world):
    oid, v, u, sid = book(app, world)
    assert app.execute("SELECT shipment_booked(%s,'C-2','T','x')", (sid,)).fetchone()[0] == "not_booking"
    assert app.execute("SELECT shipment_fail(%s,'x')", (sid,)).fetchone()[0] == "not_booking"
    assert app.execute("SELECT consignment_id FROM order_shipments WHERE id=%s", (sid,)).fetchone()[0] == "C-" + sid


def test_disabled_account_and_foreign_account(app, world):
    oid, v, u = confirmed_order(app, world)
    acc = account(app, world)
    app.execute("SELECT courier_account_status(%s,'disabled')", (acc,))
    assert reserve(app, oid, acc, u)[1][0] == "account_disabled"
    set_tenant(app, world["b"]["tenant"])
    other = account(app, world, "redx", who="b")
    ctx(app, world)
    assert reserve(app, oid, other, u)[1][0] == "no_account"            # tenant B's account cannot ship tenant A's order


def test_delivered_status_moves_the_order_and_is_idempotent(app, world):
    oid, v, u, sid = book(app, world)
    assert app.execute("SELECT shipment_apply(%s,'in_transit','in_review')", (sid,)).fetchone()[0] == "changed"
    assert app.execute("SELECT shipment_apply(%s,'in_transit','in_review')", (sid,)).fetchone()[0] == "same"
    assert app.execute("SELECT shipment_apply(%s,'delivered','delivered')", (sid,)).fetchone()[0] == "changed"
    assert order_status(app, oid) == "delivered"
    assert app.execute("SELECT shipment_apply(%s,'delivered','delivered')", (sid,)).fetchone()[0] == "terminal"
    assert app.execute("SELECT shipment_apply(%s,'returned','return')", (sid,)).fetchone()[0] == "terminal"      # a finished parcel never moves again
    assert order_status(app, oid) == "delivered"
    ev = app.execute("SELECT status FROM order_shipment_events WHERE shipment_id=%s ORDER BY seq", (sid,)).fetchall()
    assert [e[0] for e in ev] == ["booking", "booked", "in_transit", "delivered"]


def test_returned_and_courier_cancelled_restore_stock_once(app, world):
    for i, st in enumerate(("returned", "cancelled")):
        oid, v, u, sid = book(app, world, phone=f"0172233440{i}")
        before = stock(app, v)
        assert app.execute("SELECT shipment_apply(%s,%s,'x')", (sid, st)).fetchone()[0] == "changed"
        assert order_status(app, oid) == "returned"
        assert stock(app, v) == before + 1
        assert app.execute("SELECT shipment_apply(%s,%s,'x')", (sid, st)).fetchone()[0] == "terminal"
        assert stock(app, v) == before + 1                                                                   # not twice


def test_courier_cancelled_shipment_returns_the_order_so_it_cannot_be_shipped_again(app, world):
    oid, v, u, sid = book(app, world)
    app.execute("SELECT shipment_apply(%s,'cancelled','cancelled')", (sid,))
    assert app.execute("SELECT status FROM order_shipments WHERE id=%s", (sid,)).fetchone()[0] == "cancelled"
    assert reserve(app, oid, account(app, world), u)[1][0] == "bad_status"      # a returned order cannot be shipped again


def test_when_a_person_already_changed_the_order_the_shipment_still_updates(app, world):
    oid, v, u, sid = book(app, world)
    assert app.execute("SELECT order_transition(%s,%s,'delivered','by hand')", (oid, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT shipment_apply(%s,'returned','return')", (sid,)).fetchone()[0] == "changed"      # delivered -> returned is a legal order move
    assert order_status(app, oid) == "returned"
    oid2, v2, u2, sid2 = book(app, world, phone="01733445566")
    app.execute("SELECT order_transition(%s,%s,'returned','by hand')", (oid2, u2))
    assert app.execute("SELECT shipment_apply(%s,'delivered','delivered')", (sid2,)).fetchone()[0] == "mismatch"
    assert order_status(app, oid2) == "returned"                                  # the order was NOT forced
    assert app.execute("SELECT status FROM order_shipments WHERE id=%s", (sid2,)).fetchone()[0] == "delivered"
    assert "did not follow" in app.execute("SELECT note FROM order_shipment_events WHERE shipment_id=%s ORDER BY seq DESC LIMIT 1", (sid2,)).fetchone()[0]


def test_bad_status_is_rejected(app, world):
    oid, v, u, sid = book(app, world)
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT shipment_apply(%s,'teleported','x')", (sid,))


def test_twenty_failed_checks_ask_a_person_to_look(app, world):
    oid, v, u, sid = book(app, world)
    for i in range(19):
        assert app.execute("SELECT shipment_check_failed(%s,'401')", (sid,)).fetchone()[0] == "retry"
    assert app.execute("SELECT shipment_check_failed(%s,'401')", (sid,)).fetchone()[0] == "attention"
    assert app.execute("SELECT status FROM order_shipments WHERE id=%s", (sid,)).fetchone()[0] == "attention"
    assert app.execute("SELECT shipment_apply(%s,'in_transit','x')", (sid,)).fetchone()[0] == "changed"      # a later good answer brings it back
    assert app.execute("SELECT check_fails FROM order_shipments WHERE id=%s", (sid,)).fetchone()[0] == 0


def test_emergency_stop_blocks_booking_only(app, world, owner):
    oid, v, u = confirmed_order(app, world)
    acc = account(app, world)
    oid2, v2, u2, sid2 = book(app, world, phone="01766778899")
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user, reason) VALUES (%s,'stop',%s,'t')", (world["a"]["tenant"], u))
    try:
        ctx(app, world)
        assert reserve(app, oid, acc, u)[1][0] == "emergency"
        assert app.execute("SELECT shipment_apply(%s,'delivered','delivered')", (sid2,)).fetchone()[0] == "changed"      # reality is still recorded
    finally:
        owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
        owner.execute("DELETE FROM emergency_events")
        owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")


def test_shipments_are_isolated_and_cannot_be_written_directly(app, world):
    oid, v, u, sid = book(app, world)
    for sql in ("UPDATE order_shipments SET status='delivered'", "DELETE FROM order_shipments", "INSERT INTO order_shipments (id) VALUES ('x')", "UPDATE order_shipment_events SET status='x'", "DELETE FROM order_shipment_events"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
    set_tenant(app, world["b"]["tenant"])
    assert app.execute("SELECT count(*) FROM order_shipments").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM courier_accounts").fetchone()[0] == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT shipment_apply(%s,'delivered','x')", (sid,))                 # foreign shipment: forbidden
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM courier_credentials(%s)", (sid,))


def test_daily_report_counts_the_day_and_stays_in_one_workspace(app, world):
    oid, v, u, sid = book(app, world)
    app.execute("SELECT shipment_apply(%s,'delivered','delivered')", (sid,))
    oid2, v2, u2, sid2 = book(app, world, phone="01788990011")
    day = app.execute("SELECT (now() AT TIME ZONE 'Asia/Dhaka')::date").fetchone()[0]
    rep = app.execute("SELECT order_report_calc(%s)", (day,)).fetchone()[0]
    assert rep["created"]["count"] == 2 and rep["created"]["value_minor"] == 112000
    assert rep["delivered"] == {"count": 1, "value_minor": 56000}
    assert rep["shipped"] == 2 and rep["confirmed"] == 2
    assert rep["open"] == {"shipped": 1}
    assert rep["cod_pending"] == {"count": 1, "value_minor": 56000}
    assert rep["couriers"] == [{"courier": "steadfast", "delivered": 1, "bad": 0}]
    set_tenant(app, world["b"]["tenant"])
    empty = app.execute("SELECT order_report_calc(%s)", (day,)).fetchone()[0]
    assert empty["created"]["count"] == 0 and empty["couriers"] == [] and empty["open"] == {}
    set_tenant(app, world["a"]["tenant"])
    assert app.execute("SELECT order_report_store(%s,%s,%s::jsonb)", (world["a"]["tenant"], day, json.dumps(rep))).fetchone()[0] is True
    assert app.execute("SELECT order_report_store(%s,%s,%s::jsonb)", (world["a"]["tenant"], day, json.dumps(rep))).fetchone()[0] is True       # re-run replaces
    assert app.execute("SELECT count(*) FROM order_reports").fetchone()[0] == 1
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT order_report_store(%s,%s,'{}'::jsonb)", (world["b"]["tenant"], day))
