import json

import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import acc, ctx, seed  # noqa: F401
from test_accounting_docs import D, line, make, party, setup, wipe_all

TABLES = ("vat_returns", "vat_doc_flags", "vat_rates", "vat_settings")


def wipe(owner):
    as_admin(owner)
    for t in TABLES:
        owner.execute(f"DELETE FROM {t}")
    wipe_all(owner)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    wipe(owner)


def settings(app, world, reg=True, bin_="123456789", period="monthly", who="a"):
    ctx(app, world, who)
    return app.execute("SELECT vat_settings_save(%s,%s,%s,%s,%s)", (world[who]["tenant"], world[who]["user"], reg, bin_, period)).fetchone()[0]


def rate(app, world, code="STD", bp=1500, frm="2026-07-01", who="a", label="হার"):
    ctx(app, world, who)
    return app.execute("SELECT vat_rate_save(%s,%s,%s,%s,%s,%s,%s::date)", (ulid(), world[who]["tenant"], world[who]["user"], code, label, bp, frm)).fetchone()[0]


def test_settings_rules(app, world):
    assert settings(app, world) == "ok"
    assert app.execute("SELECT registered, bin, filing_period FROM vat_settings").fetchone() == (True, "123456789", "monthly")
    assert settings(app, world, period="quarterly") == "ok" and app.execute("SELECT filing_period FROM vat_settings").fetchone()[0] == "quarterly"
    assert settings(app, world, reg=True, bin_="") == "bad_bin"            # registered needs a BIN
    assert settings(app, world, reg=False, bin_=None) == "ok"
    assert settings(app, world, bin_="12ab") == "bad_bin"
    assert settings(app, world, bin_="1234567") == "bad_bin"
    assert settings(app, world, period="weekly") == "bad_period"
    assert app.execute("SELECT count(*) FROM vat_settings").fetchone()[0] == 1


def test_seed_only_once(app, world):
    ctx(app, world, "a")
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute("SELECT vat_rate_seed(%s,%s)", (t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT code, rate_bp FROM vat_rates ORDER BY code").fetchall() == [("STD", 1500), ("TRADER", 750), ("ZERO", 0)]
    assert app.execute("SELECT vat_rate_seed(%s,%s)", (t, u)).fetchone()[0] == "already"
    assert app.execute("SELECT count(*) FROM vat_rates").fetchone()[0] == 3


def test_a_new_rate_ends_the_old_one_the_day_before(app, world):
    assert rate(app, world, frm="2026-07-01") == "ok"
    assert rate(app, world, bp=1000, frm="2026-12-01") == "ok"
    assert app.execute("SELECT rate_bp, effective_from, effective_to FROM vat_rates WHERE code='STD' ORDER BY effective_from").fetchall()[0][2].isoformat() == "2026-11-30"
    assert app.execute("SELECT count(*) FROM vat_rates WHERE code='STD' AND effective_to IS NULL").fetchone()[0] == 1


@pytest.mark.parametrize("kw,want", [
    ({"frm": "2026-06-30"}, "overlap"), ({"frm": "2026-07-01"}, "overlap"), ({"frm": "2012-01-01"}, "bad_date"), ({"frm": "2999-01-01"}, "bad_date"),
    ({"bp": -1}, "bad_input"), ({"bp": 10001}, "bad_input"), ({"code": "bad code!"}, "bad_input"), ({"label": "  "}, "bad_input"),
])
def test_rate_refusals(app, world, kw, want):
    assert rate(app, world) == "ok"
    assert rate(app, world, **kw) == want


def test_a_rate_cannot_be_backdated_into_an_ended_one(app, world):
    rate(app, world, frm="2026-01-01")
    rate(app, world, bp=1000, frm="2026-06-01")
    assert rate(app, world, bp=900, frm="2026-03-01") == "overlap"


def purchase(app, world, kind="bill", vat=1500):
    setup_ = setup(app, world)
    p = party(app, world, "supplier")
    pay = setup_["bank"] if kind == "expense" else None
    did, r = make(app, world, kind, p, [line(setup_["rent"], 10000)], vat=vat, pay=pay)
    assert r[0] == "ok"
    return did


def flag(app, world, did, ok, reason=None, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT vat_doc_flag(%s,%s,%s,%s)", (did, world[who]["user"], ok, reason)).fetchone()[0]


def test_flagging_a_purchase_as_not_claimable_needs_a_reason(app, world):
    did = purchase(app, world)
    assert flag(app, world, did, False) == "need_reason"
    assert flag(app, world, did, False, "  ") == "need_reason"
    assert flag(app, world, did, False, "মূসক-৬.৩ নেই") == "ok"
    assert app.execute("SELECT creditable, reason FROM vat_doc_flags").fetchone() == (False, "মূসক-৬.৩ নেই")
    assert flag(app, world, did, True, "ignored") == "ok"
    assert app.execute("SELECT creditable, reason FROM vat_doc_flags").fetchone() == (True, None)
    assert app.execute("SELECT count(*) FROM vat_doc_flags").fetchone()[0] == 1


def test_only_purchases_with_vat_can_be_flagged(app, world):
    s = setup(app, world)
    c = party(app, world, "customer")
    inv, _ = make(app, world, "invoice", c, [line(s["sales"], 5000)], vat=750)
    assert flag(app, world, inv, False, "x") == "not_purchase"
    p = party(app, world, "supplier")
    nov, _ = make(app, world, "bill", p, [line(s["rent"], 5000)], vat=0)
    assert flag(app, world, nov, False, "x") == "no_vat"


def figures(out=1500, inp=750):
    return {"output_vat_minor": out, "input_vat_minor": inp, "net_minor": out - inp}


def save(app, world, frm="2026-11-01", to="2026-11-30", fig=None, who="a"):
    ctx(app, world, who)
    r = app.execute("SELECT * FROM vat_return_save(%s,%s,%s,%s::date,%s::date,%s::jsonb,%s)", (ulid(), world[who]["tenant"], world[who]["user"], frm, to, json.dumps(fig or figures()), "n")).fetchone()
    return tuple(r)


def test_a_draft_is_refreshed_not_duplicated(app, world):
    r1 = save(app, world)
    assert r1[0] == "ok"
    r2 = save(app, world, fig=figures(2000, 0))
    assert r2 == ("ok", r1[1])
    assert app.execute("SELECT count(*), max((figures->>'net_minor')::int) FROM vat_returns").fetchone() == (1, 2000)


@pytest.mark.parametrize("kw,want", [
    ({"frm": "2026-12-01", "to": "2026-11-01"}, "bad_period"), ({"frm": "2025-01-01", "to": "2026-11-30"}, "bad_period"),
    ({"fig": {"output_vat_minor": 1}}, "bad_input"), ({"fig": {"output_vat_minor": "x", "input_vat_minor": 1, "net_minor": 1}}, "bad_input"),
])
def test_save_refusals(app, world, kw, want):
    assert save(app, world, **kw)[0] == want


def test_filing_locks_the_period(app, world):
    r = save(app, world)
    ctx(app, world, "a")
    u = world["a"]["user"]
    assert app.execute("SELECT vat_return_file(%s,%s,%s::date,%s)", (r[1], u, "2026-10-31", "x")).fetchone()[0] == "bad_date"          # before the period began
    assert app.execute("SELECT vat_return_file(%s,%s,%s::date,%s)", (r[1], u, "2999-01-01", "x")).fetchone()[0] == "bad_date"
    assert app.execute("SELECT vat_return_file(%s,%s,CURRENT_DATE,%s)", (r[1], u, "x" * 61)).fetchone()[0] == "bad_ref"
    assert app.execute("SELECT vat_return_file(%s,%s,CURRENT_DATE,%s)", (r[1], u, "NBR-1")).fetchone()[0] == "ok"
    assert app.execute("SELECT status, filed_ref FROM vat_returns WHERE id=%s", (r[1],)).fetchone() == ("filed", "NBR-1")
    assert app.execute("SELECT vat_return_file(%s,%s,CURRENT_DATE,NULL)", (r[1], u)).fetchone()[0] == "not_draft"
    assert save(app, world)[0] == "filed"                                       # the period cannot be re-drafted
    assert save(app, world, "2026-12-01", "2026-12-31")[0] == "ok"              # another period is fine


def test_another_workspace_cannot_touch_anything(app, world):
    did = purchase(app, world)
    r = save(app, world)
    for sql, args in (("SELECT vat_doc_flag(%s,%s,false,'x')", (did, world["b"]["user"])), ("SELECT vat_return_file(%s,%s,CURRENT_DATE,NULL)", (r[1], world["b"]["user"]))):
        ctx(app, world, "b")
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql, args)
        app.rollback()
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT vat_settings_save(%s,%s,true,'123456789','monthly')", (world["a"]["tenant"], world["b"]["user"]))
    app.rollback()
    ctx(app, world, "b")
    for t in TABLES:
        assert app.execute(f"SELECT count(*) FROM {t}").fetchone()[0] == 0


@pytest.mark.parametrize("sql", [
    "INSERT INTO vat_rates (id, tenant_id, code, label, rate_bp, effective_from, created_by) VALUES ('x','x','S','l',1,'2026-01-01','x')",
    "UPDATE vat_settings SET registered = false", "UPDATE vat_rates SET rate_bp = 1", "DELETE FROM vat_returns", "UPDATE vat_returns SET status = 'filed'", "UPDATE vat_doc_flags SET creditable = true", "DELETE FROM vat_doc_flags",
])
def test_the_app_role_cannot_write_directly(app, world, sql):
    did = purchase(app, world)
    assert settings(app, world) == "ok" and rate(app, world) == "ok" and flag(app, world, did, False, "x") == "ok" and save(app, world)[0] == "ok"      # rows exist, so a granted write would really change them
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(sql)
