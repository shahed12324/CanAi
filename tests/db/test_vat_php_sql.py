"""The SQL strings behind the VAT screens (PgVatRepo), run against real PG."""
import datetime
import json

from conftest import ulid
from test_accounting import acc, ctx  # noqa: F401
from test_accounting_docs import D, line, make, party, setup
from test_lead_php_sql import sql_of
from test_vat import clean  # noqa: F401  (autouse cleanup reused)

REPO = "app/Vat/PgVatRepo.php"


def test_repo_statements_run_end_to_end(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    s = setup(app, world)
    ctx(app, world, "a")
    assert app.execute(sql_of(REPO, "from vat_settings limit 1")).fetchone() is None
    assert app.execute(sql_of(REPO, "select vat_settings_save("), (t, u, True, "123456789", "quarterly")).fetchone()[0] == "ok"
    assert tuple(app.execute(sql_of(REPO, "from vat_settings limit 1")).fetchone()) == (True, "123456789", "quarterly")
    assert app.execute(sql_of(REPO, "select vat_rate_seed("), (t, u)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select vat_rate_save("), (ulid(), t, u, "STD", "নতুন", 1000, "2027-01-01")).fetchone()[0] == "ok"
    rates = app.execute(sql_of(REPO, "from vat_rates order by")).fetchall()
    assert [(r[1], r[3]) for r in rates] == [("STD", 1000), ("STD", 1500), ("TRADER", 750), ("ZERO", 0)] and rates[1][5] is not None
    c, p = party(app, world, "customer"), party(app, world, "supplier")
    make(app, world, "invoice", c, [line(s["sales"], 100000)], vat=15000)
    bill, _ = make(app, world, "bill", p, [line(s["rent"], 50000)], vat=7500)
    assert app.execute(sql_of(REPO, "select vat_doc_flag("), (bill, u, False, "মূসক-৬.৩ নেই")).fetchone()[0] == "ok"
    docs = app.execute(sql_of(REPO, "from acct_docs d left join acct_parties"), ("2026-11-01", "2026-11-30")).fetchall()
    assert {(d[1], d[5], d[6], d[9]) for d in docs} == {("invoice", 100000, 15000, None), ("bill", 50000, 7500, False)}
    fig = {"output_vat_minor": 15000, "input_vat_minor": 0, "net_minor": 15000}
    r = app.execute(sql_of(REPO, "from vat_return_save("), (ulid(), t, u, "2026-11-01", "2026-11-30", json.dumps(fig), "খসড়া")).fetchone()
    assert r[0] == "ok"
    assert app.execute(sql_of(REPO, "where period_from = ?::date"), ("2026-11-01", "2026-11-30", "draft")).fetchone()[0].strip() == r[1].strip()
    assert app.execute(sql_of(REPO, "select vat_return_file("), (r[1], u, datetime.date.today().isoformat(), "NBR-9")).fetchone()[0] == "ok"
    rows = app.execute(sql_of(REPO, "from vat_returns order by period_from desc"), (10,)).fetchall()
    assert len(rows) == 1 and rows[0][3] == "filed" and rows[0][4]["net_minor"] == 15000
