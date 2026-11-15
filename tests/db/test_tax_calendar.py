import datetime

import psycopg
import pytest
from hypothesis import HealthCheck, given, settings, strategies as st

from conftest import as_admin, ulid
from test_accounting import ctx  # noqa: F401

TODAY = datetime.date.today()


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("DELETE FROM tax_obligation_done")
    owner.execute("DELETE FROM tax_obligations")


def save(app, world, *, who="a", id=None, label="মূসক", kind="vat_return", freq="monthly", day=15, month=None, once=None, remind=7):
    ctx(app, world, who)
    oid = id or ulid()
    r = app.execute("SELECT tax_obligation_save(%s,%s,%s,%s,%s,%s,%s,%s,%s::date,%s)", (oid, world[who]["tenant"], world[who]["user"], label, kind, freq, day, month, once, remind)).fetchone()[0]
    return oid, r


def done(app, world, oid, due, on=None, who="a", note=None):
    ctx(app, world, who)
    return app.execute("SELECT tax_cal_done(%s,%s,%s,%s::date,%s::date,%s)", (oid, world[who]["tenant"], world[who]["user"], due, on or TODAY.isoformat(), note)).fetchone()[0]


def matches(app, freq, day, month, once, d):
    return app.execute("SELECT tax_due_matches(%s,%s,%s,%s::date,%s::date)", (freq, day, month, once, d)).fetchone()[0]


def test_create_then_change_keeps_one_row(app, world):
    oid, r = save(app, world)
    assert r == "ok"
    _, r = save(app, world, id=oid, label="নতুন নাম", day=20)
    assert r == "ok"
    assert app.execute("SELECT count(*), max(label), max(due_day) FROM tax_obligations").fetchone() == (1, "নতুন নাম", 20)


@pytest.mark.parametrize("kw,expect", [
    (dict(label=" "), "bad_input"), (dict(kind="gst"), "bad_input"), (dict(freq="weekly"), "bad_input"), (dict(remind=61), "bad_input"), (dict(remind=-1), "bad_input"),
    (dict(day=0), "bad_schedule"), (dict(day=32), "bad_schedule"), (dict(day=None), "bad_schedule"), (dict(month=3), "bad_schedule"),
    (dict(freq="quarterly", month=4), "bad_schedule"), (dict(freq="quarterly", month=None), "bad_schedule"),
    (dict(freq="yearly", month=13), "bad_schedule"), (dict(freq="yearly", month=None), "bad_schedule"),
    (dict(freq="once", day=None), "bad_schedule"), (dict(freq="once", day=None, once="2026-12-01", month=2), "bad_schedule"),
    (dict(freq="monthly", once="2026-12-01"), "bad_schedule"),
])
def test_bad_input_is_refused_and_nothing_written(app, world, kw, expect):
    assert save(app, world, **kw)[1] == expect
    assert app.execute("SELECT count(*) FROM tax_obligations").fetchone()[0] == 0


def test_valid_shapes(app, world):
    assert save(app, world, freq="quarterly", month=3)[1] == "ok"
    assert save(app, world, freq="yearly", month=11, day=30)[1] == "ok"
    assert save(app, world, freq="once", day=None, once="2027-01-10")[1] == "ok"


@pytest.mark.parametrize("freq,day,month,once,d,want", [
    ("monthly", 15, None, None, "2026-11-15", True), ("monthly", 15, None, None, "2026-11-14", False),
    ("monthly", 31, None, None, "2026-02-28", True), ("monthly", 31, None, None, "2026-02-27", False), ("monthly", 31, None, None, "2024-02-29", True), ("monthly", 30, None, None, "2026-04-30", True),
    ("quarterly", 15, 3, None, "2026-03-15", True), ("quarterly", 15, 3, None, "2026-06-15", True), ("quarterly", 15, 3, None, "2026-12-15", True), ("quarterly", 15, 3, None, "2026-11-15", False),
    ("quarterly", 10, 1, None, "2026-04-10", True), ("quarterly", 10, 1, None, "2026-05-10", False),
    ("yearly", 30, 11, None, "2026-11-30", True), ("yearly", 30, 11, None, "2026-10-30", False), ("yearly", 29, 2, None, "2026-02-28", True),
    ("once", None, None, "2026-12-01", "2026-12-01", True), ("once", None, None, "2026-12-01", "2026-12-02", False),
])
def test_due_date_matching(app, freq, day, month, once, d, want):
    assert matches(app, freq, day, month, once, d) is want


def test_tick_and_untick(app, world):
    oid, _ = save(app, world)
    assert done(app, world, oid, "2026-10-15", "2026-10-14", note=" paid ") == "ok"
    assert app.execute("SELECT done_on, note FROM tax_obligation_done").fetchone() == (datetime.date(2026, 10, 14), "paid")
    assert done(app, world, oid, "2026-10-15") == "already"
    assert done(app, world, oid, "2026-10-16") == "bad_due"
    assert done(app, world, oid, "2026-11-15", (TODAY + datetime.timedelta(days=1)).isoformat()) == "bad_date"
    assert done(app, world, oid, "2026-11-15", "2011-01-01") == "bad_date"
    assert app.execute("SELECT count(*) FROM tax_obligation_done").fetchone()[0] == 1
    ctx(app, world, "a")
    t = world["a"]["tenant"]
    assert app.execute("SELECT tax_cal_undo(%s,%s,%s::date)", (oid, t, "2026-10-15")).fetchone()[0] == "ok"
    assert app.execute("SELECT tax_cal_undo(%s,%s,%s::date)", (oid, t, "2026-10-15")).fetchone()[0] == "missing"
    assert done(app, world, oid, "2026-10-15") == "ok"


def test_switch_off_keeps_history(app, world):
    oid, _ = save(app, world)
    done(app, world, oid, "2026-10-15")
    ctx(app, world, "a")
    assert app.execute("SELECT tax_obligation_active(%s,%s,false)", (oid, world["a"]["tenant"])).fetchone()[0] == "ok"
    assert app.execute("SELECT active FROM tax_obligations").fetchone()[0] is False
    assert app.execute("SELECT count(*) FROM tax_obligation_done").fetchone()[0] == 1


def test_seed_once_only(app, world):
    ctx(app, world, "a")
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute("SELECT tax_cal_seed(%s,%s,%s)", (t, u, [ulid()] * 4)).fetchone()[0] == "bad_ids"
    assert app.execute("SELECT tax_cal_seed(%s,%s,%s)", (t, u, [ulid() for _ in range(5)])).fetchone()[0] == "ok"
    assert app.execute("SELECT count(*) FROM tax_obligations").fetchone()[0] == 5
    assert app.execute("SELECT tax_cal_seed(%s,%s,%s)", (t, u, [ulid() for _ in range(5)])).fetchone()[0] == "already"
    assert app.execute("SELECT count(*) FROM tax_obligations").fetchone()[0] == 5


def test_tenants_are_isolated(app, world):
    oid, _ = save(app, world)
    done(app, world, oid, "2026-10-15")
    ctx(app, world, "b")
    tb, ub = world["b"]["tenant"], world["b"]["user"]
    assert app.execute("SELECT count(*) FROM tax_obligations").fetchone()[0] == 0
    assert app.execute("SELECT count(*) FROM tax_obligation_done").fetchone()[0] == 0
    for sql, args in [("SELECT tax_obligation_save(%s,%s,%s,'x','other','once',NULL,NULL,'2027-01-01',7)", (oid, tb, ub)), ("SELECT tax_obligation_active(%s,%s,false)", (oid, tb)),
                      ("SELECT tax_cal_done(%s,%s,%s,'2026-10-15',%s,NULL)", (oid, tb, ub, TODAY.isoformat()))]:
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        ctx(app, world, "b")
    assert app.execute("SELECT tax_cal_undo(%s,%s,'2026-10-15')", (oid, tb)).fetchone()[0] == "missing"      # cannot see, cannot undo
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM tax_obligation_done").fetchone()[0] == 1
    # acting for another tenant is refused outright
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT tax_obligation_save(%s,%s,%s,'x','other','once',NULL,NULL,'2027-01-01',7)", (ulid(), world["a"]["tenant"], ub))
    ctx(app, world, "a")
    assert app.execute("SELECT label FROM tax_obligations").fetchone()[0] == "মূসক"


@pytest.mark.parametrize("sql", [
    "INSERT INTO tax_obligations (id, tenant_id, label, kind, freq, due_day, created_by) VALUES ('x','x','l','other','monthly',1,'x')",
    "UPDATE tax_obligations SET label = 'z'", "DELETE FROM tax_obligations", "UPDATE tax_obligation_done SET note = 'z'", "DELETE FROM tax_obligation_done",
    "INSERT INTO tax_obligation_done (obligation_id, tenant_id, due_date, done_on, done_by) VALUES ('x','x','2026-01-01','2026-01-01','x')"])
def test_the_app_role_cannot_write_directly(app, world, sql):
    oid, _ = save(app, world)
    done(app, world, oid, "2026-10-15")
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(sql)


def test_table_checks_hold_even_for_the_owner(owner, world):
    as_admin(owner)
    t, u = world["a"]["tenant"], world["a"]["user"]
    for freq, day, month, once in [("monthly", None, None, None), ("monthly", 1, 1, None), ("once", 1, None, "2026-01-01"), ("yearly", 1, None, None), ("quarterly", 1, 4, None)]:
        with pytest.raises(psycopg.errors.CheckViolation):
            owner.execute("INSERT INTO tax_obligations (id, tenant_id, label, kind, freq, due_day, due_month, once_date, created_by) VALUES (%s,%s,'l','other',%s,%s,%s,%s,%s)", (ulid(), t, freq, day, month, once, u))
        owner.rollback() if hasattr(owner, "rollback") and not owner.autocommit else None


@settings(max_examples=40, deadline=None, suppress_health_check=[HealthCheck.function_scoped_fixture, HealthCheck.too_slow])
@given(day=st.integers(1, 31), month=st.integers(1, 3), year=st.integers(2024, 2030), mm=st.integers(1, 12))
def test_quarterly_due_dates_are_exactly_every_third_month(app, world, day, month, year, mm):
    import calendar
    last = calendar.monthrange(year, mm)[1]
    for d in range(1, last + 1):
        want = ((mm - 1) % 3) + 1 == month and d == min(day, last)
        if want or d in (1, 15, last):
            assert matches(app, "quarterly", day, month, None, datetime.date(year, mm, d).isoformat()) is want
