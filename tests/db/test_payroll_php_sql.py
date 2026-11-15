"""The SQL strings behind the payroll screens (PgPayrollRepo), run against real PG."""
import datetime

from conftest import ulid
from test_accounting import ctx
from test_accounting_docs import setup
from test_lead_php_sql import sql_of
from test_payroll import PERIOD, TODAY, clean  # noqa: F401  (autouse cleanup reused)

REPO = "app/Payroll/PgPayrollRepo.php"


def test_repo_statements_run_end_to_end(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    s = setup(app, world)
    ctx(app, world, "a")
    e = ulid()
    assert app.execute(sql_of(REPO, "select payroll_employee_save("), (e, t, u, "E1", "করিম", "ম্যানেজার", "2024-01-01", None)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select payroll_component_save("), (ulid(), t, u, e, "earning", "false", "মূল বেতন", 3000000, "2024-01-01")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select payroll_component_save("), (ulid(), t, u, e, "deduction", "true", "আয়কর", 100000, "2024-01-01")).fetchone()[0] == "ok"
    emps = app.execute(sql_of(REPO, "from payroll_employees e left join payroll_components c")).fetchall()
    assert len(emps) == 1 and emps[0][1] == "E1" and emps[0][7] == 3000000                       # gross counts earnings only
    one = app.execute(sql_of(REPO, "from payroll_employees where id"), (e,)).fetchone()
    assert one[2] == "করিম" and one[6] is True
    comps = app.execute(sql_of(REPO, "from payroll_components where employee_id"), (e,)).fetchall()
    assert [(c[1], c[2], c[4]) for c in comps] == [("earning", False, 3000000), ("deduction", True, 100000)]
    rid = ulid()
    assert app.execute(sql_of(REPO, "select payroll_run_create("), (rid, t, u, PERIOD)).fetchone()[0] == "ok"
    runs = app.execute(sql_of(REPO, "from payroll_runs r order by"), (10,)).fetchall()
    assert len(runs) == 1 and runs[0][1] == PERIOD and runs[0][7] == 1
    assert app.execute(sql_of(REPO, "select payroll_adjust_add("), (rid, t, e, "bonus", "ঈদ", 500000, "false")).fetchone()[0] == "ok"
    head = app.execute(sql_of(REPO, "from payroll_runs where id"), (rid,)).fetchone()
    assert head[3:7] == (3500000, 100000, 100000, 3400000)
    lines = app.execute(sql_of(REPO, "from payroll_lines l join payroll_employees e"), (rid,)).fetchall()
    assert len(lines) == 1 and lines[0][1] == "E1" and len(lines[0][3]) == 3
    bonus = next(i["id"] for i in lines[0][3] if i["src"] == "bonus")
    assert app.execute(sql_of(REPO, "select payroll_adjust_remove("), (rid, t, e, bonus)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select payroll_run_post("), (rid, t, u, ulid(), TODAY.isoformat())).fetchone()[0] == "ok"
    assert [r["id"] if isinstance(r, dict) else r[0] for r in app.execute(sql_of(REPO, "where cash_account and active")).fetchall()].count(s["bank"]) == 1
    assert app.execute(sql_of(REPO, "select payroll_run_pay("), (rid, t, u, ulid(), TODAY.isoformat(), s["bank"])).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "from payroll_runs where id"), (rid,)).fetchone()[2] == "paid"
    comp = comps[0][0]
    assert app.execute(sql_of(REPO, "select payroll_component_end("), (comp, t, TODAY.isoformat())).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select payroll_employee_active("), (e, t, "false")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "from payroll_employees where id"), (e,)).fetchone()[6] is False
    nxt = ulid()
    assert app.execute(sql_of(REPO, "select payroll_run_create("), (nxt, t, u, (TODAY.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m"))).fetchone()[0] == "nothing"      # the only employee is now inactive
    assert app.execute(sql_of(REPO, "select payroll_run_void("), (rid, t, u, ulid(), TODAY.isoformat())).fetchone()[0] == "paid"
