import datetime as dt
import json

import psycopg
import pytest
from psycopg.types.json import Jsonb

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)

DEF = {"start": "n1", "nodes": {"n1": {"type": "output", "text": "hi"}}}


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("wf_steps", "wf_runs", "wf_workflows"):
        owner.execute(f"DELETE FROM {t}")


def save(app, world, *, name="সকালের কাজ", desc="", cat="personal", definition=DEF, enabled=True, schedule=None, mx=5, new=True, wid=None, who="a", user=None):
    ctx(app, world, who)
    wid = wid or ulid()
    r = app.execute("SELECT wf_save(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s::numeric,%s)", (wid, world[who]["tenant"], user or world[who]["user"], name, desc, cat, Jsonb(definition) if definition is not None else None, enabled, schedule, mx, new)).fetchone()[0]
    return wid, r


def wrow(app, world, wid, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT name, category, version, enabled, schedule_time, max_credits, definition FROM wf_workflows WHERE id=%s", (wid,)).fetchone()


def create(app, world, wid, vars_=None, who="a", user=None, rid=None):
    ctx(app, world, who)
    rid = rid or ulid()
    r = app.execute("SELECT wf_run_create(%s,%s,%s,%s,%s)", (rid, world[who]["tenant"], user or world[who]["user"], wid, Jsonb(vars_ if vars_ is not None else {}))).fetchone()[0]
    return rid, r


def run(app, world, rid, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT status, current_node, spent, error, vars, version, max_credits, definition FROM wf_runs WHERE id=%s", (rid,)).fetchone()


# ---- workflows ----

def test_save_normalises_and_defaults(app, world):
    wid, r = save(app, world, name="  সকালের   কাজ ", desc=" বিবরণ ")
    assert r == "ok"
    assert wrow(app, world, wid) == ("সকালের কাজ", "personal", 1, True, None, 5, DEF)


@pytest.mark.parametrize("kw", [{"name": ""}, {"name": "  "}, {"name": None}, {"name": "ক" * 121}, {"desc": "ক" * 501}, {"cat": "gaming"}, {"cat": None}, {"definition": None}, {"definition": [1]}, {"definition": {"x": "a" * 100001}}, {"mx": -1}, {"mx": 1001}])
def test_bad_input(app, world, kw):
    assert save(app, world, **kw)[1] == "bad_input"


def test_exact_limits(app, world):
    assert save(app, world, name="ক" * 120, desc="খ" * 500, mx=1000)[1] == "ok"
    assert save(app, world, mx=0)[1] == "ok"
    assert save(app, world, definition={"x": "a" * 99000})[1] == "ok"


@pytest.mark.parametrize("cat", ["personal", "sales", "marketing", "ecommerce", "finance", "developer"])
def test_every_category(app, world, cat):
    assert save(app, world, cat=cat)[1] == "ok"


def test_limit_50_per_person(app, world, owner, second_user):
    as_admin(owner)
    owner.execute("INSERT INTO wf_workflows (id,tenant_id,user_id,name,definition) SELECT lpad(g::text,26,'w'),%s,%s,'w '||g,'{}' FROM generate_series(1,50) g", (world["a"]["tenant"], world["a"]["user"]))
    assert save(app, world)[1] == "limit"
    assert save(app, world, user=second_user)[1] == "ok"


def test_editing_the_steps_bumps_the_version_only_when_they_change(app, world):
    wid, _ = save(app, world)
    assert save(app, world, name="নতুন নাম", new=False, wid=wid)[1] == "ok"
    assert wrow(app, world, wid)[:3] == ("নতুন নাম", "personal", 1)
    other = {"start": "n1", "nodes": {"n1": {"type": "output", "text": "bye"}}}
    assert save(app, world, definition=other, new=False, wid=wid)[1] == "ok"
    assert wrow(app, world, wid)[2] == 2
    assert wrow(app, world, wid)[6] == other


def test_edit_missing_or_not_yours(app, world, second_user):
    wid, _ = save(app, world)
    assert save(app, world, new=False, wid=ulid())[1] == "not_found"
    assert save(app, world, new=False, wid=wid, user=second_user)[1] == "not_found"
    assert save(app, world, new=False, wid=wid, who="b", user=world["b"]["user"])[1] == "not_found"


def test_changing_the_schedule_allows_a_fresh_run_today(app, world, owner):
    wid, _ = save(app, world, schedule="00:00")
    as_admin(owner)
    owner.execute("UPDATE wf_workflows SET last_scheduled_on = current_date WHERE id=%s", (wid,))
    save(app, world, schedule="00:00", new=False, wid=wid)
    as_admin(owner)
    assert owner.execute("SELECT last_scheduled_on FROM wf_workflows WHERE id=%s", (wid,)).fetchone()[0] is not None
    save(app, world, schedule="00:30", new=False, wid=wid)
    as_admin(owner)
    assert owner.execute("SELECT last_scheduled_on FROM wf_workflows WHERE id=%s", (wid,)).fetchone()[0] is None


def test_user_required_and_tenant_check(app, world):
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT wf_save(%s,%s,NULL,'n','','personal',%s,true,NULL,5,true)", (ulid(), world["a"]["tenant"], Jsonb(DEF)))
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT wf_save(%s,%s,%s,'n','','personal',%s,true,NULL,5,true)", (ulid(), world["a"]["tenant"], world["b"]["user"], Jsonb(DEF)))


def test_delete_is_personal_and_removes_runs(app, world, second_user, owner):
    wid, _ = save(app, world)
    rid, _ = create(app, world, wid)
    ctx(app, world, "a")
    assert app.execute("SELECT wf_delete(%s,%s,%s)", (wid, world["a"]["tenant"], second_user)).fetchone()[0] == "not_found"
    assert app.execute("SELECT wf_delete(%s,%s,%s)", (wid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert app.execute("SELECT wf_delete(%s,%s,%s)", (wid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "not_found"
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM wf_runs WHERE id=%s", (rid,)).fetchone()[0] == 0


def test_other_workspace_sees_nothing_and_app_cannot_write(app, world):
    wid, _ = save(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM wf_workflows").fetchone()[0] == 0
    ctx(app, world, "a")
    for sql in ("INSERT INTO wf_workflows (id,tenant_id,user_id,name,definition) VALUES ('%s','%s','%s','x','{}')" % (ulid(), world["a"]["tenant"], world["a"]["user"]), "UPDATE wf_workflows SET name='x'", "DELETE FROM wf_runs", "UPDATE wf_steps SET output='x'"):
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)


# ---- runs ----

def test_a_run_snapshots_the_steps_and_starts_at_the_start_node(app, world):
    wid, _ = save(app, world, mx=2.5)
    rid, r = create(app, world, wid, {"email": "হ্যালো"})
    assert r == "ok"
    st, node, spent, err, vars_, ver, mx, d = run(app, world, rid)
    assert (st, node, float(spent), err, vars_, ver, float(mx), d) == ("queued", "n1", 0.0, None, {"email": "হ্যালো"}, 1, 2.5, DEF)
    other = {"start": "n9", "nodes": {"n9": {"type": "output", "text": "x"}}}
    save(app, world, definition=other, new=False, wid=wid)
    assert run(app, world, rid)[7] == DEF                      # a run in progress is not changed by later edits


def test_create_refusals(app, world, second_user):
    wid, _ = save(app, world)
    assert create(app, world, ulid())[1] == "not_found"
    assert create(app, world, wid, user=second_user)[1] == "not_found"
    assert create(app, world, wid, who="b", user=world["b"]["user"])[1] == "not_found"
    ctx(app, world, "a")
    for bad in (Jsonb([1]), Jsonb({"x": "a" * 50001})):
        assert app.execute("SELECT wf_run_create(%s,%s,%s,%s,%s)", (ulid(), world["a"]["tenant"], world["a"]["user"], wid, bad)).fetchone()[0] == "bad_input"
    d, _ = save(app, world, enabled=False)
    assert create(app, world, d)[1] == "disabled"
    assert create(app, world, wid)[1] == "ok"
    assert create(app, world, wid)[1] == "busy"


def test_a_finished_run_frees_the_workflow(app, world, owner, admin):
    wid, _ = save(app, world)
    rid, _ = create(app, world, wid)
    assert create(app, world, wid)[1] == "busy"
    ctx(app, world, "a")
    assert app.execute("SELECT wf_run_cancel(%s,%s,%s)", (rid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    assert create(app, world, wid)[1] == "ok"


def claim(admin, n=10):
    as_admin(admin)
    return admin.execute("SELECT run_id, tenant_id, user_id FROM wf_run_claim(%s)", (n,)).fetchall()


def test_claim_takes_each_queued_run_once_oldest_first(app, world, admin, owner, second_user):
    w1, _ = save(app, world, name="a")
    w2, _ = save(app, world, name="b")
    r1, _ = create(app, world, w1)
    r2, _ = create(app, world, w2)
    got = claim(admin, 1)
    assert [g[0].strip() for g in got] == [r1] and got[0][1].strip() == world["a"]["tenant"] and got[0][2].strip() == world["a"]["user"]
    assert run(app, world, r1)[0] == "running"
    assert [g[0].strip() for g in claim(admin)] == [r2]
    assert claim(admin) == []


def test_claim_fails_runs_stuck_for_15_minutes(app, world, admin, owner):
    wid, _ = save(app, world)
    rid, _ = create(app, world, wid)
    claim(admin)
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET started_at = now() - interval '16 minutes', claimed_at = now() - interval '16 minutes' WHERE id=%s", (rid,))
    claim(admin)
    st, _, _, err, *_ = run(app, world, rid)
    assert st == "failed" and err
    owner.execute("UPDATE wf_runs SET status='running', started_at = now() - interval '14 minutes', claimed_at = now() - interval '14 minutes' WHERE id=%s", (rid,))
    claim(admin)
    as_admin(owner)
    assert owner.execute("SELECT status FROM wf_runs WHERE id=%s", (rid,)).fetchone()[0] == "running"


def test_only_admin_may_claim_or_schedule(app, world):
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT * FROM wf_run_claim(5)")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT wf_due()")


def record(app, world, rid, node="n1", typ="output", status="ok", out="hi", credits=0, vars_=None, nxt=None, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT wf_step_record(%s,%s,%s,%s,%s,%s,%s::numeric,%s,%s)", (rid, world[who]["tenant"], node, typ, status, out, credits, Jsonb(vars_) if vars_ is not None else None, nxt)).fetchone()[0]


def end(app, world, rid, status="done", error=None, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT wf_run_end(%s,%s,%s,%s)", (rid, world[who]["tenant"], status, error)).fetchone()[0]


def started(app, world, admin, **kw):
    wid, _ = save(app, world, **kw)
    rid, _ = create(app, world, wid)
    claim(admin)
    return wid, rid


def test_step_record_logs_moves_on_and_adds_up_the_spend(app, world, admin):
    _, rid = started(app, world, admin)
    assert record(app, world, rid, "n1", "ai", "ok", "উত্তর", 0.25, {"a": "1"}, "n2") == "ok"
    assert record(app, world, rid, "n2", "tool", "ok", "ঠিক", 0.5, None, "n3") == "ok"
    st, node, spent, _, vars_, *_ = run(app, world, rid)
    assert (st, node, float(spent), vars_) == ("running", "n3", 0.75, {"a": "1"})      # NULL vars = unchanged
    ctx(app, world, "a")
    assert app.execute("SELECT seq, node_id, node_type, status, output, credits FROM wf_steps WHERE run_id=%s ORDER BY seq", (rid,)).fetchall() == [(1, "n1", "ai", "ok", "উত্তর", 0.25), (2, "n2", "tool", "ok", "ঠিক", 0.5)]


def test_step_record_only_works_on_a_running_run(app, world, admin):
    wid, _ = save(app, world)
    rid, _ = create(app, world, wid)
    assert record(app, world, rid) == "not_running"            # still queued
    claim(admin)
    ctx(app, world, "a")
    app.execute("SELECT wf_run_cancel(%s,%s,%s)", (rid, world["a"]["tenant"], world["a"]["user"]))
    assert record(app, world, rid) == "not_running"            # a cancelled run stops here
    assert record(app, world, ulid()) == "not_running"
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM wf_steps").fetchone()[0] == 0


def test_step_record_validates(app, world, admin):
    _, rid = started(app, world, admin)
    ctx(app, world, "a")
    for status, credits in (("weird", 0), (None, 0), ("ok", -1)):
        with pytest.raises(psycopg.errors.InvalidParameterValue):
            app.execute("SELECT wf_step_record(%s,%s,'n','t',%s,'',%s,NULL,NULL)", (rid, world["a"]["tenant"], status, credits))
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT wf_step_record(%s,%s,'n','t','ok','',0,NULL,NULL)", (rid, world["a"]["tenant"]))


def test_step_output_is_cut_to_4000(app, world, admin):
    _, rid = started(app, world, admin)
    assert record(app, world, rid, out="ক" * 5000) == "ok"
    ctx(app, world, "a")
    assert app.execute("SELECT length(output) FROM wf_steps WHERE run_id=%s", (rid,)).fetchone()[0] == 4000


def test_run_end(app, world, admin):
    _, rid = started(app, world, admin)
    assert end(app, world, rid, "done") == "ok"
    st, _, _, err, *_ = run(app, world, rid)
    assert st == "done" and err is None
    assert end(app, world, rid, "done") == "not_running"
    _, rid2 = started(app, world, admin, name="two")
    assert end(app, world, rid2, "failed", "ক" * 400) == "ok"
    assert len(run(app, world, rid2)[3]) == 300
    ctx(app, world, "a")
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT wf_run_end(%s,%s,'queued',NULL)", (rid2, world["a"]["tenant"]))


def test_approval_pause_approve_and_reject(app, world, admin, second_user):
    wid, rid = started(app, world, admin)
    assert record(app, world, rid, "ap", "approval", "waiting", "অনুমোদন চাই", 0, None, "ap") == "ok"
    assert end(app, world, rid, "waiting_approval") == "ok"
    assert run(app, world, rid)[0] == "waiting_approval"
    ctx(app, world, "a")
    t = world["a"]["tenant"]
    assert app.execute("SELECT wf_run_decide(%s,%s,%s,true)", (rid, t, second_user)).fetchone()[0] == "not_found"          # only the owner decides
    assert app.execute("SELECT wf_run_decide(%s,%s,%s,true)", (ulid(), t, world["a"]["user"])).fetchone()[0] == "not_found"
    assert app.execute("SELECT wf_run_decide(%s,%s,%s,true)", (rid, t, world["a"]["user"])).fetchone()[0] == "ok"
    st, node, _, _, vars_, *_ = run(app, world, rid)
    assert (st, node, vars_) == ("queued", "ap", {"__approved:ap": True})
    assert app.execute("SELECT wf_run_decide(%s,%s,%s,true)", (rid, t, world["a"]["user"])).fetchone()[0] == "not_waiting"
    # a second run is rejected
    claim(admin)
    record(app, world, rid, "x", "output", "ok", "", 0, None, None)
    end(app, world, rid, "done")
    rid2, _ = create(app, world, wid)
    claim(admin)
    record(app, world, rid2, "ap", "approval", "waiting", "", 0, None, "ap")
    end(app, world, rid2, "waiting_approval")
    ctx(app, world, "a")
    assert app.execute("SELECT wf_run_decide(%s,%s,%s,false)", (rid2, t, world["a"]["user"])).fetchone()[0] == "ok"
    st, _, _, err, *_ = run(app, world, rid2)
    assert st == "cancelled" and err


def test_cancel(app, world, admin, second_user):
    wid, _ = save(app, world)
    rid, _ = create(app, world, wid)
    ctx(app, world, "a")
    t = world["a"]["tenant"]
    assert app.execute("SELECT wf_run_cancel(%s,%s,%s)", (rid, t, second_user)).fetchone()[0] == "not_found"
    assert app.execute("SELECT wf_run_cancel(%s,%s,%s)", (rid, t, world["a"]["user"])).fetchone()[0] == "ok"
    assert app.execute("SELECT wf_run_cancel(%s,%s,%s)", (rid, t, world["a"]["user"])).fetchone()[0] == "finished"
    assert app.execute("SELECT wf_run_cancel(%s,%s,%s)", (ulid(), t, world["a"]["user"])).fetchone()[0] == "not_found"
    assert claim(admin) == []                                    # a cancelled run is never picked up


# ---- daily schedule ----

def due(admin):
    as_admin(admin)
    return admin.execute("SELECT wf_due()").fetchone()[0]


def test_schedule_runs_once_a_day_after_the_time(app, world, admin, owner):
    wid, _ = save(app, world, schedule="00:00")
    assert due(admin) == 1
    ctx(app, world, "a")
    assert app.execute("SELECT trigger, status, current_node, vars FROM wf_runs WHERE workflow_id=%s", (wid,)).fetchall() == [("schedule", "queued", "n1", {})]
    assert due(admin) == 0                                        # already done today (and a run is waiting)
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET status='done', finished_at=now() WHERE workflow_id=%s", (wid,))
    assert due(admin) == 0                                        # finished, but today's run already happened


def test_schedule_not_before_its_time_not_when_disabled_not_when_busy(app, world, admin, owner):
    save(app, world, schedule="23:59:59", name="late")
    save(app, world, schedule="00:00", enabled=False, name="off")
    busy, _ = save(app, world, schedule="00:00", name="busy")
    create(app, world, busy)
    save(app, world, schedule=None, name="manual")
    assert due(admin) == 0
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM wf_runs WHERE trigger='schedule'").fetchone()[0] == 0


def test_schedule_uses_the_workflows_own_time_zone(app, world, admin, owner):
    wid, _ = save(app, world, schedule="00:00")
    as_admin(owner)
    owner.execute("UPDATE wf_workflows SET schedule_zone='Pacific/Kiritimati' WHERE id=%s", (wid,))
    assert due(admin) == 1                                        # 00:00 has passed in every zone


def test_old_finished_runs_are_cleaned_up(app, world, admin, owner):
    wid, _ = save(app, world)
    rid, _ = create(app, world, wid)
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET status='done', finished_at = now() - interval '31 days' WHERE id=%s", (rid,))
    due(admin)
    assert owner.execute("SELECT count(*) FROM wf_runs WHERE id=%s", (rid,)).fetchone()[0] == 0


# ---- delay step (phase 21b) ----

def delay(app, world, rid, node="d1", nxt="n2", minutes=30, vars_=None, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT wf_step_delay(%s,%s,%s,%s,%s,%s)", (rid, world[who]["tenant"], node, nxt, minutes, Jsonb(vars_) if vars_ is not None else None)).fetchone()[0]


def waiting(owner, rid):
    as_admin(owner)
    return owner.execute("SELECT not_before IS NOT NULL, not_before <= now() FROM wf_runs WHERE id=%s", (rid,)).fetchone()


def test_delay_parks_the_run_and_logs_the_step(app, world, admin, owner):
    _, rid = started(app, world, admin)
    assert record(app, world, rid, "n1", "ai", "ok", "x", 0.5, {"a": "1"}, "d1") == "ok"
    assert delay(app, world, rid, "d1", "n2", 30, {"a": "1", "b": "2"}) == "ok"
    st, node, spent, _, vars_, *_ = run(app, world, rid)
    assert (st, node, float(spent), vars_) == ("queued", "n2", 0.5, {"a": "1", "b": "2"})
    ctx(app, world, "a")
    assert app.execute("SELECT seq, node_id, node_type, status, output, credits FROM wf_steps WHERE run_id=%s ORDER BY seq", (rid,)).fetchall()[-1] == (2, "d1", "delay", "ok", "30 মিনিট অপেক্ষা", 0)
    assert waiting(owner, rid) == (True, False)
    assert claim(admin) == []                                    # not due yet: nobody takes it


def test_a_due_run_is_picked_up_again_and_continues_where_it_waited(app, world, admin, owner):
    _, rid = started(app, world, admin)
    delay(app, world, rid, "d1", "n2", 30)
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET not_before = now() - interval '1 second' WHERE id=%s", (rid,))
    assert [g[0].strip() for g in claim(admin)] == [rid]
    st, node, *_ = run(app, world, rid)
    assert (st, node) == ("running", "n2")
    assert waiting(owner, rid)[0] is False                           # the wait is over and cleared
    assert record(app, world, rid, "n2", "output", "ok", "ok", 0, None, None) == "ok"
    assert claim(admin) == []


def test_a_delayed_run_is_not_failed_as_stuck_because_its_start_is_old(app, world, admin, owner):
    _, rid = started(app, world, admin)
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET started_at = now() - interval '2 days' WHERE id=%s", (rid,))
    delay(app, world, rid, "d1", "n2", 1440)
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET not_before = now() - interval '1 second' WHERE id=%s", (rid,))
    assert [g[0].strip() for g in claim(admin)] == [rid]
    claim(admin)                                                 # the stuck sweep runs again: claimed_at is fresh
    assert run(app, world, rid)[0] == "running"
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET claimed_at = now() - interval '16 minutes' WHERE id=%s", (rid,))
    claim(admin)
    assert run(app, world, rid)[0] == "failed"                   # but a worker that really died is still caught


def test_delayed_runs_come_back_oldest_due_first(app, world, admin, owner):
    w1, _ = save(app, world, name="a")
    w2, _ = save(app, world, name="b")
    r1, _ = create(app, world, w1)
    r2, _ = create(app, world, w2)
    claim(admin)
    delay(app, world, r1, minutes=60)
    delay(app, world, r2, minutes=10)
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET not_before = now() - interval '5 minutes' WHERE id=%s", (r1,))
    owner.execute("UPDATE wf_runs SET not_before = now() - interval '9 minutes' WHERE id=%s", (r2,))
    assert [g[0].strip() for g in claim(admin, 1)] == [r2]
    assert [g[0].strip() for g in claim(admin, 1)] == [r1]


@pytest.mark.parametrize("minutes", [0, -1, 1441, None])
def test_delay_limits(app, world, admin, minutes):
    _, rid = started(app, world, admin)
    assert delay(app, world, rid, minutes=minutes) == "bad_input"
    assert run(app, world, rid)[0] == "running"


def test_delay_boundaries_are_allowed(app, world, admin):
    _, rid = started(app, world, admin)
    assert delay(app, world, rid, minutes=1) == "ok"


def test_delay_needs_a_next_step_and_a_running_run(app, world, admin):
    _, rid = started(app, world, admin)
    assert delay(app, world, rid, nxt="") == "bad_input"
    assert delay(app, world, rid, nxt=None) == "bad_input"
    wid, _ = save(app, world, name="q")
    queued, _ = create(app, world, wid)
    assert delay(app, world, queued) == "not_running"
    assert delay(app, world, ulid()) == "not_running"
    ctx(app, world, "a")
    app.execute("SELECT wf_run_cancel(%s,%s,%s)", (rid, world["a"]["tenant"], world["a"]["user"]))
    assert delay(app, world, rid) == "not_running"
    ctx(app, world, "a")
    assert app.execute("SELECT count(*) FROM wf_steps").fetchone()[0] == 0


def test_a_waiting_run_can_be_cancelled_and_then_never_wakes(app, world, admin, owner):
    _, rid = started(app, world, admin)
    delay(app, world, rid, minutes=1)
    ctx(app, world, "a")
    assert app.execute("SELECT wf_run_cancel(%s,%s,%s)", (rid, world["a"]["tenant"], world["a"]["user"])).fetchone()[0] == "ok"
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET not_before = now() - interval '1 hour' WHERE id=%s", (rid,))
    assert claim(admin) == []


def test_another_workspace_cannot_park_a_run(app, world, admin):
    _, rid = started(app, world, admin)
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT wf_step_delay(%s,%s,'d','n',5,NULL)", (rid, world["a"]["tenant"]))
