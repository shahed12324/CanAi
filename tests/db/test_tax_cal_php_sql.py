"""The SQL strings behind the tax-calendar screen (PgTaxCalRepo), run against real PG."""
import datetime

from conftest import ulid
from test_accounting import ctx
from test_lead_php_sql import sql_of
from test_tax_calendar import clean  # noqa: F401  (autouse cleanup reused)

REPO = "app/Vat/PgTaxCalRepo.php"
TODAY = datetime.date.today().isoformat()


def test_repo_statements_run_end_to_end(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world, "a")
    ids = "{" + ",".join(ulid() for _ in range(5)) + "}"
    assert app.execute(sql_of(REPO, "select tax_cal_seed("), (t, u, ids)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select tax_cal_seed("), (t, u, ids)).fetchone()[0] == "already"
    rows = app.execute(sql_of(REPO, "from tax_obligations order by")).fetchall()
    assert len(rows) == 5 and all(r[8] is True for r in rows)
    oid = ulid()
    assert app.execute(sql_of(REPO, "select tax_obligation_save("), (oid, t, u, "লাইসেন্স", "license", "yearly", 10, 6, None, 14)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select tax_obligation_save("), (oid, t, u, "লাইসেন্স২", "license", "once", None, None, "2027-06-10", 3)).fetchone()[0] == "ok"
    row = [r for r in app.execute(sql_of(REPO, "from tax_obligations order by")).fetchall() if r[0].strip() == oid][0]
    assert (row[1], row[3], row[4], row[5], str(row[6]), row[7]) == ("লাইসেন্স২", "once", None, None, "2027-06-10", 3)
    assert app.execute(sql_of(REPO, "select tax_cal_done("), (oid, t, u, "2027-06-10", TODAY, "ঠিক")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "from tax_obligation_done where due_date"), ("2027-01-01", "2027-12-31")).fetchall()[0][1] == datetime.date(2027, 6, 10)
    assert app.execute(sql_of(REPO, "select tax_cal_undo("), (oid, t, "2027-06-10")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "from tax_obligation_done where due_date"), ("2027-01-01", "2027-12-31")).fetchall() == []
    assert app.execute(sql_of(REPO, "select tax_obligation_active("), (oid, t, "false")).fetchone()[0] == "ok"
    assert [r[8] for r in app.execute(sql_of(REPO, "from tax_obligations order by")).fetchall() if r[0].strip() == oid] == [False]
