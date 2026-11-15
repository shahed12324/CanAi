"""The SQL strings in PgLeadStore.php and the controllers, executed for real with the parameter shapes PHP passes."""
import json
import re
from pathlib import Path

import pytest

from conftest import as_admin, set_tenant, ulid

OVERLAY = Path(__file__).resolve().parents[2] / "apps/web-overlay"
STORE = "app/Leads/PgLeadStore.php"


def sql_of(file: str, marker: str, fragment: bool = False) -> str:
    src = (OVERLAY / file).read_text()
    for m in re.finditer(r"""(?s)(?<![\w$])(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")""", src):
        s = m.group(1) if m.group(1) is not None else m.group(2)
        if marker in s and (fragment or re.match(r"\s*(select|insert|update|delete)\b", s, re.I)):
            return s.replace("%", "%%").replace("?", "%s")
    raise AssertionError(f"no SQL containing {marker!r} in {file}")


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("lead_research_items", "leads", "contacts", "companies", "lead_research_jobs"):
        owner.execute(f"DELETE FROM {t}")


def test_store_statements_run_the_pipeline(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    job = ulid()
    app.execute(sql_of(STORE, "insert into lead_research_jobs"), (job, t, u, "j", "url", 2, json.dumps({"max_pages": 3})))
    items = [ulid(), ulid()]
    for i, d in zip(items, ("alo.com", "beta.com"), strict=True):
        app.execute(sql_of(STORE, "insert into lead_research_items"), (i, t, job, f"https://{d}/", d))

    row = app.execute(sql_of(STORE, "update lead_research_items i set status = 'running'"), (items[0],)).fetchall()
    assert len(row) == 1 and row[0][5] == u and row[0][6] == {"max_pages": 3}          # user_id, options (jsonb)
    assert app.execute(sql_of(STORE, "update lead_research_items i set status = 'running'"), (items[0],)).fetchall() == []   # claimed once
    app.execute(sql_of(STORE, "update lead_research_jobs set status = 'running'"), (job,))

    analysis = json.dumps({"gaps": [{"code": "no_fb_pixel"}]}, ensure_ascii=False)
    cid = ulid()
    app.execute(sql_of(STORE, "insert into companies"), (cid, t, "আলো", "alo.com", "https://alo.com/", None, None, analysis, 70, True))
    found = app.execute(sql_of(STORE, "from companies where tenant_id"), (t, "alo.com")).fetchone()
    assert found[0] == cid and found[2] == 70
    app.execute(sql_of(STORE, "update companies set website"), ("https://alo.com/x", "d", None, analysis, 75, cid))

    new = lambda: app.execute(sql_of(STORE, "insert into leads"), (ulid(), t, cid, 75, "research", job, u)).rowcount
    assert (new(), new()) == (1, 0)                                                      # second insert is a no-op, not an error
    assert app.execute(sql_of(STORE, "update leads set score"), (80, t, cid)).rowcount == 1

    def contact(email=None, phone=None):
        return app.execute(sql_of(STORE, "insert into contacts"), (ulid(), t, cid, email, "mx_ok" if email else "unverified", phone, "mobile" if phone else None, None)).rowcount

    assert (contact(email="a@alo.com"), contact(email="a@alo.com")) == (1, 0)
    assert (contact(phone="+8801712345678"), contact(phone="+8801712345678")) == (1, 0)
    assert app.execute(sql_of(STORE, "select count(*) from contacts"), (cid,)).fetchone()[0] == 2

    app.execute(sql_of(STORE, "update lead_research_items set status = ?, result_code"), ("done", "ok", cid, items[0]))
    app.execute(sql_of(STORE, "update lead_research_jobs set status = 'done'"), (job, job))
    assert app.execute("SELECT status FROM lead_research_jobs WHERE id=%s", (job,)).fetchone()[0] == "running"      # item 2 still queued
    app.execute(sql_of(STORE, "update lead_research_items set status = ?, result_code"), ("failed", "unreachable", None, items[1]))
    app.execute(sql_of(STORE, "update lead_research_jobs set status = 'done'"), (job, job))
    assert app.execute("SELECT status FROM lead_research_jobs WHERE id=%s", (job,)).fetchone()[0] == "done"


def test_controller_statements_and_limits(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    ctl = "app/Http/Controllers/LeadResearchController.php"
    app.execute(sql_of(STORE, "insert into lead_research_jobs"), (ulid(), t, u, "j", "csv", 0, "{}"))
    rows = app.execute(sql_of(ctl, "from lead_research_jobs j left join lead_research_items")).fetchall()
    assert len(rows) == 1
    assert app.execute(sql_of("app/Leads/ResearchLimits.php", "status in ('queued', 'running')")).fetchone()[0] == 1
    assert app.execute(sql_of("app/Leads/ResearchLimits.php", "interval '24 hours'")).fetchone()[0] == 1
    jid = rows[0][0].strip()
    app.execute(sql_of(ctl, "set status = 'cancelled'"), (jid,))
    app.execute(sql_of(ctl, "result_code = 'cancelled'"), (jid,))
    assert app.execute(sql_of(ctl, "select id, name, status, total, input_type"), (jid,)).fetchone()[2] == "cancelled"
    assert len(app.execute(sql_of(ctl, "from lead_research_items i left join leads l"), (jid,)).fetchall()) == 0


def test_lead_list_queries(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    cid = ulid()
    app.execute(sql_of(STORE, "insert into companies"), (cid, t, "আলো ডিজিটাল", "alo.com", None, None, None, json.dumps({"gaps": []}), 50, True))
    lid = ulid()
    app.execute(sql_of(STORE, "insert into leads"), (lid, t, cid, 50, "research", None, u))
    ctl = "app/Http/Controllers/LeadController.php"
    head = sql_of(ctl, "select l.id, l.stage, l.score, l.created_at, c.name")      # ends with "where "
    tail = sql_of(ctl, "order by l.score desc", fragment=True)                                      # " order by ... limit 31 offset ?"
    for where, params in (("l.deleted_at is null", []), ("l.deleted_at is null and (c.name ilike %s or c.domain ilike %s) and l.stage = %s and l.score >= %s", ["%আলো%", "%আলো%", "new", 10])):
        assert len(app.execute(head + where + tail, [*params, 0]).fetchall()) == 1
    show = sql_of(ctl, "select l.id, l.stage, l.score, l.source, array_to_string")
    r = app.execute(show, (lid,)).fetchone()
    assert r[0] == lid and r[9] == "alo.com" and r[4] == ""            # tags come back as one comma string
    assert app.execute(sql_of(ctl, "from contacts where company_id"), (cid,)).fetchall() == []
