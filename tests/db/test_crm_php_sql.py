"""The SQL strings in PgCrmStore.php and the CRM controllers, executed for real with the parameter shapes PHP passes."""
import json
from pathlib import Path

import pytest

from conftest import as_admin, set_tenant, ulid
from test_lead_php_sql import OVERLAY, sql_of

STORE = "app/Crm/PgCrmStore.php"
BOARD = "app/Http/Controllers/LeadBoardController.php"
CSVC = "app/Http/Controllers/LeadCsvController.php"
LEADC = "app/Http/Controllers/LeadController.php"
LEADS = "app/Leads/PgLeadStore.php"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("lead_activities", "crm_tasks", "lead_research_items", "leads", "contacts", "companies", "lead_research_jobs"):
        owner.execute(f"DELETE FROM {t}")


def make_lead(conn, t, domain, score=50):
    c, l = ulid(), ulid()
    conn.execute("INSERT INTO companies (id,tenant_id,name,domain) VALUES (%s,%s,%s,%s)", (c, t, domain, domain))
    conn.execute("INSERT INTO leads (id,tenant_id,company_id,score) VALUES (%s,%s,%s,%s)", (l, t, c, score))
    return l, c


def test_store_statements(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    lid, cid = make_lead(app, t, "alo.com")

    row = app.execute(sql_of(STORE, "select id, stage, tenant_id from leads"), (lid,)).fetchone()
    assert row[1] == "new" and row[2] == t
    assert app.execute(sql_of(STORE, "select lead_move_stage"), (lid, "proposal", u)).fetchone()[0] == "new"
    assert app.execute(sql_of(STORE, "select id from leads where company_id"), (cid,)).fetchone()[0] == lid

    app.execute(sql_of(STORE, "insert into lead_activities"), (ulid(), t, lid, u, "note", "কল হয়েছে", json.dumps({})))
    app.execute(sql_of(STORE, "insert into lead_activities"), (ulid(), t, lid, None, "task", "x", json.dumps({"event": "created"})))

    task = ulid()
    app.execute(sql_of(STORE, "insert into crm_tasks"), (task, t, lid, "Call", "2026-11-05 08:30:00+00:00", u, u))
    done = app.execute(sql_of(STORE, "update crm_tasks set done_at"), (task,)).fetchone()
    assert done[0] == lid and done[1] == t and done[2] == "Call"
    assert app.execute(sql_of(STORE, "update crm_tasks set done_at"), (task,)).fetchone() is None      # only once

    app.execute(sql_of(STORE, "update leads set tags"), ('{"vip","ঢাকা","web-design"}', lid))
    assert app.execute("SELECT tags FROM leads WHERE id=%s", (lid,)).fetchone()[0] == ["vip", "ঢাকা", "web-design"]
    app.execute(sql_of(STORE, "update leads set tags"), ("{}", lid))
    app.execute(sql_of(STORE, "update leads set next_follow_up_at"), ("2026-11-06 03:00:00+00:00", lid))
    assert app.execute("SELECT next_follow_up_at IS NOT NULL FROM leads WHERE id=%s", (lid,)).fetchone()[0]
    app.execute(sql_of(STORE, "update leads set next_follow_up_at"), (None, lid))


def test_read_queries_for_board_tasks_export_and_detail(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    l1, c1 = make_lead(app, t, "alo.com", 80)
    l2, _ = make_lead(app, t, "beta.com", 40)
    app.execute("UPDATE leads SET tags=%s, next_follow_up_at=now()+interval '1 day' WHERE id=%s", (["vip", "ঢাকা"], l1))
    app.execute("SELECT lead_move_stage(%s,'qualified',%s)", (l2, u))
    app.execute("INSERT INTO crm_tasks (id,tenant_id,lead_id,title,assignee_id) VALUES (%s,%s,%s,'Call',%s)", (ulid(), t, l1, u))
    app.execute("INSERT INTO contacts (id,tenant_id,company_id,email) VALUES (%s,%s,%s,'a@alo.com')", (ulid(), t, c1))
    app.execute("INSERT INTO contacts (id,tenant_id,company_id,phone_e164) VALUES (%s,%s,%s,'+8801712345678')", (ulid(), t, c1))

    board = sql_of(BOARD, "row_number() over")
    rows = app.execute(board, (None, None, 30)).fetchall()
    assert {r[1] for r in rows} == {"new", "qualified"} and len(rows) == 2
    first = next(r for r in rows if r[0] == l1)
    assert first[3] == "vip,ঢাকা" and first[7] == 1                      # tags string, open task count
    assert len(app.execute(board, ("vip", "vip", 30)).fetchall()) == 1
    assert app.execute(board, ("nope", "nope", 30)).fetchall() == []
    assert len(app.execute(board, (None, None, 1)).fetchall()) == 2      # per-column cap applies per stage, not overall

    cloud = app.execute(sql_of(BOARD, "unnest(l.tags)")).fetchall()
    assert sorted(r[0] for r in cloud) == ["vip", "ঢাকা"]

    tasks = sql_of(BOARD, "t.assignee_id")
    assert len(app.execute(tasks, (None, None)).fetchall()) == 1
    assert len(app.execute(tasks, (u, u)).fetchall()) == 1
    assert app.execute(tasks, (ulid(), ulid())).fetchall() == []
    assert len(app.execute(sql_of(BOARD, "next_follow_up_at < now()")).fetchall()) == 1

    exp = app.execute(sql_of(CSVC, "string_agg(k.email"), (5000,)).fetchall()
    top = exp[0]
    assert top[0] == "alo.com" and top[1] == "alo.com" and top[4] == "vip;ঢাকা"
    assert top[8] == "a@alo.com" and top[9] == "+8801712345678"

    assert len(app.execute(sql_of(LEADC, "from lead_activities a"), (l2,)).fetchall()) == 1       # the stage move
    assert len(app.execute(sql_of(LEADC, "from crm_tasks where lead_id"), (l1,)).fetchall()) == 1
    shown = app.execute(sql_of(LEADC, "from leads l join companies c on c.id = l.company_id where l.id"), (l1,)).fetchone()
    assert shown is not None


def test_imported_company_has_no_analysis_time(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    sql = sql_of(LEADS, "insert into companies")
    app.execute(sql, (ulid(), t, "Alo", "alo.com", "https://alo.com/", None, None, "[]", None, False))
    app.execute(sql, (ulid(), t, "Beta", "beta.com", "https://beta.com/", None, None, "{}", 70, True))
    got = dict(app.execute("SELECT domain, analyzed_at IS NOT NULL FROM companies").fetchall())
    assert got == {"alo.com": False, "beta.com": True}
