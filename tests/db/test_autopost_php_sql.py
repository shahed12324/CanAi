"""The SQL strings behind the automatic-posting screen and command (PgAutopostRepo), run against real PG."""
import datetime

from conftest import as_admin, ulid
from test_accounting import acc, ctx  # noqa: F401
from test_accounting_docs import setup
from test_autopost import clean, deliver, enable, q_rows, run as run_posting  # noqa: F401  (autouse cleanup reused)
from test_lead_php_sql import sql_of

REPO = "app/Accounting/PgAutopostRepo.php"
CMD = "app/Console/Commands/AccountingAutopostCommand.php"


def test_repo_statements_run_end_to_end(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    A = setup(app, world)
    assert app.execute(sql_of(REPO, "select enabled from autopost_settings")).fetchone() is None
    assert app.execute(sql_of(REPO, "select autopost_set("), (t, u, True)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select enabled from autopost_settings")).fetchone()[0] is True
    oid, _ = deliver(app, world, courier="steadfast", cost=10000)
    deliver(app, world, phone="01811000002")
    c = app.execute(sql_of(REPO, "count(*) filter (where status = 'pending')")).fetchone()
    assert tuple(c) == (2, 0, 0, 0)
    res = app.execute(sql_of(REPO, "from autopost_run("), (t, u, 200)).fetchone()
    assert tuple(res) == (2, 0, 0)
    assert tuple(app.execute(sql_of(REPO, "count(*) filter (where status = 'pending')")).fetchone()) == (0, 0, 2, 0)
    q = app.execute(sql_of(REPO, "from autopost_queue q join store_orders"), (50,)).fetchall()
    assert len(q) == 2 and {r[2] for r in q} == {"posted"} and all(r[-1] is not None for r in q)             # the journal entry number is joined in
    rec = app.execute(sql_of(REPO, "from courier_receivable_report()")).fetchall()
    assert [tuple(r) for r in rec] == [("steadfast", 1, 56000, 0, 0, 56000)]
    cash = app.execute(sql_of(REPO, "where cash_account and active")).fetchall()
    assert [r[1] for r in cash] == ["1110", "1120", "1130"]
    sid = ulid()
    assert app.execute(sql_of(REPO, "select courier_settle("), (sid, ulid(), t, u, str(datetime.date.today()), "steadfast", 56000, 1000, A["bank"], "ok বুঝে পেলাম")).fetchone()[0] == "ok"
    s = app.execute(sql_of(REPO, "from courier_settlements s join accounts a"), (30,)).fetchall()
    assert len(s) == 1 and s[0][0].strip() == sid and s[0][3] == 56000 and s[0][4] == 1000 and s[0][6] is None
    assert [tuple(r) for r in app.execute(sql_of(REPO, "from courier_receivable_report()")).fetchall()] == [("steadfast", 1, 56000, 0, 56000, 0)]
    assert app.execute(sql_of(REPO, "select courier_settle_void("), (ulid(), sid, u, str(datetime.date.today()))).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select autopost_set("), (t, u, False)).fetchone()[0] == "ok"


def test_skip_statement(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    setup(app, world)
    enable(app, world)
    deliver(app, world)
    seq = app.execute("SELECT seq FROM autopost_queue").fetchone()[0]
    assert app.execute(sql_of(REPO, "select autopost_skip("), (seq, u, "by hand")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select autopost_skip("), (seq, u, "again")).fetchone()[0] == "not_open"


def test_command_statement_finds_each_waiting_workspace_once(app, world, admin):
    t, u = world["a"]["tenant"], world["a"]["user"]
    setup(app, world)
    enable(app, world)
    deliver(app, world, phone="01711000001")
    deliver(app, world, phone="01711000002")
    as_admin(admin)
    rows = admin.execute(sql_of(CMD, "from autopost_queue q")).fetchall()
    assert len(rows) == 1 and rows[0][0].strip() == t and rows[0][1] is not None                          # one row per workspace, with a member to attribute to
    run_posting(app, world)
    assert admin.execute(sql_of(CMD, "from autopost_queue q")).fetchall() == []
