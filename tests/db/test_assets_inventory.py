import datetime
import json

import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid
from test_accounting import acc, cr, ctx, dr, post, seed  # noqa: F401
from test_accounting_docs import setup, wipe_all


def wipe(owner):
    as_admin(owner)
    for t in ("fa_dep_lines", "inv_costs", "stock_movements"):
        owner.execute(f"ALTER TABLE {t} DISABLE TRIGGER {t}_no_change")
    for t in ("fa_dep_lines", "fixed_assets", "inv_costs", "stock_movements", "product_variants", "products", "store_connections"):
        owner.execute(f"DELETE FROM {t}")
    for t in ("fa_dep_lines", "inv_costs", "stock_movements"):
        owner.execute(f"ALTER TABLE {t} ENABLE TRIGGER {t}_no_change")
    wipe_all(owner)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    wipe(owner)


def today(app):
    return app.execute("SELECT (now() AT TIME ZONE 'Asia/Dhaka')::date").fetchone()[0]


def ok(r):
    assert r[1][0] == "ok", r


def register(app, world, A, *, name="Laptop", account=None, date="2026-09-15", cost=1_200_000, salvage=0, life=12, who="a"):
    aid = ulid()
    acct = account or acc(app, world, "fixed_equipment", who)
    r = app.execute("SELECT fa_register(%s,%s,%s,%s,%s,%s::date,%s,%s,%s)", (aid, world[who]["tenant"], world[who]["user"], name, acct, date, cost, salvage, life)).fetchone()[0]
    return aid, r


def run(app, world, month, who="a"):
    return app.execute("SELECT * FROM fa_run(%s,%s,%s,%s::date)", (ulid(), world[who]["tenant"], world[who]["user"], month)).fetchone()


def lines_of(app, asset):
    return app.execute("SELECT to_char(period,'YYYY-MM'), amount_minor FROM fa_dep_lines WHERE asset_id=%s ORDER BY period", (asset,)).fetchall()


def balance(app, key):
    return app.execute("SELECT coalesce(sum(l.debit_minor-l.credit_minor),0) FROM journal_lines l JOIN accounts a ON a.id=l.account_id WHERE a.system_key=%s", (key,)).fetchone()[0]


# ----------------------------------------------------------------------------------------------------------------- fixed assets
def test_month_amounts_always_add_up_exactly(app, world):
    for cost, salvage, life in ((1_200_000, 0, 12), (1_000_000, 1, 7), (999_999_999_999, 123_456_789, 600), (100, 99, 600), (10, 0, 3), (1, 0, 1)):
        amts = [app.execute("SELECT fa_month_amount(%s,%s,%s,%s)", (cost, salvage, life, k)).fetchone()[0] for k in range(life)]
        assert sum(amts) == cost - salvage and min(amts) >= 0 and max(amts) - min(amts) <= 1, (cost, salvage, life)
        assert app.execute("SELECT fa_month_amount(%s,%s,%s,%s), fa_month_amount(%s,%s,%s,%s)", (cost, salvage, life, -1, cost, salvage, life, life)).fetchone() == (0, 0)


def test_register_rules(app, world):
    A = setup(app, world)
    assert register(app, world, A)[1] == "ok"
    for kw, exp in ((dict(name="  "), "bad_input"), (dict(cost=0), "bad_input"), (dict(salvage=1_200_000), "bad_input"), (dict(salvage=-1), "bad_input"), (dict(life=0), "bad_input"), (dict(life=601), "bad_input"),
                    (dict(date="2099-01-01"), "bad_input"), (dict(date="1999-01-01"), "bad_input"), (dict(cost=10**12), "bad_input"),
                    (dict(account=A["cash"]), "bad_account"), (dict(account=A["ar"]), "bad_account"), (dict(account=A["rent"]), "bad_account"),
                    (dict(account=acc(app, world, "inventory")), "bad_account"), (dict(account=acc(app, world, "accum_depreciation")), "bad_account"),
                    (dict(account=app.execute("SELECT id FROM accounts WHERE code='1600'").fetchone()[0].strip()), "bad_account"), (dict(account="z" * 26), "bad_account")):
        assert register(app, world, A, **kw)[1] == exp, kw
    app.execute("UPDATE accounts SET active=false WHERE system_key='accum_depreciation'")
    assert register(app, world, A)[1] == "no_system_account"
    assert app.execute("SELECT count(*) FROM fixed_assets").fetchone()[0] == 1


def test_a_months_depreciation_is_posted_once_and_grouped(app, world):
    A = setup(app, world)
    a1, _ = register(app, world, A, name="Laptop", date="2026-09-15", cost=1_200_000, life=12)
    a2, _ = register(app, world, A, name="Chair", date="2026-09-01", cost=100_000, salvage=10_000, life=9, account=acc(app, world, "fixed_equipment"))
    r = run(app, world, "2026-09-20")                                                                            # any day in the month
    assert tuple(r) == ("ok", 2, 100_000 + 10_000)
    e = app.execute("SELECT e.entry_date, e.memo, e.source, e.source_ref, count(*) FROM journal_entries e JOIN journal_lines l ON l.entry_id=e.id WHERE e.source_ref='depr:2026-09' GROUP BY 1,2,3,4").fetchall()
    assert [(str(x[0]), x[1], x[2], x[4]) for x in e] == [("2026-09-30", "Depreciation 2026-09", "system", 2)]       # both assets share the same two accounts -> two lines
    assert balance(app, "depreciation") == 110_000 and balance(app, "accum_depreciation") == -110_000
    assert tuple(run(app, world, "2026-09-01")) == ("nothing_to_do", 0, 0)
    assert lines_of(app, a1) == [("2026-09", 100_000)] and lines_of(app, a2) == [("2026-09", 10_000)]
    assert tuple(run(app, world, "2026-08-31")) == ("nothing_to_do", 0, 0)                                      # before either asset existed
    # a later-registered asset is caught up by re-running its months; the old one is not charged twice
    a3, _ = register(app, world, A, name="Phone", date="2026-09-03", cost=90_000, life=9)
    assert tuple(run(app, world, "2026-09-01")) == ("ok", 1, 10_000)
    assert balance(app, "depreciation") == 120_000
    assert tuple(run(app, world, "2026-10-01")) == ("ok", 3, 100_000 + 10_000 + 10_000)


def test_current_month_is_dated_today_and_the_future_is_refused(app, world):
    A = setup(app, world)
    register(app, world, A, date=str(today(app).replace(day=1)))
    t = today(app)
    assert run(app, world, str(t))[0] == "ok"
    d = app.execute("SELECT entry_date FROM journal_entries WHERE source_ref=%s", ("depr:" + t.strftime("%Y-%m"),)).fetchone()[0]
    assert d == t
    nxt = (t.replace(day=1) + datetime.timedelta(days=32)).replace(day=1)
    assert run(app, world, str(nxt))[0] == "bad_month"
    assert run(app, world, "1999-01-01")[0] == "bad_month"


def test_depreciation_stops_at_the_end_of_life_with_nothing_lost(app, world):
    A = setup(app, world)
    a, _ = register(app, world, A, date="2026-06-10", cost=1_000_000, salvage=1, life=3)
    for m in ("2026-06", "2026-07", "2026-08", "2026-09", "2026-10"):
        run(app, world, m + "-01")
    ls = lines_of(app, a)
    assert [x[0] for x in ls] == ["2026-06", "2026-07", "2026-08"] and sum(x[1] for x in ls) == 999_999        # 3 months only, exactly cost - salvage
    assert balance(app, "accum_depreciation") == -999_999


def test_pending_months_read(app, world):
    A = setup(app, world)
    a, _ = register(app, world, A, date="2026-09-10", cost=1200, life=12)
    p = app.execute("SELECT to_char(period,'YYYY-MM'), amount_minor FROM fa_pending('2026-11-30')").fetchall()
    assert p == [("2026-09", 100), ("2026-10", 100), ("2026-11", 100)]
    run(app, world, "2026-10-01")
    assert [x[0] for x in app.execute("SELECT to_char(period,'YYYY-MM') FROM fa_pending('2026-11-30')").fetchall()] == ["2026-09", "2026-11"]
    assert app.execute("SELECT count(*) FROM fa_pending('2026-08-31')").fetchone()[0] == 0


def test_period_lock_blocks_a_run_and_leaves_nothing(app, world):
    A = setup(app, world)
    a, _ = register(app, world, A, date="2026-09-10")
    app.execute("SELECT acct_lock(%s,'2026-09-30'::date,%s)", (world["a"]["tenant"], world["a"]["user"]))
    entries = app.execute("SELECT count(*) FROM journal_entries").fetchone()[0]
    assert tuple(run(app, world, "2026-09-01")) == ("locked", 0, 0)
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == entries and lines_of(app, a) == []
    assert run(app, world, "2026-10-01")[0] == "ok"


def test_disposal_rules(app, world):
    A = setup(app, world)
    u = world["a"]["user"]
    a, _ = register(app, world, A, name="Laptop", date="2026-08-05", cost=1_200_000, life=12)           # 100,000 a month
    post(app, world, [dr(acc(app, world, "fixed_equipment"), 1_200_000), cr(A["bank"], 1_200_000)], date="2026-08-05")
    disp = lambda d, proceeds, pay=None: app.execute("SELECT fa_dispose(%s,%s,%s,%s::date,%s,%s)", (ulid(), a, u, d, proceeds, pay)).fetchone()[0]
    assert disp("2026-11-02", 500_000, A["cash"]) == "depreciate_first"                                  # Aug, Sep, Oct not done yet
    for m in ("2026-08", "2026-09", "2026-10"):
        run(app, world, m + "-01")
    assert disp("2026-07-01", 0) == "bad_date" and disp("2099-01-01", 0) == "bad_date"
    assert disp("2026-11-02", -1) == "bad_proceeds"
    assert disp("2026-11-02", 5, A["ar"]) == "bad_pay_account"                                            # money must land in a cash/bank account
    assert disp("2026-11-02", 5) == "bad_pay_account"
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT fa_dispose(%s,%s,%s,'2026-11-02'::date,0,NULL)", (ulid(), "n" * 26, u))
    assert app.execute("SELECT status FROM fixed_assets WHERE id=%s", (a,)).fetchone()[0] == "active"      # nothing above changed anything
    assert balance(app, "fixed_equipment") == 1_200_000


def test_disposal_postings(app, world):
    A = setup(app, world)
    u = world["a"]["user"]
    equip = acc(app, world, "fixed_equipment")
    def fresh(name, cost):
        a, r = register(app, world, A, name=name, date="2026-08-05", cost=cost, life=12)
        assert r == "ok"
        post(app, world, [dr(equip, cost), cr(A["bank"], cost)], date="2026-08-05")
        return a
    sell = lambda asset, proceeds, pay, d="2026-11-02": app.execute("SELECT fa_dispose(%s,%s,%s,%s::date,%s,%s)", (ulid(), asset, u, d, proceeds, pay)).fetchone()[0]
    a_loss, a_gain, a_even = fresh("L", 1_200_000), fresh("G", 1_200_000), fresh("E", 1_200_000)
    for m in ("2026-08", "2026-09", "2026-10"):
        assert run(app, world, m + "-01")[1] == 3
    # accumulated 300,000 each -> book value 900,000
    cash0, bank0 = balance(app, "cash"), balance(app, "bank")
    assert sell(a_loss, 700_000, A["cash"]) == "ok"                                                      # loss 200,000
    assert sell(a_gain, 1_000_000, A["bank"]) == "ok"                                                    # gain 100,000
    assert sell(a_even, 900_000, A["cash"]) == "ok"                                                      # no gain, no loss
    assert balance(app, "cash") - cash0 == 700_000 + 900_000 and balance(app, "bank") - bank0 == 1_000_000
    assert balance(app, "fixed_equipment") == 0 and balance(app, "accum_depreciation") == 0
    assert balance(app, "misc_expense") == 200_000 and balance(app, "other_income") == -100_000
    assert app.execute("SELECT status, disposed_date::text, proceeds_minor FROM fixed_assets WHERE id=%s", (a_loss,)).fetchone() == ("disposed", "2026-11-02", 700_000)
    assert sell(a_loss, 1, A["cash"]) == "not_active"
    assert run(app, world, "2026-11-01")[0] == "nothing_to_do"                                            # disposed assets are no longer depreciated
    tb = app.execute("SELECT coalesce(sum(debit_minor),0), coalesce(sum(credit_minor),0) FROM acct_trial_balance('2099-12-31')").fetchone()
    assert tb[0] == tb[1]


def test_scrapping_and_the_disposal_month_rule(app, world):
    A = setup(app, world)
    u = world["a"]["user"]
    a, _ = register(app, world, A, date="2026-08-05", cost=600_000, life=6)                               # 100,000 a month
    post(app, world, [dr(acc(app, world, "fixed_equipment"), 600_000), cr(A["bank"], 600_000)], date="2026-08-05")
    for m in ("2026-08", "2026-09", "2026-10"):
        run(app, world, m + "-01")
    assert app.execute("SELECT fa_dispose(%s,%s,%s,'2026-11-02'::date,0,NULL)", (ulid(), a, u)).fetchone()[0] == "ok"      # scrapped for nothing: loss = 300,000
    assert balance(app, "misc_expense") == 300_000
    assert run(app, world, "2026-11-01")[0] == "nothing_to_do"                                           # no depreciation in the month of disposal
    b, _ = register(app, world, A, date="2026-08-05", cost=600_000, life=6)
    for m in ("2026-08", "2026-09"):
        run(app, world, m + "-01")
    assert app.execute("SELECT fa_dispose(%s,%s,%s,'2026-10-01'::date,0,NULL)", (ulid(), b, u)).fetchone()[0] == "ok"      # disposed on the 1st of October: Aug, Sep done is enough
    app.execute("SELECT acct_lock(%s,'2026-10-31'::date,%s)", (world["a"]["tenant"], u))
    c, _ = register(app, world, A, date="2026-10-02", cost=100, life=1)
    run(app, world, "2026-11-01")
    assert app.execute("SELECT fa_dispose(%s,%s,%s,'2026-10-20'::date,0,NULL)", (ulid(), c, u)).fetchone()[0] == "locked"
    assert app.execute("SELECT status FROM fixed_assets WHERE id=%s", (c,)).fetchone()[0] == "active"


# ----------------------------------------------------------------------------------------------------------------- inventory
def make_variant(app, world, owner, title="Tee", who="a", track=True):
    ctx(app, world, who)
    s = app.execute("SELECT id FROM store_connections").fetchone()
    if not s:
        sid = ulid()
        app.execute("INSERT INTO store_connections (id,tenant_id,platform,name) VALUES (%s,%s,'native','Main')", (sid, world[who]["tenant"]))
    else:
        sid = s[0]
    p, v = ulid(), ulid()
    app.execute("INSERT INTO products (id,tenant_id,store_id,title) VALUES (%s,%s,%s,%s)", (p, world[who]["tenant"], sid, title))
    app.execute("INSERT INTO product_variants (id,tenant_id,product_id,track_stock) VALUES (%s,%s,%s,%s)", (v, world[who]["tenant"], p, track))
    return v


def move(owner, world, variant, qty_after, at, who="a"):
    as_admin(owner)
    owner.execute("INSERT INTO stock_movements (tenant_id,variant_id,delta,qty_after,reason,created_at) VALUES (%s,%s,%s,%s,'adjust',%s::timestamptz)", (world[who]["tenant"], variant, 1, qty_after, at))


def cost(app, world, variant, c, date="2026-10-01", who="a"):
    ctx(app, world, who)
    return app.execute("SELECT inv_cost_set(%s,%s,%s::date,%s)", (variant, c, date, world[who]["user"])).fetchone()[0]


def val(app, asof, tenant=None):
    return app.execute("SELECT variant_id, qty, unit_cost_minor, value_minor FROM inv_valuation(%s::date) ORDER BY value_minor, variant_id", (asof,)).fetchall()


def test_valuation_uses_the_stock_and_cost_of_that_day(app, world, owner):
    setup(app, world)
    v = make_variant(app, world, owner)
    move(owner, world, v, 10, "2026-10-05 12:00+06")
    move(owner, world, v, 4, "2026-10-20 12:00+06")
    ctx(app, world)
    assert val(app, "2026-10-04") == []                                                    # nothing in stock yet
    assert cost(app, world, v, 500, "2026-10-01") == "ok"
    assert cost(app, world, v, 700, "2026-10-15") == "ok"
    assert val(app, "2026-10-10") == [(v, 10, 500, 5000)]
    assert val(app, "2026-10-15") == [(v, 10, 700, 7000)]                                  # cost effective that same day
    assert val(app, "2026-10-25") == [(v, 4, 700, 2800)]
    assert cost(app, world, v, 650, "2026-10-15") == "ok"                                  # same effective day: the later entry wins
    assert val(app, "2026-10-25") == [(v, 4, 650, 2600)]
    assert val(app, "2026-09-30") == []
    move(owner, world, v, 0, "2026-11-01 08:00+06")
    ctx(app, world)
    assert val(app, "2026-11-02") == []                                                    # sold out -> no row


def test_day_boundary_is_dhaka_time(app, world, owner):
    setup(app, world)
    v = make_variant(app, world, owner)
    move(owner, world, v, 3, "2026-10-10 23:30+06")                                        # 17:30 UTC on the 10th
    move(owner, world, v, 5, "2026-10-11 00:10+06")                                        # 18:10 UTC on the 10th, but already the 11th in Dhaka
    ctx(app, world)
    cost(app, world, v, 100, "2026-10-01")
    assert val(app, "2026-10-10")[0][1] == 3
    assert val(app, "2026-10-11")[0][1] == 5


def test_no_cost_untracked_and_unstocked_variants(app, world, owner):
    setup(app, world)
    nocost = make_variant(app, world, owner, "NoCost")
    untracked = make_variant(app, world, owner, "Service", track=False)
    empty = make_variant(app, world, owner, "Empty")
    for v in (nocost, untracked):
        move(owner, world, v, 5, "2026-10-05 12:00+06")
    ctx(app, world)
    assert val(app, "2026-10-30") == [(nocost, 5, None, 0)]                                # counted, but worth 0 until a cost is set
    assert cost(app, world, empty, 100) == "ok"
    assert [r[0] for r in val(app, "2026-10-30")] == [nocost]


def test_cost_rules_and_isolation(app, world, owner):
    A = setup(app, world)
    v = make_variant(app, world, owner)
    u = world["a"]["user"]
    for c, d, exp in ((-1, "2026-10-01", "bad_cost"), (10**12, "2026-10-01", "bad_cost"), (5, "2099-01-01", "bad_date"), (5, "1999-12-31", "bad_date")):
        assert cost(app, world, v, c, d) == exp
    assert cost(app, world, v, 0, "2026-10-01") == "ok"                                    # a zero cost is allowed (free item)
    assert app.execute("SELECT count(*) FROM inv_costs").fetchone()[0] == 1
    seed(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        cost(app, world, v, 5, "2026-10-01", who="b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT inv_cost_set(%s,5,'2026-10-01'::date,%s)", ("q" * 26, world["b"]["user"]))
    assert app.execute("SELECT count(*) FROM inv_costs").fetchone()[0] == 0 and val(app, "2026-10-30") == []
    for sql in ("INSERT INTO inv_costs(tenant_id) VALUES ('x')", "INSERT INTO fixed_assets(id) VALUES ('x')", "UPDATE fixed_assets SET name='x'", "DELETE FROM fa_dep_lines"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)


def test_revalue_brings_the_books_to_the_stock_value(app, world, owner):
    A = setup(app, world)
    u, t = world["a"]["user"], world["a"]["tenant"]
    inv, cogs = acc(app, world, "inventory"), acc(app, world, "cogs")
    v = make_variant(app, world, owner)
    move(owner, world, v, 10, "2026-10-05 12:00+06")
    ctx(app, world)
    cost(app, world, v, 500, "2026-10-01")
    reval = lambda d, counter=cogs: app.execute("SELECT * FROM inv_revalue(%s,%s,%s,%s::date,%s)", (ulid(), t, u, d, counter)).fetchone()
    assert tuple(reval("2026-10-31")) == ("ok", 5000, 0, 5000)
    assert balance(app, "inventory") == 5000 and balance(app, "cogs") == -5000                           # Dr Inventory, Cr COGS
    assert tuple(reval("2026-10-31")) == ("no_difference", 5000, 5000, 0)
    cost(app, world, v, 300, "2026-11-01")                                                                  # the cost falls
    assert tuple(reval("2026-11-02")) == ("ok", 3000, 5000, -2000)
    assert balance(app, "inventory") == 3000
    assert tuple(reval("2026-11-02")) == ("no_difference", 3000, 3000, 0)
    for counter, exp in ((inv, "bad_account"), (A["cash"], "bad_account"), (app.execute("SELECT id FROM accounts WHERE code='6000'").fetchone()[0].strip(), "bad_account"), ("z" * 26, "bad_account")):
        assert reval("2026-11-02", counter)[0] == exp, counter
    assert reval("2099-01-01")[0] == "bad_date" and reval("1999-01-01")[0] == "bad_date"


def test_revalue_counts_only_entries_up_to_its_date(app, world, owner):
    A = setup(app, world)
    u, t = world["a"]["user"], world["a"]["tenant"]
    inv = acc(app, world, "inventory")
    v = make_variant(app, world, owner)
    move(owner, world, v, 10, "2026-10-05 12:00+06")
    ctx(app, world)
    cost(app, world, v, 500, "2026-10-01")
    post(app, world, [dr(inv, 5000), cr(A["bank"], 5000)], date="2026-10-20")                              # the purchase is booked
    post(app, world, [dr(inv, 700), cr(A["bank"], 700)], date="2026-11-02")                                # a later entry must not leak into an October revaluation
    r = app.execute("SELECT * FROM inv_revalue(%s,%s,%s,'2026-10-31'::date,%s)", (ulid(), t, u, acc(app, world, "cogs"))).fetchone()
    assert tuple(r) == ("no_difference", 5000, 5000, 0)


def test_revalue_refusals_leave_the_books_alone(app, world, owner):
    A = setup(app, world)
    u, t = world["a"]["user"], world["a"]["tenant"]
    cogs = acc(app, world, "cogs")
    v = make_variant(app, world, owner)
    move(owner, world, v, 10, "2026-10-05 12:00+06")
    ctx(app, world)
    cost(app, world, v, 500, "2026-10-01")
    app.execute("SELECT acct_lock(%s,'2026-10-31'::date,%s)", (t, u))
    n = app.execute("SELECT count(*) FROM journal_entries").fetchone()[0]
    assert app.execute("SELECT result FROM inv_revalue(%s,%s,%s,'2026-10-31'::date,%s)", (ulid(), t, u, cogs)).fetchone()[0] == "locked"
    assert app.execute("SELECT count(*) FROM journal_entries").fetchone()[0] == n
    app.execute("UPDATE accounts SET active=false WHERE system_key='inventory'")
    assert app.execute("SELECT result FROM inv_revalue(%s,%s,%s,'2026-11-02'::date,%s)", (ulid(), t, u, cogs)).fetchone()[0] == "no_system_account"


def test_revalue_ignores_other_workspaces_even_for_an_admin_view(app, world, owner):
    A = setup(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    va = make_variant(app, world, owner)
    move(owner, world, va, 10, "2026-10-05 12:00+06")
    ctx(app, world)
    cost(app, world, va, 500, "2026-10-01")
    seed(app, world, "b")
    vb = make_variant(app, world, owner, who="b")
    move(owner, world, vb, 99, "2026-10-05 12:00+06", who="b")
    cost(app, world, vb, 1000, "2026-10-01", who="b")
    ctx(app, world)
    assert app.execute("SELECT result, value_minor FROM inv_revalue(%s,%s,%s,'2026-10-31'::date,%s)", (ulid(), t, u, acc(app, world, "cogs"))).fetchone() == ("ok", 5000)
    assert app.execute("SELECT sum(value_minor) FROM inv_valuation('2026-10-31')").fetchone()[0] == 5000


# ---------------------------------------------------------------------------------------------------------------------
hyp = pytest.importorskip("hypothesis")
from hypothesis import HealthCheck, given, settings, strategies as st  # noqa: E402

op = st.one_of(
    st.tuples(st.just("reg"), st.integers(1000, 5_000_000), st.integers(0, 90), st.integers(1, 9), st.integers(0, 3)),         # cost, salvage %, life, purchase month offset
    st.tuples(st.just("run"), st.integers(0, 4), st.just(0), st.just(0), st.just(0)),
    st.tuples(st.just("sell"), st.integers(0, 4), st.integers(0, 6_000_000), st.integers(0, 3), st.just(0)),
)


@settings(max_examples=40, deadline=None, suppress_health_check=[HealthCheck.function_scoped_fixture])
@given(st.lists(op, min_size=1, max_size=14))
def test_property_books_always_agree_with_the_asset_register(app, world, owner, ops):
    A = setup(app, world)
    u = world["a"]["user"]
    equip = acc(app, world, "fixed_equipment")
    months = ["2026-07", "2026-08", "2026-09", "2026-10", "2026-11"]
    assets = []
    for kind, a, b, c, d in ops:
        if kind == "reg":
            salvage = a * b // 100
            aid, r = register(app, world, A, name="x", date=months[d] + "-05", cost=a, salvage=min(salvage, a - 1), life=c * 2)
            if r == "ok":
                assets.append(aid)
                post(app, world, [dr(equip, a), cr(A["bank"], a)], date=months[d] + "-05")
        elif kind == "run":
            run(app, world, months[a] + "-01")
        elif assets:
            aid = assets[a % len(assets)]
            app.execute("SELECT fa_dispose(%s,%s,%s,%s::date,%s,%s)", (ulid(), aid, u, months[min(4, d + 1)] + "-20" if d < 3 else "2026-11-02", b, A["cash"] if b else None))
    active = app.execute("SELECT id, cost_minor, salvage_minor, life_months FROM fixed_assets WHERE status='active'").fetchall()
    assert balance(app, "fixed_equipment") == sum(x[1] for x in active)                                      # asset account = cost of what we still own
    accum = sum(app.execute("SELECT coalesce(sum(amount_minor),0) FROM fa_dep_lines WHERE asset_id=%s", (x[0],)).fetchone()[0] for x in active)
    assert -balance(app, "accum_depreciation") == accum                                                       # accumulated = what the active assets have charged
    for aid, cost_, salvage, life in app.execute("SELECT id, cost_minor, salvage_minor, life_months FROM fixed_assets").fetchall():
        total = app.execute("SELECT coalesce(sum(amount_minor),0), count(*) FROM fa_dep_lines WHERE asset_id=%s", (aid,)).fetchone()
        assert total[0] <= cost_ - salvage and total[1] <= life
    tb = app.execute("SELECT coalesce(sum(debit_minor),0), coalesce(sum(credit_minor),0) FROM acct_trial_balance('2099-12-31')").fetchone()
    assert tb[0] == tb[1]
    wipe(owner)
