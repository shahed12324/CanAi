import psycopg
import pytest

from conftest import as_admin, set_tenant, ulid


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("lead_activities", "crm_tasks", "lead_research_items", "leads", "contacts", "companies", "lead_research_jobs"):
        owner.execute(f"DELETE FROM {t}")


def lead(conn, w, who="a", domain="alo.test"):
    cid, lid = ulid(), ulid()
    conn.execute("INSERT INTO companies (id,tenant_id,name,domain) VALUES (%s,%s,%s,%s)", (cid, w[who]["tenant"], domain, domain))
    conn.execute("INSERT INTO leads (id,tenant_id,company_id) VALUES (%s,%s,%s)", (lid, w[who]["tenant"], cid))
    return lid


def note(conn, w, lid, who="a", body="hello"):
    conn.execute("INSERT INTO lead_activities (id,tenant_id,lead_id,user_id,kind,body) VALUES (%s,%s,%s,%s,'note',%s)",
                 (ulid(), w[who]["tenant"], lid, w[who]["user"], body))


def test_tenants_cannot_see_each_others_notes_or_tasks(app, owner, world):
    as_admin(owner)
    la, lb = lead(owner, world, "a", "a.test"), lead(owner, world, "b", "b.test")
    note(owner, world, la, "a", "A secret")
    note(owner, world, lb, "b", "B secret")
    owner.execute("INSERT INTO crm_tasks (id,tenant_id,lead_id,title) VALUES (%s,%s,%s,'t')", (ulid(), world["b"]["tenant"], lb))
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    set_tenant(app, world["a"]["tenant"])
    assert [r[0] for r in app.execute("SELECT body FROM lead_activities").fetchall()] == ["A secret"]
    assert app.execute("SELECT count(*) FROM crm_tasks").fetchone()[0] == 0


def test_activity_log_is_append_only(app, world):
    set_tenant(app, world["a"]["tenant"])
    lid = lead(app, world)
    note(app, world, lid)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE lead_activities SET body='edited'")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("DELETE FROM lead_activities")


def test_child_rows_cannot_point_at_another_tenants_lead(app, owner, world):
    as_admin(owner)
    lb = lead(owner, world, "b", "b.test")
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    set_tenant(app, world["a"]["tenant"])
    with pytest.raises(psycopg.errors.RaiseException):
        note(app, world, lb, "a")
    with pytest.raises(psycopg.errors.RaiseException):
        app.execute("INSERT INTO crm_tasks (id,tenant_id,lead_id,title) VALUES (%s,%s,%s,'x')", (ulid(), world["a"]["tenant"], lb))


def test_move_stage_records_history_once(app, world):
    set_tenant(app, world["a"]["tenant"])
    lid = lead(app, world)
    u = world["a"]["user"]
    assert app.execute("SELECT lead_move_stage(%s,'contacted',%s)", (lid, u)).fetchone()[0] == "new"
    assert app.execute("SELECT stage FROM leads WHERE id=%s", (lid,)).fetchone()[0] == "contacted"
    # same stage again: nothing changes, nothing logged
    assert app.execute("SELECT lead_move_stage(%s,'contacted',%s)", (lid, u)).fetchone()[0] == "contacted"
    rows = app.execute("SELECT kind, meta FROM lead_activities WHERE lead_id=%s", (lid,)).fetchall()
    assert rows == [("stage", {"from": "new", "to": "contacted"})]


def test_move_stage_to_won_or_lost_clears_follow_up(app, world):
    set_tenant(app, world["a"]["tenant"])
    lid = lead(app, world)
    app.execute("UPDATE leads SET next_follow_up_at = now() + interval '2 days' WHERE id=%s", (lid,))
    app.execute("SELECT lead_move_stage(%s,'qualified',%s)", (lid, world["a"]["user"]))
    assert app.execute("SELECT next_follow_up_at IS NOT NULL FROM leads WHERE id=%s", (lid,)).fetchone()[0] is True
    app.execute("SELECT lead_move_stage(%s,'won',%s)", (lid, world["a"]["user"]))
    assert app.execute("SELECT next_follow_up_at IS NULL FROM leads WHERE id=%s", (lid,)).fetchone()[0] is True


def test_move_stage_cannot_touch_other_tenants_or_missing_leads(app, owner, world):
    as_admin(owner)
    lb = lead(owner, world, "b", "b.test")
    owner.execute("SELECT set_config('app.bypass_rls','',false)")
    set_tenant(app, world["a"]["tenant"])
    assert app.execute("SELECT lead_move_stage(%s,'won',%s)", (lb, world["a"]["user"])).fetchone()[0] is None
    assert app.execute("SELECT lead_move_stage(%s,'won',%s)", (ulid(), world["a"]["user"])).fetchone()[0] is None
    as_admin(owner)
    assert owner.execute("SELECT stage FROM leads WHERE id=%s", (lb,)).fetchone()[0] == "new"


def test_move_stage_rejects_unknown_stage(app, world):
    set_tenant(app, world["a"]["tenant"])
    lid = lead(app, world)
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("SELECT lead_move_stage(%s,'banana',%s)", (lid, world["a"]["user"]))


def test_tag_limit_and_task_title_rules(app, world):
    set_tenant(app, world["a"]["tenant"])
    lid = lead(app, world)
    app.execute("UPDATE leads SET tags = %s WHERE id=%s", ([f"t{i}" for i in range(10)], lid))
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("UPDATE leads SET tags = %s WHERE id=%s", ([f"t{i}" for i in range(11)], lid))
    with pytest.raises(psycopg.errors.CheckViolation):
        app.execute("INSERT INTO crm_tasks (id,tenant_id,lead_id,title) VALUES (%s,%s,%s,'   ')", (ulid(), world["a"]["tenant"], lid))


def test_admin_role_can_read_but_not_write(admin, owner, world):
    as_admin(owner)
    lid = lead(owner, world)
    note(owner, world, lid)
    as_admin(admin)
    assert admin.execute("SELECT count(*) FROM lead_activities").fetchone()[0] == 1
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        admin.execute("DELETE FROM crm_tasks")
