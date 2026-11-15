"""The SQL strings behind the statement screens (PgBankRepo), run against real PG."""
import json

from conftest import ulid
from test_accounting import acc, cr, ctx, dr, post  # noqa: F401
from test_accounting_docs import setup
from test_bank_reconcile import clean, imp, jseq, row  # noqa: F401  (autouse cleanup reused)
from test_lead_php_sql import sql_of

REPO = "app/Accounting/PgBankRepo.php"


def test_repo_statements_run(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    A = setup(app, world)
    names = app.execute(sql_of(REPO, "from accounts where cash_account and active order by code")).fetchall()
    assert [r[1] for r in names] == ["1110", "1120", "1130"]
    e, r = post(app, world, [dr(A["bank"], 1000), cr(A["sales"], 1000)], date="2026-11-02")
    assert r[0] == "ok"
    res = app.execute(sql_of(REPO, "from bank_import("), (ulid(), t, u, A["bank"], "f.csv", json.dumps([row(1000, desc="in", bal=1000), row(-40, date="2026-11-03", desc="fee", bal=960)]))).fetchone()
    assert tuple(res) == ("ok", 2, 0)
    ls = app.execute(sql_of(REPO, "from bank_lines b left join journal_lines l"), (A["bank"], "unmatched", "unmatched", 200)).fetchall()
    assert [(x[4], x[6]) for x in ls] == [(-40, "unmatched"), (1000, "unmatched")]                      # newest first
    assert len(app.execute(sql_of(REPO, "from bank_lines b left join journal_lines l"), (A["bank"], "all", "all", 1)).fetchall()) == 1
    sug = app.execute(sql_of(REPO, "from bank_suggestions("), (A["bank"], 3)).fetchall()
    assert len(sug) == 1 and sug[0][1] == jseq(app, e, A["bank"])
    line = [x[0] for x in ls if x[4] == 1000][0]
    fee = [x[0] for x in ls if x[4] == -40][0]
    assert app.execute(sql_of(REPO, "select bank_match("), (line, sug[0][1], u)).fetchone()[0] == "ok"
    ml = app.execute(sql_of(REPO, "from bank_lines b left join journal_lines l"), (A["bank"], "matched", "matched", 200)).fetchall()
    assert ml[0][9] == 1 and ml[0][8].strip() == e                                                    # entry number and id come through the join
    assert app.execute(sql_of(REPO, "select bank_unmatch("), (line,)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select bank_auto_match("), (A["bank"], u, 3)).fetchone()[0] == 1
    assert app.execute(sql_of(REPO, "select bank_ignore("), (fee, "true")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select bank_ignore("), (fee, "false")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select bank_create_entry("), (fee, ulid(), u, acc(app, world, "bank_charges"), "SMS fee")).fetchone()[0] == "ok"
    rec = app.execute(sql_of(REPO, "from bank_reconciliation("), (A["bank"], "2026-11-30")).fetchone()
    assert (rec[0], rec[1], rec[3], rec[4]) == (960, 960, 2, 0)
    imps = app.execute(sql_of(REPO, "from bank_imports where account_id"), (A["bank"], 10)).fetchall()
    assert [(x[0], x[1], x[2], x[3]) for x in imps] == [("f.csv", 2, 2, 0)]
