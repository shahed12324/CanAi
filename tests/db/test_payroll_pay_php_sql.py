"""The SQL strings behind the pay-details screens (PgPayrollRepo), run against real PG."""
from test_accounting import ctx
from test_lead_php_sql import sql_of
from test_payroll import emp
from test_payroll_pay import clean  # noqa: F401  (autouse cleanup reused)

REPO = "app/Payroll/PgPayrollRepo.php"


def test_repo_statements(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    e = emp(app, world)
    ctx(app, world, "a")
    assert app.execute(sql_of(REPO, "select payroll_pay_details_save("), (t, u, e, "bank", "ডাচ-বাংলা", "গুলশান", "090261234", "12345678", None)).fetchone()[0] == "ok"
    rows = app.execute(sql_of(REPO, "from payroll_pay_details")).fetchall()
    assert len(rows) == 1 and rows[0][0].strip() == e and rows[0][1] == "bank" and rows[0][5] == "12345678" and rows[0][4].strip() == "090261234"
    assert app.execute(sql_of(REPO, "select payroll_pay_details_save("), (t, u, e, "bkash", None, None, None, None, "01712345678")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "from payroll_pay_details")).fetchall()[0][6].strip() == "01712345678"
    assert app.execute(sql_of(REPO, "from tenants where id"), (t,)).fetchone() is not None
