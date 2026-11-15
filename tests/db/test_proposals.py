import json

import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("lead_activities", "proposals", "portfolio_items", "brand_kits", "crm_tasks", "leads", "companies"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("DELETE FROM proposal_templates WHERE tenant_id IS NOT NULL")


def lead(conn, w, who="a", domain="alo.test"):
    c, l = ulid(), ulid()
    conn.execute("INSERT INTO companies (id,tenant_id,name,domain) VALUES (%s,%s,%s,%s)", (c, w[who]["tenant"], domain, domain))
    conn.execute("INSERT INTO leads (id,tenant_id,company_id) VALUES (%s,%s,%s)", (l, w[who]["tenant"], c))
    return l


def proposal(conn, w, who="a", **kw):
    pid = ulid()
    cols = {"id": pid, "tenant_id": w[who]["tenant"], "title": "প্রস্তাব", "data": json.dumps({"sections": []})} | kw
    conn.execute(f"INSERT INTO proposals ({','.join(cols)}) VALUES ({','.join(['%s'] * len(cols))})", list(cols.values()))
    return pid


def test_shipped_templates_are_visible_to_every_workspace_in_both_languages(app, world):
    for who in ("a", "b"):
        set_tenant(app, world[who]["tenant"])
        rows = app.execute("SELECT kind, language FROM proposal_templates WHERE tenant_id IS NULL").fetchall()
        assert sorted(rows) == sorted((k, l) for k in ("web_design", "marketing", "general") for l in ("bn", "en"))
    secs = app.execute("SELECT sections FROM proposal_templates WHERE kind='web_design' AND language='bn'").fetchone()[0]
    assert "{{gaps_list}}" in json.dumps(secs, ensure_ascii=False) and "{{client_name}}" in json.dumps(secs, ensure_ascii=False)


def test_a_workspace_cannot_change_or_fake_a_shipped_template(app, world):
    set_tenant(app, world["a"]["tenant"])
    assert app.execute("UPDATE proposal_templates SET name='hacked' WHERE tenant_id IS NULL").rowcount == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM proposal_templates")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO proposal_templates (id,tenant_id,kind,language,name,sections) VALUES (%s,NULL,'general','bn','x','[]')", (ulid(),))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("INSERT INTO proposal_templates (id,tenant_id,kind,language,name,sections) VALUES (%s,%s,'general','bn','x','[]')", (ulid(), world["b"]["tenant"]))
    mine = ulid()
    app.execute("INSERT INTO proposal_templates (id,tenant_id,kind,language,name,sections) VALUES (%s,%s,'general','bn','মেরটি','[]')", (mine, world["a"]["tenant"]))
    set_tenant(app, world["b"]["tenant"])
    assert app.execute("SELECT count(*) FROM proposal_templates WHERE id=%s", (mine,)).fetchone()[0] == 0     # private to A


def test_tenants_cannot_see_each_others_brand_portfolio_or_proposals(app, owner, world):
    as_admin(owner)
    for who in ("a", "b"):
        t = world[who]["tenant"]
        owner.execute("INSERT INTO brand_kits (id,tenant_id,name,is_default) VALUES (%s,%s,%s,true)", (ulid(), t, f"brand-{who}"))
        owner.execute("INSERT INTO portfolio_items (id,tenant_id,title) VALUES (%s,%s,%s)", (ulid(), t, f"work-{who}"))
        proposal(owner, world, who, title=f"p-{who}")
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    set_tenant(app, world["a"]["tenant"])
    assert [r[0] for r in app.execute("SELECT name FROM brand_kits").fetchall()] == ["brand-a"]
    assert [r[0] for r in app.execute("SELECT title FROM portfolio_items").fetchall()] == ["work-a"]
    assert [r[0] for r in app.execute("SELECT title FROM proposals").fetchall()] == ["p-a"]
    assert app.execute("UPDATE proposals SET title='x' WHERE tenant_id=%s", (world["b"]["tenant"],)).rowcount == 0


def test_one_default_brand_kit_per_workspace(app, world):
    set_tenant(app, world["a"]["tenant"])
    t = world["a"]["tenant"]
    app.execute("INSERT INTO brand_kits (id,tenant_id,name,is_default) VALUES (%s,%s,'one',true)", (ulid(), t))
    app.execute("INSERT INTO brand_kits (id,tenant_id,name,is_default) VALUES (%s,%s,'two',false)", (ulid(), t))
    with pytest.raises(psycopg.errors.UniqueViolation):
        app.execute("INSERT INTO brand_kits (id,tenant_id,name,is_default) VALUES (%s,%s,'three',true)", (ulid(), t))


def test_brand_kit_value_rules(app, world):
    set_tenant(app, world["a"]["tenant"])
    t = world["a"]["tenant"]
    for col, bad in (("primary_color", "red"), ("primary_color", "#12345"), ("accent_color", "#GGGGGG"), ("primary_color", "#ABCDEF"), ("logo_b64", "x" * 820001), ("name", "   ")):
        with pytest.raises(psycopg.errors.CheckViolation):
            app.execute(f"INSERT INTO brand_kits (id,tenant_id,name,{col}) VALUES (%s,%s,'n',%s)" if col != "name" else "INSERT INTO brand_kits (id,tenant_id,name) VALUES (%s,%s,%s)", (ulid(), t, bad))


def test_portfolio_rules(app, world):
    set_tenant(app, world["a"]["tenant"])
    t = world["a"]["tenant"]
    app.execute("INSERT INTO portfolio_items (id,tenant_id,title,url,tags) VALUES (%s,%s,'ok','https://a.test',%s)", (ulid(), t, ["web"]))
    for url in ("javascript:alert(1)", "ftp://x", "a.test"):
        with pytest.raises(psycopg.errors.CheckViolation):
            app.execute("INSERT INTO portfolio_items (id,tenant_id,title,url) VALUES (%s,%s,'x',%s)", (ulid(), t, url))
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("INSERT INTO portfolio_items (id,tenant_id,title,tags) VALUES (%s,%s,'x',%s)", (ulid(), t, [str(i) for i in range(11)]))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM portfolio_items")


def test_proposal_cannot_point_at_another_workspaces_lead_kit_or_template(app, owner, world):
    as_admin(owner)
    lb = lead(owner, world, "b", "b.test")
    kb, tb = ulid(), ulid()
    owner.execute("INSERT INTO brand_kits (id,tenant_id,name) VALUES (%s,%s,'kb')", (kb, world["b"]["tenant"]))
    owner.execute("INSERT INTO proposal_templates (id,tenant_id,kind,language,name,sections) VALUES (%s,%s,'general','en','tb','[]')", (tb, world["b"]["tenant"]))
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    set_tenant(app, world["a"]["tenant"])
    for col, val in (("lead_id", lb), ("brand_kit_id", kb), ("template_id", tb), ("template_id", ulid())):
        with pytest.raises(psycopg.errors.RaiseException):
            proposal(app, world, **{col: val})
    shipped = app.execute("SELECT id FROM proposal_templates WHERE tenant_id IS NULL LIMIT 1").fetchone()[0]
    proposal(app, world, template_id=shipped)                                  # a shipped template is fine


def test_proposal_rules_and_lead_history_kind(app, world):
    set_tenant(app, world["a"]["tenant"])
    l = lead(app, world)
    p = proposal(app, world, lead_id=l)
    for bad in ("draft2", "", "SENT"):
        with pytest.raises(psycopg.errors.CheckViolation):
            app.execute("UPDATE proposals SET status=%s WHERE id=%s", (bad, p))
    for status in ("sent", "accepted", "declined", "draft"):
        app.execute("UPDATE proposals SET status=%s WHERE id=%s", (status, p))
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("UPDATE proposals SET data=%s WHERE id=%s", (json.dumps(["not", "an", "object"]), p))
    with pytest.raises(psycopg.errors.CheckViolation):
        proposal(app, world, title="  ")
    app.execute("INSERT INTO lead_activities (id,tenant_id,lead_id,user_id,kind,body) VALUES (%s,%s,%s,%s,'proposal','x')", (ulid(), world["a"]["tenant"], l, world["a"]["user"]))


def test_admin_can_read_but_not_write(admin, owner, world):
    as_admin(owner)
    proposal(owner, world)
    as_admin(admin)
    assert admin.execute("SELECT count(*) FROM proposals").fetchone()[0] == 1
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        admin.execute("DELETE FROM proposals")
