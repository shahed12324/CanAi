"""Phase 28d: more webhook events (run.waiting_approval, pc.action.pending) and the per-address event choice."""
import psycopg
import pytest

from conftest import as_admin
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)
from test_webhooks import hook, backdate, enqueue, claim, finished_run
from test_workflows import create, save
from test_pc_control import device, policy, req, decide

SET = "SELECT webhook_set_events(%s,%s,%s,%s)"
ALL = ["run.finished", "run.waiting_approval", "pc.action.pending"]


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE pc_actions DISABLE TRIGGER pc_actions_guard_trg")
    for t in ("webhook_deliveries", "webhook_endpoints", "pc_actions", "pc_policies", "pc_pairings", "pc_devices", "wf_steps", "wf_runs", "wf_workflows"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE pc_actions ENABLE TRIGGER pc_actions_guard_trg")


def set_events(app, world, hid, events, who="a", user=None):
    ctx(app, world, who)
    return app.execute(SET, (hid, world[who]["tenant"], user or world[who]["user"], events)).fetchone()[0]


def reset_since(owner, hid):
    as_admin(owner)
    owner.execute("UPDATE webhook_endpoints SET events_at = now() - interval '1 hour', since_at = now() - interval '1 hour' WHERE id=%s", (hid,))


def waiting_run(app, world, owner, who="a", user=None):
    wid = save(app, world, who=who, user=user)[0]
    rid, r = create(app, world, wid, who=who, user=user)
    assert r == "ok"
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET status='waiting_approval', current_node='n1' WHERE id=%s", (rid,))
    return rid


def pending_action(app, world, who="a"):
    dev, _ = device(app, world, who)
    policy(app, world, dev, who)
    a = req(app, world, dev, "read_file", {"path": "/home/me/work/secret.txt"}, who)
    assert a["status"] == "pending"
    return dev, a["id"]


def events_of(owner, hid):
    as_admin(owner)
    return owner.execute("SELECT events FROM webhook_endpoints WHERE id=%s", (hid,)).fetchone()[0]


def test_default_is_run_finished_only_so_old_addresses_are_unchanged(app, world, owner, admin):
    hid, _ = hook(app, world)
    assert events_of(owner, hid) == ["run.finished"]
    reset_since(owner, hid)
    waiting_run(app, world, owner)
    pending_action(app, world)
    assert enqueue(admin) == 0


def test_set_events_roundtrip_dedupes_and_sorts(app, world, owner):
    hid, _ = hook(app, world)
    assert set_events(app, world, hid, ["run.waiting_approval", "run.finished", "run.waiting_approval"]) == "ok"
    assert events_of(owner, hid) == ["run.finished", "run.waiting_approval"]
    assert set_events(app, world, hid, ALL) == "ok"
    assert events_of(owner, hid) == sorted(ALL)


@pytest.mark.parametrize("bad", [None, [], ["nope"], ["run.finished", "x"], ["RUN.FINISHED"]])
def test_bad_event_lists_are_refused_and_change_nothing(app, world, owner, bad):
    hid, _ = hook(app, world)
    assert set_events(app, world, hid, bad) == "bad_input"
    assert events_of(owner, hid) == ["run.finished"]


def test_only_the_owner_in_their_workspace_can_change_events(app, world, owner, second_user):
    hid, _ = hook(app, world)
    assert set_events(app, world, hid, ALL, user=second_user) == "not_found"
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute(SET, (hid, world["a"]["tenant"], world["a"]["user"], ALL))
    app.rollback() if hasattr(app, "rollback") else None
    assert events_of(owner, hid) == ["run.finished"]
    assert set_events(app, world, "0" * 26, ALL) == "not_found"
    ctx(app, world)
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute(SET, (hid, world["a"]["tenant"], None, ALL))


def test_waiting_approval_message_carries_only_ids_and_status(app, world, owner, admin):
    hid, _ = hook(app, world)
    reset_since(owner, hid)
    assert set_events(app, world, hid, ["run.waiting_approval"]) == "ok"
    reset_since(owner, hid)
    rid = waiting_run(app, world, owner)
    assert enqueue(admin) == 1 and enqueue(admin) == 0
    p = claim(admin)[0][5]
    assert set(p) == {"event", "run_id", "workflow_id", "status", "node", "created_at"}
    assert p["event"] == "run.waiting_approval" and p["run_id"] == rid and p["status"] == "waiting_approval"


def test_pc_pending_message_has_no_params_or_paths(app, world, owner, admin):
    hid, _ = hook(app, world)
    assert set_events(app, world, hid, ["pc.action.pending"]) == "ok"
    reset_since(owner, hid)
    dev, aid = pending_action(app, world)
    assert enqueue(admin) == 1 and enqueue(admin) == 0
    p = claim(admin)[0][5]
    assert set(p) == {"event", "action_id", "device_id", "kind", "risk", "expires_at"}
    assert p["action_id"] == aid and p["kind"] == "read_file" and p["device_id"] == dev.strip()
    assert "secret.txt" not in str(p) and "/home/me" not in str(p)


def test_a_decided_action_is_no_longer_pending_and_is_not_sent(app, world, owner, admin):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ["pc.action.pending"])
    reset_since(owner, hid)
    dev, aid = pending_action(app, world)
    assert decide(app, world, aid, "reject") == "ok"
    assert enqueue(admin) == 0


def test_blocked_actions_are_not_sent(app, world, owner, admin):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ["pc.action.pending"])
    reset_since(owner, hid)
    dev, _ = device(app, world)
    policy(app, world, dev)
    assert req(app, world, dev, "read_file", {"path": "/etc/passwd"})["status"] == "blocked"
    assert enqueue(admin) == 0


def test_a_newly_picked_event_only_fires_for_things_after_the_choice(app, world, owner, admin):
    hid, _ = hook(app, world)
    reset_since(owner, hid)
    waiting_run(app, world, owner)                          # happened before the choice
    pending_action(app, world)
    assert set_events(app, world, hid, ALL) == "ok"
    assert enqueue(admin) == 0
    waiting_run(app, world, owner)                          # after
    assert enqueue(admin) == 1


def test_choosing_the_same_events_again_does_not_move_the_start_time(app, world, owner):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ["run.finished", "run.waiting_approval"])
    reset_since(owner, hid)
    set_events(app, world, hid, ["run.waiting_approval", "run.finished"])
    as_admin(owner)
    assert owner.execute("SELECT events_at < now() - interval '30 minutes' FROM webhook_endpoints WHERE id=%s", (hid,)).fetchone()[0] is True


def test_same_run_may_make_both_a_waiting_and_a_finished_message(app, world, owner, admin):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ["run.finished", "run.waiting_approval"])
    reset_since(owner, hid)
    rid = waiting_run(app, world, owner)
    assert enqueue(admin) == 1
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET status='done', finished_at=now() WHERE id=%s", (rid,))
    assert enqueue(admin) == 1
    assert sorted(r[5]["event"] for r in claim(admin)) == ["run.finished", "run.waiting_approval"]


def test_only_the_owners_own_things_and_only_in_their_workspace(app, world, owner, admin, second_user):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ALL)
    reset_since(owner, hid)
    waiting_run(app, world, owner, user=second_user)        # someone else's run
    waiting_run(app, world, owner, who="b")                 # other workspace
    pending_action(app, world, who="b")
    assert enqueue(admin) == 0


def test_switched_off_addresses_get_nothing(app, world, owner, admin):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ALL)
    reset_since(owner, hid)
    as_admin(owner)
    owner.execute("UPDATE webhook_endpoints SET active=false WHERE id=%s", (hid,))
    waiting_run(app, world, owner)
    pending_action(app, world)
    assert enqueue(admin) == 0


def test_old_things_are_not_sent(app, world, owner, admin):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ALL)
    reset_since(owner, hid)
    rid = waiting_run(app, world, owner)
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET created_at = now() - interval '2 days' WHERE id=%s", (rid,))
    assert enqueue(admin) == 0


def test_event_column_check_and_who_may_call_what(app, world, owner, admin):
    hid, _ = hook(app, world)
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute("UPDATE webhook_endpoints SET events = ARRAY['x'] WHERE id=%s", (hid,))
    owner.rollback() if hasattr(owner, "rollback") else None
    ctx(app, world)
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("UPDATE webhook_endpoints SET events = ARRAY['run.finished']")


def test_an_address_without_run_finished_does_not_get_it(app, world, owner, admin):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ["run.waiting_approval"])
    reset_since(owner, hid)
    finished_run(app, world, owner)
    assert enqueue(admin) == 0


def test_a_colleagues_computer_action_in_the_same_workspace_is_not_sent_to_me(app, world, owner, admin, second_user):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ["pc.action.pending"])
    reset_since(owner, hid)
    dev, _ = device(app, world, user=second_user)
    policy(app, world, dev, user=second_user)
    assert req(app, world, dev, "read_file", {"path": "/home/me/work/a.txt"}, user=second_user)["status"] == "pending"
    assert enqueue(admin) == 0


def test_an_expired_but_not_yet_swept_action_is_not_sent(app, world, owner, admin):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ["pc.action.pending"])
    reset_since(owner, hid)
    dev, aid = pending_action(app, world)
    as_admin(owner)
    owner.execute("ALTER TABLE pc_actions DISABLE TRIGGER pc_actions_guard_trg")
    owner.execute("UPDATE pc_actions SET expires_at = now() - interval '1 minute' WHERE id=%s", (aid,))
    owner.execute("ALTER TABLE pc_actions ENABLE TRIGGER pc_actions_guard_trg")
    assert enqueue(admin) == 0


def test_only_runs_that_are_waiting_are_sent_as_waiting(app, world, owner, admin):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ["run.waiting_approval"])
    reset_since(owner, hid)
    rid = waiting_run(app, world, owner)
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET status='running' WHERE id=%s", (rid,))
    assert enqueue(admin) == 0


def test_day_old_waiting_runs_and_actions_are_not_sent_even_if_the_choice_is_older(app, world, owner, admin):
    hid, _ = hook(app, world)
    set_events(app, world, hid, ALL)
    as_admin(owner)
    owner.execute("UPDATE webhook_endpoints SET events_at = now() - interval '5 days' WHERE id=%s", (hid,))
    rid = waiting_run(app, world, owner)
    dev, aid = pending_action(app, world)
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET created_at = now() - interval '2 days' WHERE id=%s", (rid,))
    owner.execute("ALTER TABLE pc_actions DISABLE TRIGGER pc_actions_guard_trg")
    owner.execute("UPDATE pc_actions SET created_at = now() - interval '2 days' WHERE id=%s", (aid,))
    owner.execute("ALTER TABLE pc_actions ENABLE TRIGGER pc_actions_guard_trg")
    assert enqueue(admin) == 0


@pytest.mark.parametrize("bad", ["ARRAY[]::text[]", "ARRAY['run.finished','run.finished','run.finished','run.finished','run.finished']"])
def test_column_check_refuses_empty_and_oversized_lists(app, world, owner, bad):
    hid, _ = hook(app, world)
    as_admin(owner)
    with pytest.raises(psycopg.errors.CheckViolation):
        owner.execute(f"UPDATE webhook_endpoints SET events = {bad} WHERE id=%s", (hid,))
