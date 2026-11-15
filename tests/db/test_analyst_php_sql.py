"""The SQL strings behind the analyst (PgAnalystRepo), run against real PG with a real journal."""
import json

from conftest import ulid
from test_accounting import acc, cr, ctx, dr, post, seed
from test_analyst import clean  # noqa: F401  (autouse cleanup reused)
from test_lead_php_sql import sql_of

REPO = "app/Analyst/PgAnalystRepo.php"


def test_monthly_cash_and_open_items(app, world):
    seed(app, world)
    cash, sales, rent, ar, ap = (acc(app, world, k) for k in ("cash", "sales", "rent", "receivable", "payable"))
    post(app, world, [dr(cash, 500000), cr(sales, 500000)], date="2026-09-10")
    post(app, world, [dr(cash, 300000), cr(sales, 300000)], date="2026-10-02")
    post(app, world, [dr(rent, 100000), cr(cash, 100000)], date="2026-10-05")
    post(app, world, [dr(ar, 70000), cr(sales, 70000)], date="2026-10-20")
    post(app, world, [dr(rent, 40000), cr(ap, 40000)], date="2026-10-21")
    post(app, world, [dr(cash, 999), cr(sales, 999)], date="2026-11-03")              # outside the window
    ctx(app, world, "a")
    rows = app.execute(sql_of(REPO, "from journal_lines l join journal_entries e on e.id = l.entry_id and e.tenant_id = l.tenant_id join accounts a on a.id = l.account_id and a.tenant_id = l.tenant_id\n                where a.type in"), ("2026-09-01", "2026-10-31")).fetchall()
    got = {(r[0], r[1]): r[4] for r in rows}
    assert got == {("2026-09", "income"): 500000, ("2026-10", "income"): 370000, ("2026-10", "expense"): 140000}
    cashm = app.execute(sql_of(REPO, "where a.cash_account and e.entry_date between"), ("2026-09-01", "2026-10-31")).fetchall()
    assert cashm == [("2026-09", 500000), ("2026-10", 200000)]
    assert app.execute(sql_of(REPO, "where a.cash_account and e.entry_date <="), ("2026-10-31",)).fetchone()[0] == 700000
    assert app.execute(sql_of(REPO, "where a.cash_account and e.entry_date <="), ("2026-09-30",)).fetchone()[0] == 500000
    assert app.execute(sql_of(REPO, "where a.system_key in ('receivable', 'payable')")).fetchone() == (70000, 40000)


def test_reversal_nets_out_and_other_tenants_are_invisible(app, world):
    seed(app, world, "a")
    seed(app, world, "b")
    cash, sales = acc(app, world, "cash"), acc(app, world, "sales")
    eid, _ = post(app, world, [dr(cash, 1000), cr(sales, 1000)], date="2026-10-02")
    ctx(app, world, "a")
    assert app.execute("SELECT journal_reverse(%s,%s,%s,%s::date,%s)", (ulid(), eid, world["a"]["user"], "2026-10-03", "undo")).fetchone()[0] == "ok"
    bc, bs = acc(app, world, "cash", "b"), acc(app, world, "sales", "b")
    post(app, world, [dr(bc, 77700), cr(bs, 77700)], who="b", date="2026-10-02")
    ctx(app, world, "a")
    rows = app.execute(sql_of(REPO, "where a.type in"), ("2026-10-01", "2026-10-31")).fetchall()
    assert [(r[1], r[4]) for r in rows] == [("income", 0)]                       # +1000 and the reversal cancel; B's 777 is not here
    assert app.execute(sql_of(REPO, "where a.cash_account and e.entry_date <="), ("2026-10-31",)).fetchone()[0] == 0


def test_report_roundtrip(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world, "a")
    rid = ulid()
    assert app.execute(sql_of(REPO, "select analyst_report_save("), (rid, t, u, "2026-10", json.dumps({"k": 1}), json.dumps({"enough": False}), "লেখা", "template", None, None, 0, 0, "0")).fetchone()[0] == "ok"
    r = app.execute(sql_of(REPO, "from analyst_reports where id"), (rid,)).fetchone()
    assert r[0].strip() == rid and r[1].strip() == "2026-10" and r[2] == {"k": 1} and r[3] == {"enough": False} and r[4] == "লেখা" and r[5] == "template"
    assert [x[0].strip() for x in app.execute(sql_of(REPO, "from analyst_reports order by created_at desc"), (5,)).fetchall()] == [rid]
