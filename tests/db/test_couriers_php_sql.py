"""The SQL strings behind the courier screens, the sync command and the daily-report command, run against real PG."""
import json

import pytest

from conftest import as_admin, set_tenant, ulid
from test_catalog import ctx
from test_couriers import account, book, clean as _clean, confirmed_order, order_status, reserve  # noqa: F401  (autouse cleanup is reused)
from test_lead_php_sql import sql_of

REPO = "app/Couriers/PgCourierRepo.php"
SYNC = "app/Console/Commands/CouriersSyncCommand.php"
REPORT = "app/Console/Commands/OrdersDailyReportCommand.php"


def test_repo_statements_run(app, world):
    oid, v, u, sid = book(app, world)
    acc = app.execute("SELECT account_id FROM order_shipments WHERE id=%s", (sid,)).fetchone()[0].strip()
    rows = app.execute(sql_of(REPO, "from courier_accounts order by courier")).fetchall()
    assert [(r[0].strip(), r[1], r[3]) for r in rows] == [(acc, "steadfast", "active")]
    cr = app.execute(sql_of(REPO, "from courier_credentials(?)"), (acc,)).fetchone()
    assert cr[0] == "sealed-blob" and cr[2] == "steadfast"
    one = app.execute(sql_of(REPO, "from order_shipments where id = ?"), (sid,)).fetchone()
    assert one[4] == "booked" and one[5] == "C-" + sid
    mine = app.execute(sql_of(REPO, "where order_id = ? order by created_at desc"), (oid,)).fetchall()
    assert len(mine) == 1 and mine[0][1] == "steadfast" and mine[0][-1] is False           # not stuck: it is booked
    lst = app.execute(sql_of(REPO, "join store_orders o on o.id = s.order_id"), ("all", "all", 50)).fetchall()
    assert len(lst) == 1 and lst[0][2] == 1
    assert app.execute(sql_of(REPO, "join store_orders o on o.id = s.order_id"), ("delivered", "delivered", 50)).fetchall() == []
    day = app.execute("SELECT (now() AT TIME ZONE 'Asia/Dhaka')::date").fetchone()[0]
    calc = app.execute(sql_of(REPO, "order_report_calc"), (str(day),)).fetchone()[0]
    assert calc["created"]["count"] == 1
    app.execute(sql_of(REPO, "order_report_store"), (world["a"]["tenant"], str(day), json.dumps(calc)))
    rep = app.execute(sql_of(REPO, "from order_reports order by day desc"), (10,)).fetchall()
    assert len(rep) == 1 and rep[0][1]["created"]["count"] == 1


def test_a_booking_row_is_stuck_only_after_ten_minutes(app, world, owner):
    oid, v, u = confirmed_order(app, world)
    acc = account(app, world)
    sid, r = reserve(app, oid, acc, u)
    q = sql_of(REPO, "where order_id = ? order by created_at desc")
    assert app.execute(q, (oid,)).fetchone()[-1] is False
    as_admin(owner)
    owner.execute("UPDATE order_shipments SET created_at = now() - interval '11 minutes' WHERE id=%s", (sid,))
    ctx(app, world)
    assert app.execute(q, (oid,)).fetchone()[-1] is True


def test_sync_query_picks_open_parcels_not_checked_in_the_last_15_minutes(app, world, owner):
    oid, v, u, sid = book(app, world)
    done_oid, dv, du, done = book(app, world, phone="01788776655")
    app.execute("SELECT shipment_apply(%s,'delivered','delivered')", (done,))
    sql = sql_of(SYNC, "from order_shipments where status in", fragment=True).replace("%s", "%s")
    as_admin(owner)
    owner.execute("UPDATE order_shipments SET last_checked_at = now() - interval '1 day' WHERE id=%s", (done,))        # old enough to be due, but finished
    assert [r[0].strip() for r in owner.execute(sql, (100,)).fetchall()] == [sid]                 # never checked -> due; delivered -> never
    owner.execute("UPDATE order_shipments SET last_checked_at = now() WHERE id=%s", (sid,))
    assert owner.execute(sql, (100,)).fetchall() == []                                           # just checked -> not due
    owner.execute("UPDATE order_shipments SET last_checked_at = now() - interval '16 minutes' WHERE id=%s", (sid,))
    assert len(owner.execute(sql, (100,)).fetchall()) == 1


def test_report_command_finds_the_workspaces_that_had_activity_that_day(app, world, owner):
    book(app, world)
    day = app.execute("SELECT (now() AT TIME ZONE 'Asia/Dhaka')::date").fetchone()[0]
    sql = sql_of(REPORT, "from store_order_events where", fragment=True)
    as_admin(owner)
    assert [r[0].strip() for r in owner.execute(sql, (str(day),)).fetchall()] == [world["a"]["tenant"]]
    assert owner.execute(sql, ("2001-01-01",)).fetchall() == []
    # 20:00 UTC on 1 Jan is already 2 Jan in Dhaka (UTC+6): the report day follows the Dhaka calendar
    owner.execute("ALTER TABLE store_order_events DISABLE TRIGGER store_order_events_no_change")
    owner.execute("UPDATE store_order_events SET created_at = '2026-01-01 20:00:00+00'")
    owner.execute("ALTER TABLE store_order_events ENABLE TRIGGER store_order_events_no_change")
    assert len(owner.execute(sql, ("2026-01-02",)).fetchall()) == 1
    assert owner.execute(sql, ("2026-01-01",)).fetchall() == []
