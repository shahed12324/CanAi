"""The SQL strings behind the statement screens (PgReportRepo), run against real PG."""
from test_accounting import cr, dr, post  # noqa: F401
from test_accounting_docs import clean, setup  # noqa: F401  (autouse cleanup reused)
from test_lead_php_sql import sql_of

REPO = "app/Accounting/PgReportRepo.php"


def test_repo_statements_run(app, world):
    A = setup(app, world)
    assert post(app, world, [dr(A["cash"], 1000), cr(A["sales"], 1000)], date="2026-11-02")[1][0] == "ok"
    assert post(app, world, [dr(A["rent"], 300), cr(A["cash"], 300)], date="2026-11-03")[1][0] == "ok"
    pl = app.execute(sql_of(REPO, "from acct_profit_loss("), ("2026-11-01", "2026-11-30")).fetchall()
    assert [(r[1], r[6]) for r in pl] == [("4100", 1000), ("6200", 300)]
    bs = app.execute(sql_of(REPO, "from acct_balance_sheet("), ("2026-11-30",)).fetchall()
    assert [(r[0], r[5]) for r in bs] == [("asset", 700), ("earnings", 700)]
    cf = app.execute(sql_of(REPO, "from acct_cash_flow("), ("2026-11-01", "2026-11-30")).fetchall()
    assert {r[0]: r[5] for r in cf if r[0] in ("opening", "closing")} == {"opening": 0, "closing": 700}
    assert sorted((r[0], r[5]) for r in cf if r[0] not in ("opening", "closing")) == [("operating", -300), ("operating", 1000)]
