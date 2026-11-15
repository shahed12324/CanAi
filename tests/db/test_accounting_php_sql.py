"""The SQL strings behind the accounting screens (PgAccountingRepo), run against real PG."""
import json

from conftest import ulid
from test_accounting import acc, clean, ctx, dr, cr, post, sale, seed  # noqa: F401  (autouse cleanup reused)
from test_lead_php_sql import sql_of

REPO = "app/Accounting/PgAccountingRepo.php"


def test_repo_statements_run(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world)
    assert app.execute(sql_of(REPO, "from acct_settings limit 1")).fetchone() is None                  # before first use: no row, the screen shows defaults
    seed(app, world)
    st = app.execute(sql_of(REPO, "from acct_settings limit 1")).fetchone()
    assert st[0] is None and st[1] is not None
    rows = app.execute(sql_of(REPO, "from accounts order by code")).fetchall()
    assert len(rows) >= 45 and rows[0][1] == "1000"
    e, _ = sale(app, world, 12345)
    ent = app.execute(sql_of(REPO, "join lateral (select sum(debit_minor)"), (50,)).fetchall()
    assert len(ent) == 1 and ent[0][6] == 12345
    newer, _ = sale(app, world, 700, date="2026-11-02")                                             # later number AND later date
    ent2 = app.execute(sql_of(REPO, "join lateral (select sum(debit_minor)"), (50,)).fetchall()
    assert [r[1] for r in ent2] == [2, 1]                                                           # newest first
    one = app.execute(sql_of(REPO, "from journal_entries where id = ?"), (e,)).fetchone()
    assert one[1] == 1
    lines = app.execute(sql_of(REPO, "from journal_lines l join accounts a"), (e,)).fetchall()
    assert [(r[1], r[4], r[5]) for r in lines] == [("1110", 12345, 0), ("4100", 0, 12345)]
    assert app.execute(sql_of(REPO, "from journal_entries where reverses_id = ?"), (e,)).fetchone() is None
    # statements that call functions
    new = ulid()
    assert app.execute(sql_of(REPO, "journal_reverse"), (new, e, u, "2026-11-02", None)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "from journal_entries where reverses_id = ?"), (e,)).fetchone()[1] == 3
    res = app.execute(sql_of(REPO, "from journal_post("), (ulid(), t, u, "2026-11-03", "via repo", "manual", None,
                                                          json.dumps([dr(acc(app, world, "cash"), 500), cr(acc(app, world, "sales"), 500)]))).fetchone()
    assert res[0] == "ok" and res[2] == 4
    tb = app.execute(sql_of(REPO, "from acct_trial_balance"), ("2026-12-31",)).fetchall()
    assert [(r[1], r[5], r[6]) for r in tb] == [("1110", 1200, 0), ("4100", 0, 1200)]
    led = app.execute(sql_of(REPO, "from acct_ledger"), (acc(app, world, "cash"), "2026-11-01", "2026-11-30")).fetchall()
    assert len(led) == 5                                                                             # opening + two sales + the reversal + the repo entry
    assert app.execute(sql_of(REPO, "select acct_lock"), (t, "2026-10-31", u)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select acct_unlock"), (t, None, u, "fixing a mistake")).fetchone()[0] == "ok"
    log = app.execute(sql_of(REPO, "from acct_lock_log order by seq desc"), (10,)).fetchall()
    assert [l[0] for l in log] == ["unlock", "lock"]
    cash = acc(app, world, "cash")
    app.execute(sql_of(REPO, "update accounts set active"), ("false", cash))
    assert app.execute("SELECT active FROM accounts WHERE id=%s", (cash,)).fetchone()[0] is False
    added = app.execute(sql_of(REPO, "acct_account_add"), (ulid(), t, "6960", "Gym", None, "expense", None)).fetchone()[0]
    assert added == "ok"
