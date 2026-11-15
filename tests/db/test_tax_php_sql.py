"""The SQL strings behind the withholding-tax screens (PgTaxRepo), run against real PG."""
import datetime
import json

from conftest import ulid
from test_accounting import acc, ctx  # noqa: F401
from test_accounting_docs import D, line, make, party, setup
from test_lead_php_sql import sql_of
from test_tax_withholding import clean, item  # noqa: F401  (autouse cleanup reused)

REPO = "app/Vat/PgTaxRepo.php"
TODAY = datetime.date.today().isoformat()


def test_repo_statements_run_end_to_end(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    s = setup(app, world)
    ctx(app, world, "a")
    assert app.execute(sql_of(REPO, "select tax_rule_save("), (ulid(), t, u, "tds", "TDS5", "সরবরাহ", 500, "2026-07-01")).fetchone()[0] == "ok"
    assert [tuple(r)[1:5] for r in app.execute(sql_of(REPO, "from tax_rules order by")).fetchall()] == [("tds", "TDS5", "সরবরাহ", 500)]
    p, c = party(app, world, "supplier"), party(app, world, "customer")
    bill, _ = make(app, world, "bill", p, [line(s["rent"], 100000)])
    inv, _ = make(app, world, "invoice", c, [line(s["sales"], 50000)])
    items = [item("vds", 15000), item("tds", 5000, cert="C-1")]
    assert app.execute(sql_of(REPO, "select tax_settle("), (ulid(), ulid(), bill, u, D, 100000, s["bank"], "n", json.dumps(items))).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select tax_settle("), (ulid(), ulid(), inv, u, D, 50000, s["bank"], None, json.dumps([item("tds", 1500)]))).fetchone()[0] == "ok"
    sm = app.execute(sql_of(REPO, "from tax_withholdings w join acct_payments p")).fetchall()
    assert {(r[0], r[1], r[2], r[3]) for r in sm} == {("deducted", "vds", False, 15000), ("deducted", "tds", False, 5000), ("suffered", "tds", False, 1500)}
    wl = app.execute(sql_of(REPO, "pa.name as party"), (50,)).fetchall()
    assert len(wl) == 3 and {r[2] for r in wl} == {"vds", "tds"} and all(r[-1] is False for r in wl)
    wid = next(r[0] for r in wl if r[8] == "C-1")
    assert app.execute(sql_of(REPO, "select tax_cert_set("), (wid, u, "NEW-9")).fetchone()[0] == "ok"
    r = app.execute(sql_of(REPO, "from tax_deposit("), (ulid(), ulid(), t, u, "vds", TODAY, TODAY, s["bank"], "CH-5")).fetchone()
    assert tuple(r) == ("ok", 15000)
    assert [tuple(r)[1:4] for r in app.execute(sql_of(REPO, "from tax_deposits t join accounts"), (10,)).fetchall()] == [("vds", datetime.date.today(), 15000)]
    assert app.execute(sql_of(REPO, "select tax_advance_pay("), (ulid(), ulid(), t, u, D, 7000, s["bank"], "R", "n")).fetchone()[0] == "ok"
    adv = app.execute(sql_of(REPO, "from tax_advances t join accounts"), (10,)).fetchall()
    assert len(adv) == 1 and adv[0][2] == 7000 and adv[0][5] is None
    assert app.execute(sql_of(REPO, "select tax_advance_void("), (ulid(), adv[0][0], u, D)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "coalesce(sum(amount_minor), 0) as s from tax_advances")).fetchone()[0] == 0
    assert [r[0] for r in app.execute(sql_of(REPO, "where cash_account and active")).fetchall()] != []
