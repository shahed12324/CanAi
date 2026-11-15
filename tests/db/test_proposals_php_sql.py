"""The SQL strings in the proposal repo and controllers, executed for real with the parameter shapes PHP passes."""
import json

import pytest

from conftest import as_admin, set_tenant, ulid
from test_lead_php_sql import sql_of

REPO = "app/Proposals/PgProposalRepo.php"
BRAND = "app/Http/Controllers/BrandKitController.php"
PORT = "app/Http/Controllers/PortfolioController.php"
PROP = "app/Http/Controllers/ProposalController.php"
LEADC = "app/Http/Controllers/LeadController.php"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("lead_activities", "proposals", "portfolio_items", "brand_kits", "leads", "companies"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("DELETE FROM proposal_templates WHERE tenant_id IS NOT NULL")


def lead(conn, t, analysis=None):
    c, l = ulid(), ulid()
    conn.execute("INSERT INTO companies (id,tenant_id,name,domain,analysis) VALUES (%s,%s,'আলো','alo.test',%s)", (c, t, json.dumps(analysis or {"gaps": []})))
    conn.execute("INSERT INTO leads (id,tenant_id,company_id) VALUES (%s,%s,%s)", (l, t, c))
    return l


def test_repo_statements(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    shipped = app.execute(sql_of(REPO, "from proposal_templates where id"), (app.execute("SELECT id FROM proposal_templates WHERE kind='web_design' AND language='bn'").fetchone()[0],)).fetchone()
    assert shipped[1] == "web_design" and shipped[2] == "bn" and isinstance(shipped[4], list)

    assert app.execute(sql_of(REPO, "from brand_kits where is_default")).fetchone() is None
    kit = ulid()
    app.execute("INSERT INTO brand_kits (id,tenant_id,name,is_default) VALUES (%s,%s,'Jarvis',true)", (kit, t))
    row = app.execute(sql_of(REPO, "from brand_kits where is_default")).fetchone()
    assert row[0] == kit and row[1] == "Jarvis" and row[2] == "#1f3a5f"

    l = lead(app, t, {"gaps": [{"code": "no_fb_pixel"}]})
    s = app.execute(sql_of(REPO, "from leads l join companies c"), (l,)).fetchone()
    assert s[0] == "আলো" and s[2]["gaps"][0]["code"] == "no_fb_pixel"

    pid = ulid()
    app.execute(sql_of(REPO, "insert into proposals"), (pid, t, l, None, kit, "প্রস্তাব", "bn", json.dumps({"sections": [{"heading": "হ"}]}, ensure_ascii=False), u))
    got = app.execute(sql_of(REPO, "from proposals where id"), (pid,)).fetchone()
    assert got[0] == pid and got[1] == t and got[2] == l and got[4] == "bn" and got[5] == "draft" and got[6]["sections"][0]["heading"] == "হ"
    app.execute(sql_of(REPO, "update proposals set title"), ("নতুন", "en", json.dumps({"x": 1}), pid))
    app.execute(sql_of(REPO, "update proposals set status"), ("sent", pid))
    assert app.execute("SELECT title, language, status FROM proposals WHERE id=%s", (pid,)).fetchone() == ("নতুন", "en", "sent")

    w1, w2 = ulid(), ulid()
    for w, title in ((w1, "one"), (w2, "two")):
        app.execute("INSERT INTO portfolio_items (id,tenant_id,title) VALUES (%s,%s,%s)", (w, t, title))
    by = app.execute(sql_of(REPO, "from portfolio_items where deleted_at is null and id = any"), ("{" + f'"{w1}","{w2}","{ulid()}"' + "}",)).fetchall()
    assert sorted(r[1] for r in by) == ["one", "two"]
    app.execute("UPDATE portfolio_items SET deleted_at=now() WHERE id=%s", (w2,))
    assert [r[0] for r in app.execute(sql_of(REPO, "from portfolio_items where deleted_at is null and id = any"), ("{" + f'"{w1}","{w2}"' + "}",)).fetchall()] == [w1]


def test_other_workspaces_rows_are_invisible_to_the_repo(app, owner, world):
    as_admin(owner)
    lb = lead(owner, world["b"]["tenant"])
    pb = ulid()
    owner.execute("INSERT INTO proposals (id,tenant_id,title) VALUES (%s,%s,'B only')", (pb, world["b"]["tenant"]))
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    set_tenant(app, world["a"]["tenant"])
    assert app.execute(sql_of(REPO, "from proposals where id"), (pb,)).fetchone() is None
    assert app.execute(sql_of(REPO, "from leads l join companies c"), (lb,)).fetchone() is None
    assert app.execute(sql_of(REPO, "update proposals set status"), ("sent", pb)).rowcount == 0


def test_brand_controller_statements(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    ins = sql_of(BRAND, "insert into brand_kits")
    app.execute(ins, (ulid(), t, "Jarvis", "#112233", "#445566", "tone", "a@b.test", "+8801712345678", "https://a.test", "ঢাকা", "QUJD"))
    k = app.execute(sql_of(BRAND, "from brand_kits where is_default limit 1")).fetchone()
    assert k[1] == "Jarvis" and k[5] == "a@b.test" and k[9] is True and k[10] == "QUJD"       # has_logo, logo_b64
    with_logo = sql_of(BRAND, "logo_b64 = ?")
    app.execute(with_logo, ("J2", "#000000", "#ffffff", None, None, None, None, None, None, k[0]))
    assert app.execute("SELECT name, logo_b64 FROM brand_kits").fetchone() == ("J2", None)
    plain = sql_of(BRAND, "update brand_kits set name = ?, primary_color = ?, accent_color = ?, tone = ?, email = ?, phone = ?, website = ?, address = ?, updated_at")
    app.execute(plain, ("J3", "#000000", "#ffffff", None, None, None, None, None, k[0]))
    assert app.execute("SELECT name FROM brand_kits").fetchone()[0] == "J3"


def test_portfolio_controller_statements(app, world):
    t = world["a"]["tenant"]
    set_tenant(app, t)
    wid = ulid()
    app.execute(sql_of(PORT, "insert into portfolio_items"), (wid, t, "Shop", "https://a.test", "desc", '{"ই-কমার্স","ঢাকা"}'))
    row = app.execute(sql_of(PORT, "array_to_string(tags")).fetchone()
    assert row[1] == "Shop" and row[4] == "ই-কমার্স, ঢাকা"
    assert app.execute(sql_of(PORT, "update portfolio_items set title"), ("Shop2", None, None, "{}", wid)).rowcount == 1
    assert app.execute(sql_of(PORT, "set deleted_at = now()"), (wid,)).rowcount == 1
    assert app.execute(sql_of(PORT, "set deleted_at = now()"), (wid,)).rowcount == 0
    assert app.execute(sql_of(PORT, "array_to_string(tags")).fetchall() == []


def test_proposal_controller_and_lead_queries(app, world):
    t, u = world["a"]["tenant"], world["a"]["user"]
    set_tenant(app, t)
    l = lead(app, t)
    p = ulid()
    app.execute("INSERT INTO proposals (id,tenant_id,lead_id,title,created_by) VALUES (%s,%s,%s,'P1',%s)", (p, t, l, u))
    app.execute("INSERT INTO proposals (id,tenant_id,title) VALUES (%s,%s,'P2 no lead')", (ulid(), t))
    rows = app.execute(sql_of(PROP, "left join leads l on l.id = p.lead_id")).fetchall()
    assert sorted((r[1], r[5]) for r in rows) == [("P1", "আলো"), ("P2 no lead", None)]
    tpl = app.execute(sql_of(PROP, "(tenant_id is null) as shipped")).fetchall()
    assert len(tpl) == 6 and all(r[4] for r in tpl)
    assert app.execute(sql_of(PROP, "from leads l join companies c on c.id = l.company_id where l.id = ? and l.deleted_at is null"), (l,)).fetchone()[2] == "alo.test"
    assert app.execute(sql_of(PROP, "from portfolio_items where deleted_at is null order by")).fetchall() == []
    assert app.execute(sql_of(PROP, "select l.id, c.name from leads l join companies c on c.id = l.company_id where l.id = ?"), (l,)).fetchone()[1] == "আলো"
    assert [r[1] for r in app.execute(sql_of(LEADC, "from proposals where lead_id"), (l,)).fetchall()] == ["P1"]
