"""The SQL strings behind the bill-scan screens (PgBillScanRepo), run against real PG."""
import json

from conftest import ulid
from test_accounting import acc, ctx  # noqa: F401
from test_accounting_docs import D, line, party, setup
from test_bill_scan import DRAFT, clean, sha  # noqa: F401  (autouse cleanup reused)
from test_lead_php_sql import sql_of

REPO = "app/BillScan/PgBillScanRepo.php"


def test_repo_statements_run_end_to_end(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    setup(app, world)
    p = party(app, world, "supplier")
    rent = acc(app, world, "rent")
    sid = ulid()
    ctx(app, world, "a")
    assert tuple(app.execute(sql_of(REPO, "from bill_scan_start("), (sid, t, u, "bill.jpg", "image/jpeg", 2048, sha("x"))).fetchone()) == ("ok", sid)
    again = app.execute(sql_of(REPO, "from bill_scan_start("), (ulid(), t, u, "copy.jpg", "image/jpeg", 2048, sha("x"))).fetchone()
    assert tuple(again) == ("duplicate", sid)
    assert app.execute(sql_of(REPO, "select bill_scan_ready("), (sid, json.dumps(DRAFT), '{"total_mismatch","low_confidence"}', "claude-x", 1200, 180, "0.0450")).fetchone()[0] == "ok"
    row = app.execute(sql_of(REPO, "from bill_scans where id = ?"), (sid,)).fetchone()
    assert row[0].strip() == sid and row[5] == "ready" and row[12] == ["total_mismatch", "low_confidence"] and row[11]["lines"][0]["amount_minor"] == 80000
    recent = app.execute(sql_of(REPO, "from bill_scans order by created_at"), (30,)).fetchall()
    assert len(recent) == 1 and recent[0][2] == "ready"
    lines = [{"account_id": rent, "description": "Paper", "amount_minor": 80000}]
    r = app.execute(sql_of(REPO, "from bill_scan_post("), (sid, ulid(), ulid(), u, "bill", p, D, None, "INV-9", json.dumps(lines), 0, None)).fetchone()
    assert r[0] == "ok" and r[2] == "BILL-0001" and r[3] == 80000
    assert app.execute(sql_of(REPO, "from bill_scans where id = ?"), (sid,)).fetchone()[5] == "used"


def test_fail_and_discard_statements(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world, "a")
    a, b = ulid(), ulid()
    for i, s in ((a, "1"), (b, "2")):
        app.execute(sql_of(REPO, "from bill_scan_start("), (i, t, u, f"{s}.png", "image/png", 10, sha(s)))
    assert app.execute(sql_of(REPO, "select bill_scan_fail("), (a, "unreadable", "claude-x", 900, 20, "0.0100")).fetchone()[0] == "ok"
    st = app.execute("SELECT status, error, input_tokens, credits FROM bill_scans WHERE id=%s", (a,)).fetchone()
    assert st[0] == "failed" and st[1] == "unreadable" and st[2] == 900
    assert app.execute(sql_of(REPO, "select bill_scan_ready("), (b, json.dumps(DRAFT), "{}", None, 1, 1, "0")).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select bill_scan_discard("), (b, u)).fetchone()[0] == "ok"
    assert app.execute(sql_of(REPO, "select bill_scan_discard("), (b, u)).fetchone()[0] == "not_open"
