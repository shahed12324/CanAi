"""Phase 32c: managing workflows - rename keeps identity/schedule/steps, pause vs disable, duplicate, test runs, template origin, new categories."""
import pytest
from psycopg.types.json import Jsonb

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)
from test_workflows import DEF, create, due, save, wrow


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("wf_steps", "wf_runs", "wf_workflows"):
        owner.execute(f"DELETE FROM {t}")


def fn(app, world, sql, args, who="a"):
    ctx(app, world, who)
    return app.execute(sql, args).fetchone()[0]


def full(app, world, wid, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT name, description, version, template_key, schedule_time, last_scheduled_on, enabled, paused, category, max_credits, model_id, definition FROM wf_workflows WHERE id=%s", (wid,)).fetchone()


def rename(app, world, wid, name, desc="", who="a", user=None):
    return fn(app, world, "SELECT wf_rename(%s,%s,%s,%s,%s)", (wid, world[who]["tenant"], user or world[who]["user"], name, desc), who)


def mark(app, world, wid, key, who="a"):
    return fn(app, world, "SELECT wf_mark_template(%s,%s,%s,%s)", (wid, world[who]["tenant"], world[who]["user"], key), who)


def state(app, world, wid, en=None, pa=None, who="a"):
    return fn(app, world, "SELECT wf_set_state(%s,%s,%s,%s,%s)", (wid, world[who]["tenant"], world[who]["user"], en, pa), who)


# ---- rename ----

def test_rename_changes_only_name_and_description(app, world, admin, owner):
    wid, _ = save(app, world, name="Morning plan", schedule="08:00")
    assert mark(app, world, wid, "morning_plan") == "ok"
    as_admin(owner)
    owner.execute("UPDATE wf_workflows SET last_scheduled_on = current_date WHERE id=%s", (wid,))
    before = full(app, world, wid)
    assert rename(app, world, wid, "  My   own   name ", " new desc ") == "ok"
    after = full(app, world, wid)
    assert after[0:2] == ("My own name", "new desc")
    assert after[2:] == before[2:]                       # version, template_key, schedule, last_scheduled_on, enabled, paused, category, limit, model, steps: all unchanged
    assert after[3] == "morning_plan"


def test_rename_keeps_run_history_and_scheduled_job(app, world, admin, owner):
    wid, _ = save(app, world, name="Old", schedule="00:00")
    rid, r = create(app, world, wid)
    assert r == "ok"
    assert rename(app, world, wid, "Renamed") == "ok"
    ctx(app, world, "a")
    assert app.execute("SELECT workflow_id FROM wf_runs WHERE id=%s", (rid,)).fetchone()[0].strip() == wid
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET status='done', finished_at=now()")
    owner.execute("UPDATE wf_workflows SET last_scheduled_on=NULL")
    assert due(admin) == 1                                # the schedule still fires for the renamed workflow
    ctx(app, world, "a")
    assert app.execute("SELECT trigger FROM wf_runs WHERE workflow_id=%s ORDER BY created_at DESC", (wid,)).fetchone()[0] == "schedule"


@pytest.mark.parametrize("name,desc", [("", ""), ("   ", ""), (None, ""), ("x" * 121, ""), ("ok", "d" * 501)])
def test_rename_bad_input(app, world, name, desc):
    wid, _ = save(app, world)
    assert rename(app, world, wid, name, desc) == "bad_input"


def test_rename_owner_only_and_tenant_isolated(app, world, second_user):
    wid, _ = save(app, world, name="Mine")
    assert rename(app, world, wid, "Stolen", user=second_user) == "not_found"
    assert rename(app, world, wid, "Stolen", who="b") == "not_found"
    assert full(app, world, wid)[0] == "Mine"


# ---- template origin ----

def test_template_key_is_set_once_and_never_changes(app, world):
    wid, _ = save(app, world)
    assert full(app, world, wid)[3] is None
    assert mark(app, world, wid, "email_reply") == "ok"
    assert mark(app, world, wid, "other_one") == "ok"     # second call is a no-op
    assert full(app, world, wid)[3] == "email_reply"
    assert mark(app, world, wid, "BAD KEY") == "bad_input"
    assert mark(app, world, ulid(), "x") == "not_found"


# ---- pause / enable ----

def test_paused_workflow_is_skipped_by_the_schedule_but_can_run_by_hand(app, world, admin, owner):
    wid, _ = save(app, world, schedule="00:00")
    assert state(app, world, wid, pa=True) == "ok"
    assert due(admin) == 0
    rid, r = create(app, world, wid)
    assert r == "ok"                                      # manual run still allowed while paused
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET status='done', finished_at=now()")
    owner.execute("UPDATE wf_workflows SET last_scheduled_on=NULL")
    assert state(app, world, wid, pa=False) == "ok"
    assert due(admin) == 0                                # resuming after the time passed does not fire at once...
    assert full(app, world, wid)[5] is not None           # ...today is marked as done


def test_disabled_workflow_cannot_run_and_enable_keeps_schedule(app, world, admin):
    wid, _ = save(app, world, schedule="00:00")
    assert state(app, world, wid, en=False) == "ok"
    assert create(app, world, wid)[1] == "disabled"
    assert due(admin) == 0
    assert state(app, world, wid, en=True) == "ok"
    assert full(app, world, wid)[4].strftime("%H:%M") == "00:00"
    assert create(app, world, wid)[1] == "ok"


def test_set_state_null_leaves_values_and_is_owner_only(app, world, second_user):
    wid, _ = save(app, world)
    assert state(app, world, wid, pa=True) == "ok"
    row = full(app, world, wid)
    assert (row[6], row[7]) == (True, True)               # enabled untouched, paused set
    assert fn(app, world, "SELECT wf_set_state(%s,%s,%s,%s,%s)", (wid, world["a"]["tenant"], second_user, False, None)) == "not_found"
    assert state(app, world, wid, en=False, who="b") == "not_found"


# ---- steps / settings ----

def test_set_definition_changes_only_the_steps_and_bumps_version_only_on_change(app, world):
    wid, _ = save(app, world, name="Keep", desc="d", schedule="09:00", mx=7)
    new = {"start": "n1", "nodes": {"n1": {"type": "output", "text": "changed"}}}
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert fn(app, world, "SELECT wf_set_definition(%s,%s,%s,%s)", (wid, t, u, Jsonb(new))) == "ok"
    row = full(app, world, wid)
    assert (row[0], row[1], row[2], row[4].strftime("%H:%M"), float(row[9]), row[11]) == ("Keep", "d", 2, "09:00", 7.0, new)
    assert fn(app, world, "SELECT wf_set_definition(%s,%s,%s,%s)", (wid, t, u, Jsonb(new))) == "ok"
    assert full(app, world, wid)[2] == 2
    assert fn(app, world, "SELECT wf_set_definition(%s,%s,%s,%s)", (wid, t, u, Jsonb([1]))) == "bad_input"
    assert fn(app, world, "SELECT wf_set_definition(%s,%s,%s,%s)", (wid, world["b"]["tenant"], u, Jsonb(new)), "b") == "not_found"


def test_set_meta_validates_and_resets_schedule_day_only_when_time_changes(app, world, owner):
    wid, _ = save(app, world, schedule="08:00")
    as_admin(owner)
    owner.execute("UPDATE wf_workflows SET last_scheduled_on = current_date WHERE id=%s", (wid,))
    t, u = world["a"]["tenant"], world["a"]["user"]
    sql = "SELECT wf_set_meta(%s,%s,%s,%s,%s::time,%s::numeric,%s)"
    assert fn(app, world, sql, (wid, t, u, "knowledge", "08:00", 9, None)) == "ok"
    row = full(app, world, wid)
    assert (row[8], float(row[9]), row[5] is not None) == ("knowledge", 9.0, True)       # same time: "already ran today" kept
    assert fn(app, world, sql, (wid, t, u, "knowledge", "10:30", 9, None)) == "ok"
    assert full(app, world, wid)[5] is None                                              # new time: reset
    assert fn(app, world, sql, (wid, t, u, "gaming", None, 9, None)) == "bad_input"
    assert fn(app, world, sql, (wid, t, u, "desktop", None, 1001, None)) == "bad_input"
    assert fn(app, world, sql, (wid, t, u, "desktop", None, 5, ulid())) == "bad_input"   # unknown model


def test_set_meta_accepts_a_real_model_and_it_is_forgotten_when_the_model_is_deleted(app, world, admin, owner):
    from test_ai_catalog import mk_model, mk_provider
    mid = mk_model(admin, mk_provider(admin, "prov"), key="wf-model")
    wid, _ = save(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert fn(app, world, "SELECT wf_set_meta(%s,%s,%s,%s,%s::time,%s::numeric,%s)", (wid, t, u, "desktop", None, 5, mid)) == "ok"
    assert full(app, world, wid)[10].strip() == mid
    as_admin(owner)
    owner.execute("DELETE FROM ai_models WHERE id=%s", (mid,))
    owner.execute("DELETE FROM ai_providers WHERE name='prov'")
    assert full(app, world, wid)[10] is None


def test_new_and_old_categories_are_both_valid(app, world):
    for c in ("personal", "sales", "developer", "productivity", "communication", "knowledge", "desktop", "development", "marketing"):
        assert save(app, world, cat=c, name=c)[1] == "ok"


# ---- duplicate ----

def test_duplicate_copies_steps_but_is_off_unscheduled_and_fresh(app, world):
    wid, _ = save(app, world, name="Src", desc="d", cat="marketing", schedule="07:00", mx=3)
    mark(app, world, wid, "marketing_weekly")
    t, u = world["a"]["tenant"], world["a"]["user"]
    new = ulid()
    assert fn(app, world, "SELECT wf_duplicate(%s,%s,%s,%s,%s)", (wid, new, t, u, "Src (copy)")) == "ok"
    row = full(app, world, new)
    assert (row[0], row[1], row[2], row[3], row[4], row[6], row[7], row[8], float(row[9]), row[11]) == ("Src (copy)", "d", 1, "marketing_weekly", None, False, False, "marketing", 3.0, DEF)
    assert fn(app, world, "SELECT wf_duplicate(%s,%s,%s,%s,%s)", (ulid(), ulid(), t, u, "x")) == "not_found"
    assert fn(app, world, "SELECT wf_duplicate(%s,%s,%s,%s,%s)", (wid, ulid(), t, u, "  ")) == "bad_input"
    assert fn(app, world, "SELECT wf_duplicate(%s,%s,%s,%s,%s)", (wid, ulid(), world["b"]["tenant"], u, "x"), "b") == "not_found"


def test_duplicate_respects_the_50_limit(app, world):
    first, _ = save(app, world, name="w0")
    for i in range(1, 50):
        assert save(app, world, name=f"w{i}")[1] == "ok"
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert fn(app, world, "SELECT wf_duplicate(%s,%s,%s,%s,%s)", (first, ulid(), t, u, "one too many")) == "limit"


# ---- test runs ----

def test_test_run_works_when_disabled_is_marked_test_and_blocks_pile_ups(app, world, admin):
    wid, _ = save(app, world, enabled=False)
    t, u = world["a"]["tenant"], world["a"]["user"]
    rid = ulid()
    assert fn(app, world, "SELECT wf_test_run_create(%s,%s,%s,%s,%s)", (rid, t, u, wid, Jsonb({"x": "1"}))) == "ok"
    ctx(app, world, "a")
    assert app.execute("SELECT trigger, status, current_node, vars FROM wf_runs WHERE id=%s", (rid,)).fetchone() == ("test", "queued", "n1", {"x": "1"})
    assert fn(app, world, "SELECT wf_test_run_create(%s,%s,%s,%s,%s)", (ulid(), t, u, wid, Jsonb({}))) == "busy"
    assert create(app, world, wid)[1] == "disabled"
    assert fn(app, world, "SELECT wf_test_run_create(%s,%s,%s,%s,%s)", (ulid(), t, u, ulid(), Jsonb({}))) == "not_found"
    assert fn(app, world, "SELECT wf_test_run_create(%s,%s,%s,%s,%s)", (ulid(), t, u, wid, Jsonb([1]))) == "bad_input"


def test_test_run_is_owner_only(app, world, second_user):
    wid, _ = save(app, world)
    assert fn(app, world, "SELECT wf_test_run_create(%s,%s,%s,%s,%s)", (ulid(), world["a"]["tenant"], second_user, wid, Jsonb({}))) == "not_found"
    assert fn(app, world, "SELECT wf_test_run_create(%s,%s,%s,%s,%s)", (ulid(), world["b"]["tenant"], world["b"]["user"], wid, Jsonb({})), "b") == "not_found"


def test_web_role_cannot_write_the_new_columns_directly(app, world):
    import psycopg
    wid, _ = save(app, world)
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE wf_workflows SET name='x', paused=true WHERE id=%s", (wid,))
