"""Phase 32a: agents as first-class objects - save/edit/versions/restore/delete/limit/usage log, tenant isolation."""
import psycopg
import pytest
from psycopg.types.json import Jsonb

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("wf_agent_runs", "wf_agent_versions", "wf_agents"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("DELETE FROM tenant_feature_overrides WHERE feature_key = 'agents.max'")


def save(app, world, *, name="Writer", desc="d", prompt="You write short emails.", model=None, temp=0.3, mx=800, tools=("notes.add",), knowledge=False, memory=False,
         perm=None, credits=2, enabled=True, new=True, aid=None, who="a", user=None):
    ctx(app, world, who)
    aid = aid or ulid()
    r = app.execute("SELECT wf_agent_save(%s,%s,%s,%s,%s,%s,%s,%s,%s::numeric,%s,%s::text[],%s,%s,%s,%s::numeric,%s)",
                    (aid, world[who]["tenant"], user or world[who]["user"], new, name, desc, prompt, model, temp, mx, list(tools), knowledge, memory,
                     Jsonb(perm if perm is not None else {}), credits, enabled)).fetchone()[0]
    return aid, r


def row(app, world, aid, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT name, system_prompt, version, tools, use_knowledge, use_memory, enabled, max_credits FROM wf_agents WHERE id=%s", (aid,)).fetchone()


def versions(app, world, aid, who="a"):
    ctx(app, world, who)
    return [r[0] for r in app.execute("SELECT version FROM wf_agent_versions WHERE agent_id=%s ORDER BY version", (aid,)).fetchall()]


def test_create_normalises_and_writes_version_one(app, world):
    aid, r = save(app, world, name="  Sales   Helper ")
    assert r == "ok"
    assert row(app, world, aid)[:3] == ("Sales Helper", "You write short emails.", 1)
    assert versions(app, world, aid) == [1]


@pytest.mark.parametrize("kw", [
    dict(name=""), dict(name="x" * 81), dict(prompt="  "), dict(prompt="x" * 8001), dict(temp=2.5), dict(mx=50), dict(mx=5000), dict(credits=-1), dict(credits=2000),
    dict(tools=("Bad Tool",)), dict(tools=("a",)), dict(tools=tuple(f"t.x{i}" for i in range(21))), dict(desc="d" * 501),
])
def test_bad_input_is_refused_without_writing(app, world, kw):
    aid, r = save(app, world, **kw)
    assert r == "bad_input"
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM wf_agents WHERE id=%s", (aid,)).fetchone()[0] == 0


def test_duplicate_names_are_refused_per_owner_case_insensitively(app, world):
    save(app, world, name="Helper")
    assert save(app, world, name="hELPer")[1] == "duplicate"
    a2, r = save(app, world, name="Other")
    assert r == "ok"
    assert save(app, world, name="HELPER", new=False, aid=a2)[1] == "duplicate"


def test_edit_makes_a_new_version_only_when_something_changed(app, world):
    aid, _ = save(app, world)
    assert save(app, world, new=False, aid=aid)[1] == "ok" and row(app, world, aid)[2] == 1          # same content: no new version
    assert save(app, world, new=False, aid=aid, prompt="New prompt")[1] == "ok"
    assert row(app, world, aid)[2] == 2 and versions(app, world, aid) == [1, 2]
    assert save(app, world, new=False, aid=aid, prompt="New prompt", tools=("notes.add", "tasks.add"))[1] == "ok"
    assert row(app, world, aid)[2] == 3
    assert save(app, world, new=False, aid=aid, prompt="New prompt", tools=("notes.add", "tasks.add"), enabled=False)[1] == "ok"
    assert row(app, world, aid)[2] == 3 and row(app, world, aid)[6] is False                          # enabling/disabling is not a content version


def test_restore_brings_an_old_version_back_as_a_new_version(app, world):
    aid, _ = save(app, world, prompt="First")
    save(app, world, new=False, aid=aid, prompt="Second")
    ctx(app, world, "a")
    assert app.execute("SELECT wf_agent_restore(%s,%s,%s,%s)", (aid, world["a"]["tenant"], world["a"]["user"], 1)).fetchone()[0] == "ok"
    assert row(app, world, aid)[1:3] == ("First", 3) and versions(app, world, aid) == [1, 2, 3]
    assert app.execute("SELECT wf_agent_restore(%s,%s,%s,%s)", (aid, world["a"]["tenant"], world["a"]["user"], 9)).fetchone()[0] == "not_found"


def test_only_the_owner_can_edit_restore_delete_or_log_usage(app, world, second_user):
    aid, _ = save(app, world)
    other = second_user
    assert save(app, world, new=False, aid=aid, prompt="Hijack", user=other)[1] == "not_found"
    ctx(app, world, "a")
    assert app.execute("SELECT wf_agent_delete(%s,%s,%s)", (aid, world["a"]["tenant"], other)).fetchone()[0] == "not_found"
    assert app.execute("SELECT wf_agent_restore(%s,%s,%s,%s)", (aid, world["a"]["tenant"], other, 1)).fetchone()[0] == "not_found"
    assert app.execute("SELECT wf_agent_run_record(%s,%s,%s,%s,'test','ok','i','o',0,1,1,'m')", (ulid(), world["a"]["tenant"], other, aid)).fetchone()[0] == "not_found"
    assert row(app, world, aid)[1] == "You write short emails."


def test_tenant_isolation_and_cross_tenant_calls(app, world):
    aid, _ = save(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM wf_agents").fetchone()[0] == 0                         # RLS hides it
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT wf_agent_delete(%s,%s,%s)", (aid, world["a"]["tenant"], world["a"]["user"]))
    app.rollback()
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM wf_agents WHERE id=%s", (aid,)).fetchone()[0] == 1


def test_the_web_role_cannot_write_tables_directly(app, world):
    aid, _ = save(app, world)
    ctx(app, world, "a")
    for sql in ("UPDATE wf_agents SET name='x'", "DELETE FROM wf_agents", "INSERT INTO wf_agent_runs (id) VALUES ('x')", "UPDATE wf_agent_versions SET version=9"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
        app.rollback()
        ctx(app, world, "a")


def test_plan_limit_default_ten_and_override(app, owner, world):
    for i in range(10):
        assert save(app, world, name=f"A{i}")[1] == "ok"
    assert save(app, world, name="A10")[1] == "limit"
    as_admin(owner)
    owner.execute("INSERT INTO tenant_feature_overrides (tenant_id, feature_key, enabled, limit_value, reason, set_by) VALUES (%s,'agents.max',true,11,'test',%s)", (world["a"]["tenant"], world["a"]["user"]))
    assert save(app, world, name="A10")[1] == "ok"
    assert save(app, world, name="A11")[1] == "limit"
    owner.execute("UPDATE tenant_feature_overrides SET limit_value = NULL WHERE tenant_id=%s AND feature_key='agents.max'", (world["a"]["tenant"],))
    assert save(app, world, name="A11")[1] == "ok"                                                   # NULL = unlimited
    owner.execute("UPDATE tenant_feature_overrides SET enabled = false WHERE tenant_id=%s AND feature_key='agents.max'", (world["a"]["tenant"],))
    assert save(app, world, name="A12")[1] == "limit"


def test_usage_log_and_cascade_delete(app, world):
    aid, _ = save(app, world)
    ctx(app, world, "a")
    rid = ulid()
    assert app.execute("SELECT wf_agent_run_record(%s,%s,%s,%s,'test','ok','in','out',0.5,10,20,'gpt')", (rid, world["a"]["tenant"], world["a"]["user"], aid)).fetchone()[0] == "ok"
    assert app.execute("SELECT agent_version, kind, credits, input_tokens FROM wf_agent_runs WHERE id=%s", (rid,)).fetchone() == (1, "test", 0.5, 10)
    assert app.execute("SELECT wf_agent_run_record(%s,%s,%s,%s,'hack','ok','','',0,0,0,'')", (ulid(), world["a"]["tenant"], world["a"]["user"], aid)).fetchone()[0] == "bad_input"
    assert app.execute("SELECT wf_agent_delete(%s,%s,%s)", (aid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert app.execute("SELECT count(*) FROM wf_agent_runs").fetchone()[0] == 0 and app.execute("SELECT count(*) FROM wf_agent_versions").fetchone()[0] == 0
