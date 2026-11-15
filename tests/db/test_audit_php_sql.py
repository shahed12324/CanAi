"""The SQL strings behind the auditor screen (PgAuditRepo), run against real PG."""
import datetime

from conftest import ulid
from test_accounting import ctx
from test_accounting_docs import line, make, party, pay, setup
from test_audit import clean_audit  # noqa: F401  (autouse cleanup reused)
from test_lead_php_sql import sql_of

REPO = "app/Audit/PgAuditRepo.php"
TODAY = datetime.date.today()


def test_repo_statements_run_end_to_end(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    s = setup(app, world)
    p = party(app, world, "supplier")
    make(app, world, "bill", p, [line(s["rent"], 777)])
    make(app, world, "bill", p, [line(s["rent"], 777)])
    ctx(app, world, "a")
    assert app.execute(sql_of(REPO, "select audit_settings_save("), (t, u, 4, 6, 5, 40, 6000000, 200000, 2000000)).fetchone()[0] == "ok"
    assert tuple(app.execute(sql_of(REPO, "from audit_settings")).fetchone()) == (4, 6, 5, 40, 6000000, 200000, 2000000)
    assert app.execute(sql_of(REPO, "select audit_scan("), (t, u)).fetchone()[0] == 1
    rows = app.execute(sql_of(REPO, "from audit_findings f left join users"), (None, None, 50)).fetchall()
    assert len(rows) == 1 and rows[0][1] == "dup_doc" and rows[0][7] == "open" and rows[0][11] is None
    fid = rows[0][0]
    assert app.execute(sql_of(REPO, "from audit_findings f left join users"), ("dismissed", "dismissed", 50)).fetchall() == []
    assert app.execute(sql_of(REPO, "select audit_review("), (fid, t, u, "dismissed", None)).fetchone()[0] == "need_note"
    assert app.execute(sql_of(REPO, "select audit_review("), (fid, t, u, "dismissed", "দুটি আলাদা ভাড়া")).fetchone()[0] == "ok"
    rows = app.execute(sql_of(REPO, "from audit_findings f left join users"), ("dismissed", "dismissed", 50)).fetchall()
    assert len(rows) == 1 and rows[0][8] == "দুটি আলাদা ভাড়া" and rows[0][11] is not None
    assert {r[0]: r[1] for r in app.execute(sql_of(REPO, "group by status")).fetchall()} == {"dismissed": 1}
    health = {r[0]: (r[1], r[2], r[3]) for r in app.execute(sql_of(REPO, "from audit_health("), (t,)).fetchall()}
    assert health["books_balanced"][0] is True and set(health) == {"open_high", "books_balanced", "stale_bank_lines", "overdue_bills", "tax_undeposited", "period_locked"}
    assert app.execute(sql_of(REPO, "select audit_item_seed("), (t, u)).fetchone()[0] == "ok"
    per = TODAY.strftime("%Y-%m")
    items = app.execute(sql_of(REPO, "from audit_items i left join audit_item_done"), (per,)).fetchall()
    assert len(items) == 8 and all(r[4] is None for r in items)
    iid = items[0][0]
    assert app.execute(sql_of(REPO, "select audit_item_done("), (iid, t, u, per, TODAY.isoformat(), "ok")).fetchone()[0] == "ok"
    items = app.execute(sql_of(REPO, "from audit_items i left join audit_item_done"), (per,)).fetchall()
    assert [r[4] for r in items].count(None) == 7 and items[0][5] == "ok"
    other = (TODAY.replace(day=1) - datetime.timedelta(days=1)).strftime("%Y-%m")
    assert all(r[4] is None for r in app.execute(sql_of(REPO, "from audit_items i left join audit_item_done"), (other,)).fetchall())     # ticks are per month
    assert app.execute(sql_of(REPO, "select audit_item_undo("), (iid, t, per)).fetchone()[0] == "ok"
    new = ulid()
    assert app.execute(sql_of(REPO, "select audit_item_save("), (new, t, u, "নতুন", 5)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select audit_item_active("), (new, t, "false")).fetchone()[0] == "ok"
    mine = [r for r in app.execute(sql_of(REPO, "from audit_items i left join audit_item_done"), (per,)).fetchall() if r[0].strip() == new]
    assert mine and mine[0][3] is False
    assert [r[3] for r in app.execute(sql_of(REPO, "from audit_items i left join audit_item_done"), (per,)).fetchall()][-1] is False      # inactive ones sort last
