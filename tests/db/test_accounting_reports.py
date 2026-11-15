import json

import pytest

from conftest import ulid
from test_accounting import acc, cr, ctx, dr, post, seed  # noqa: F401
from test_accounting_docs import clean, line, make, party, setup, wipe_all  # noqa: F401  (autouse cleanup reused)


def pl(app, a, b):
    return {r[1]: r[6] for r in app.execute("SELECT * FROM acct_profit_loss(%s,%s)", (a, b)).fetchall()}


def bs(app, d):
    out = {"asset": 0, "liability": 0, "equity": 0, "earnings": 0}
    for r in app.execute("SELECT * FROM acct_balance_sheet(%s)", (d,)).fetchall():
        out[r[0]] += r[5]
    return out


def cf(app, a, b):
    out = {}
    for r in app.execute("SELECT * FROM acct_cash_flow(%s,%s)", (a, b)).fetchall():
        out.setdefault(r[0], {})[r[2] or r[3]] = r[5]
    return out


def ok(r):
    assert r[1][0] == "ok", r


def test_profit_and_loss_by_period_with_reversals(app, world):
    A = setup(app, world)
    cogs = acc(app, world, "cogs")
    ok(post(app, world, [dr(A["cash"], 100000), cr(A["sales"], 100000)], date="2026-10-15"))
    e, r = post(app, world, [dr(A["cash"], 50000), cr(A["sales"], 50000)], date="2026-11-02")
    ok((e, r))
    ok(post(app, world, [dr(cogs, 30000), cr(A["cash"], 30000)], date="2026-11-02"))
    ok(post(app, world, [dr(A["rent"], 8000), cr(A["cash"], 8000)], date="2026-11-03"))
    assert pl(app, "2026-11-01", "2026-11-30") == {"4100": 50000, "5000": 30000, "6200": 8000}      # October's sale is not in November
    assert pl(app, "2026-10-01", "2026-10-31") == {"4100": 100000}
    assert pl(app, "2026-11-02", "2026-11-02") == {"4100": 50000, "5000": 30000}                       # both ends inclusive
    app.execute("SELECT journal_reverse(%s,%s,%s,%s::date,NULL)", (ulid(), e, world["a"]["user"], "2026-11-03"))
    assert pl(app, "2026-11-01", "2026-11-30") == {"5000": 30000, "6200": 8000}                        # the sale and its reversal cancel -> account vanishes
    assert pl(app, "2026-12-01", "2026-12-31") == {}
    assert pl(app, "2026-11-01", "2026-10-01") == {}                                                   # backwards range is empty, not an error


def test_balance_sheet_balances_and_shows_profit_to_date(app, world):
    A = setup(app, world)
    cap, loans = acc(app, world, "capital"), acc(app, world, "loans")
    equip = acc(app, world, "fixed_equipment")
    ok(post(app, world, [dr(A["bank"], 500000), cr(cap, 500000)], date="2026-10-01"))
    ok(post(app, world, [dr(A["cash"], 100000), cr(loans, 100000)], date="2026-10-02"))
    ok(post(app, world, [dr(equip, 200000), cr(A["bank"], 200000)], date="2026-10-03"))
    ok(post(app, world, [dr(A["cash"], 70000), cr(A["sales"], 70000)], date="2026-10-20"))
    ok(post(app, world, [dr(A["rent"], 20000), cr(A["cash"], 20000)], date="2026-10-21"))
    b = bs(app, "2026-10-31")
    assert b == {"asset": 500000 + 100000 - 200000 + 200000 + 70000 - 20000, "liability": 100000, "equity": 500000, "earnings": 50000}
    assert b["asset"] == b["liability"] + b["equity"] + b["earnings"]
    assert bs(app, "2026-10-02")["earnings"] == 0 and bs(app, "2026-10-02")["asset"] == 600000                    # as-of really filters
    assert bs(app, "2025-01-01") == {"asset": 0, "liability": 0, "equity": 0, "earnings": 0}                     # empty books -> zeros, still one earnings row
    rows = app.execute("SELECT * FROM acct_balance_sheet('2026-10-31')").fetchall()
    assert sum(1 for r in rows if r[0] == "earnings") == 1 and [r[0] for r in rows] == sorted(r[0] for r in rows)


def test_cash_flow_sections_and_transfers(app, world):
    A = setup(app, world)
    cap, loans, equip, draw = (acc(app, world, k) for k in ("capital", "loans", "fixed_equipment", "drawings"))
    ok(post(app, world, [dr(A["bank"], 500000), cr(cap, 500000)], date="2026-10-01"))                          # before the period -> opening
    ok(post(app, world, [dr(A["cash"], 60000), cr(A["sales"], 50000), cr(acc(app, world, "vat_output"), 10000)], date="2026-11-02"))
    ok(post(app, world, [dr(A["rent"], 8000), cr(A["cash"], 8000)], date="2026-11-03"))
    ok(post(app, world, [dr(equip, 150000), cr(A["bank"], 150000)], date="2026-11-03"))
    ok(post(app, world, [dr(A["bank"], 90000), cr(loans, 90000)], date="2026-11-04"))
    ok(post(app, world, [dr(draw, 5000), cr(A["cash"], 5000)], date="2026-11-04"))
    ok(post(app, world, [dr(A["bank"], 20000), cr(A["cash"], 20000)], date="2026-11-04"))                      # bank <-> cash: no effect on totals
    ok(post(app, world, [dr(A["bank"], 1000), dr(A["rent"], 500), cr(A["cash"], 1500)], date="2026-11-04"))    # mixed: cash line moves bank and cash
    f = cf(app, "2026-11-01", "2026-11-30")
    assert f["opening"]["Opening cash"] == 500000
    assert f["operating"] == {"4100": 50000, "2200": 10000, "6200": -8500}
    assert f["investing"] == {"1610": -150000}
    assert f["financing"] == {"2600": 90000, "3200": -5000}
    total = sum(sum(v.values()) for k, v in f.items() if k not in ("opening", "closing"))
    assert f["opening"]["Opening cash"] + total == f["closing"]["Closing cash"] == 500000 + 60000 - 8000 - 150000 + 90000 - 5000 - 500
    assert cf(app, "2026-10-01", "2026-10-01")["opening"]["Opening cash"] == 0
    assert "operating" not in cf(app, "2026-12-01", "2026-12-31")                                                  # quiet month: only opening and closing
    assert cf(app, "2026-12-01", "2026-12-31")["opening"] == {"Opening cash": f["closing"]["Closing cash"]}


def test_marking_another_account_as_cash_changes_the_flow(app, world):
    A = setup(app, world)
    cap = acc(app, world, "capital")
    prepaid = acc(app, world, "prepaid")
    app.execute("SELECT acct_account_cash(%s, true)", (prepaid,))
    ok(post(app, world, [dr(prepaid, 1000), cr(cap, 1000)], date="2026-11-02"))
    assert cf(app, "2026-11-01", "2026-11-30")["financing"] == {"3100": 1000}


def test_user_added_accounts_are_classified_by_code(app, world):
    A = setup(app, world)
    t = world["a"]["tenant"]
    for code, name, typ in (("2650", "Bank Loan", "liability"), ("2350", "Other Payable", "liability"), ("1650", "Vehicles", "asset")):
        assert app.execute("SELECT acct_account_add(%s,%s,%s,%s,NULL,%s,NULL)", (ulid(), t, code, name, typ)).fetchone()[0] == "ok"
    ids = {c: app.execute("SELECT id FROM accounts WHERE code=%s", (c,)).fetchone()[0].strip() for c in ("2650", "2350", "1650")}
    ok(post(app, world, [dr(A["cash"], 900), cr(ids["2650"], 900)], date="2026-11-02"))
    ok(post(app, world, [dr(A["cash"], 90), cr(ids["2350"], 90)], date="2026-11-02"))
    ok(post(app, world, [dr(ids["1650"], 400), cr(A["cash"], 400)], date="2026-11-02"))
    f = cf(app, "2026-11-01", "2026-11-30")
    assert f["financing"] == {"2650": 900} and f["operating"] == {"2350": 90} and f["investing"] == {"1650": -400}


def test_reports_are_per_workspace_and_read_only(app, world):
    A = setup(app, world)
    ok(post(app, world, [dr(A["cash"], 777), cr(A["sales"], 777)], date="2026-11-02"))
    seed(app, world, "b")
    assert pl(app, "2026-11-01", "2026-11-30") == {}
    assert bs(app, "2026-11-30") == {"asset": 0, "liability": 0, "equity": 0, "earnings": 0}
    assert cf(app, "2026-11-01", "2026-11-30")["closing"]["Closing cash"] == 0
    ctx(app, world, "a")
    assert pl(app, "2026-11-01", "2026-11-30") == {"4100": 777}
    with pytest.raises(Exception):
        app.execute("INSERT INTO journal_lines(id) VALUES ('x')")


# ---------------------------------------------------------------------------------------------------------------------
hyp = pytest.importorskip("hypothesis")
from hypothesis import HealthCheck, given, settings, strategies as st  # noqa: E402

KEYS = ["cash", "bank", "mobile_wallet", "receivable", "inventory", "fixed_equipment", "prepaid", "payable", "vat_output", "loans", "capital", "drawings", "sales", "cogs", "rent", "salaries", "interest_expense"]
DAYS = ["2026-10-05", "2026-10-20", "2026-11-01", "2026-11-02", "2026-11-03"]
entry = st.tuples(st.sampled_from(KEYS), st.sampled_from(KEYS), st.integers(min_value=1, max_value=900_000), st.sampled_from(DAYS), st.booleans())


@settings(max_examples=40, deadline=None, suppress_health_check=[HealthCheck.function_scoped_fixture])
@given(st.lists(entry, min_size=1, max_size=10))
def test_property_statements_always_agree(app, world, owner, entries):
    setup(app, world)
    ids = {k: acc(app, world, k) for k in KEYS}
    for a, b, n, day, split in entries:
        if a == b:
            continue
        lines = [dr(ids[a], n), cr(ids[b], n)] if not split or n < 2 else [dr(ids[a], n), cr(ids[b], n // 2), cr(ids["vat_output"], n - n // 2)]
        post(app, world, lines, date=day)
    for d in ("2026-10-10", "2026-11-01", "2026-11-03"):
        b = bs(app, d)
        assert b["asset"] == b["liability"] + b["equity"] + b["earnings"], (entries, d)                   # the accounting equation
        profit = sum(v if code[0] == "4" else -v for code, v in pl(app, "2000-01-01", d).items())
        assert profit == b["earnings"], (entries, d)                                                       # P&L over all time = balance sheet earnings
    for a, b2 in (("2026-10-01", "2026-10-31"), ("2026-11-01", "2026-11-03"), ("2026-10-15", "2026-11-02")):
        f = cf(app, a, b2)
        moved = sum(sum(v.values()) for k, v in f.items() if k not in ("opening", "closing"))
        assert f["opening"]["Opening cash"] + moved == f["closing"]["Closing cash"], (entries, a, b2)      # cash flow reconciles to the cash books
        cash_total = app.execute("SELECT coalesce(sum(balance_minor),0) FROM acct_cash_balances(%s)", (b2,)).fetchone()[0]
        assert f["closing"]["Closing cash"] == cash_total
    wipe_all(owner)
