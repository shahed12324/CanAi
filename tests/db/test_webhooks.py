"""Phase 27.2b: outgoing webhooks."""
import psycopg
import pytest

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)
from test_workflows import create, save

URL = "https://hooks.example.com/jarvis"
CREATE = "SELECT webhook_create(%s,%s,%s,%s,%s,%s,%s)"


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    for t in ("webhook_deliveries", "webhook_endpoints", "wf_steps", "wf_runs", "wf_workflows"):
        owner.execute(f"DELETE FROM {t}")


def hook(app, world, *, who="a", user=None, name="n8n", url=URL, cipher="ENC(x)", version=1, hid=None):
    ctx(app, world, who)
    hid = hid or ulid()
    return hid, app.execute(CREATE, (hid, world[who]["tenant"], user or world[who]["user"], name, url, cipher, version)).fetchone()[0]


def finished_run(app, world, owner, status="done", who="a", spent="1.5", wid=None, user=None):
    wid = wid or save(app, world, who=who, user=user)[0]
    rid, r = create(app, world, wid, who=who, user=user)
    assert r == "ok"
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET status=%s, spent=%s, finished_at=now() WHERE id=%s", (status, spent, rid))
    return rid


def backdate(owner, hid):
    as_admin(owner)
    owner.execute("UPDATE webhook_endpoints SET since_at = now() - interval '1 hour' WHERE id=%s", (hid,))


def enqueue(admin):
    as_admin(admin)
    return admin.execute("SELECT webhook_enqueue()").fetchone()[0]


def claim(admin, n=20):
    as_admin(admin)
    return admin.execute("SELECT id, tenant_id, url, secret_cipher, secret_version, payload, attempts FROM webhook_claim(%s)", (n,)).fetchall()


def result(admin, did, ok, code=None, err=None):
    as_admin(admin)
    return admin.execute("SELECT webhook_result(%s,%s,%s,%s)", (did, ok, code, err)).fetchone()[0]


def test_create_and_the_secret_is_unreadable_by_the_app(app, world):
    hid, r = hook(app, world)
    assert r == "ok"
    row = app.execute("SELECT name, url, active, failures FROM webhook_endpoints WHERE id=%s", (hid,)).fetchone()
    assert row == ("n8n", URL, True, 0)
    with pytest.raises(psycopg.Error):
        app.execute("SELECT secret_cipher FROM webhook_endpoints")
    app.rollback()


@pytest.mark.parametrize("kw", [
    dict(name="  "), dict(name="x" * 81), dict(url="http://hooks.example.com/x"), dict(url="ftp://a.com"), dict(url="https://"), dict(url="https://user:pw@a.com/x"),
    dict(url="https://a.com/ x"), dict(url="https://a.com/" + "p" * 500), dict(cipher=""), dict(url="javascript:alert(1)"),
])
def test_bad_input_is_refused(app, world, kw):
    assert hook(app, world, **kw)[1] == "bad_input"


def test_exact_limits_five_per_person_and_duplicates(app, world):
    assert hook(app, world, name="x" * 80, url="https://a.com/" + "p" * 484)[1] == "ok"          # 500 chars exactly
    ids = [hook(app, world)[0] for _ in range(4)]
    assert hook(app, world)[1] == "limit"
    assert hook(app, world, who="b")[1] == "ok"                                                  # another workspace has its own five
    assert hook(app, world, hid=ids[0])[1] in ("limit",)


def test_a_run_that_finishes_makes_exactly_one_signed_message_and_nothing_else_leaks(app, world, owner, admin):
    hid, _ = hook(app, world)
    backdate(owner, hid)
    rid = finished_run(app, world, owner, spent="2.5")
    assert enqueue(admin) == 1
    assert enqueue(admin) == 0                                  # safe to run again
    got = claim(admin)
    assert len(got) == 1
    did, tenant, url, cipher, version, payload, attempts = got[0]
    assert (tenant.strip(), url, cipher, version, attempts) == (world["a"]["tenant"], URL, "ENC(x)", 1, 1)
    assert set(payload) == {"event", "run_id", "workflow_id", "status", "spent", "finished_at"}
    assert payload["event"] == "run.finished" and payload["run_id"] == rid and payload["status"] == "done" and payload["spent"] == "2.5000"
    assert claim(admin) == []                                    # leased: not handed out twice


@pytest.mark.parametrize("status", ["done", "failed", "cancelled"])
def test_every_final_status_is_reported(app, world, owner, admin, status):
    hid, _ = hook(app, world)
    backdate(owner, hid)
    finished_run(app, world, owner, status=status)
    assert enqueue(admin) == 1
    assert claim(admin)[0][5]["status"] == status


def test_unfinished_old_foreign_and_before_the_address_existed_runs_are_not_sent(app, world, owner, admin):
    hid, _ = hook(app, world)
    wid = save(app, world)[0]
    qrid, _ = create(app, world, wid)                            # still queued
    assert enqueue(admin) == 0
    as_admin(owner)
    owner.execute("UPDATE wf_runs SET status='cancelled' WHERE id=%s", (qrid,))      # ended but without a finish time: still nothing to send
    assert enqueue(admin) == 0
    finished_run(app, world, owner)                              # finished AFTER creation but not after since_at=now()? since_at = creation time, finished later -> counts
    assert enqueue(admin) == 1
    backdate(owner, hid)
    as_admin(owner)
    owner.execute("UPDATE webhook_endpoints SET since_at = now() + interval '1 hour' WHERE id=%s", (hid,))
    finished_run(app, world, owner, wid=wid)
    assert enqueue(admin) == 0                                   # finished before "since"
    owner.execute("UPDATE webhook_endpoints SET since_at = now() - interval '2 days' WHERE id=%s", (hid,))
    rid = finished_run(app, world, owner, wid=wid)
    owner.execute("UPDATE wf_runs SET finished_at = now() - interval '2 days' WHERE id=%s", (rid,))
    assert enqueue(admin) == 1                                   # the fresh one (the 2-day-old run is skipped)


def test_only_the_owners_own_runs_in_their_own_workspace(app, world, owner, admin, second_user):
    hid, _ = hook(app, world)
    backdate(owner, hid)
    finished_run(app, world, owner, who="b")                     # other workspace
    assert enqueue(admin) == 0
    finished_run(app, world, owner, user=second_user)            # another person, same workspace
    assert enqueue(admin) == 0


def test_switched_off_addresses_get_nothing_and_switching_on_starts_from_now(app, world, owner, admin):
    hid, _ = hook(app, world)
    backdate(owner, hid)
    ctx(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert app.execute("SELECT webhook_set_active(%s,%s,%s,false)", (hid, t, u)).fetchone()[0] == "ok"
    finished_run(app, world, owner)
    assert enqueue(admin) == 0
    as_admin(owner)
    owner.execute("UPDATE webhook_endpoints SET failures=10, last_error='boom' WHERE id=%s", (hid,))
    ctx(app, world)
    assert app.execute("SELECT webhook_set_active(%s,%s,%s,true)", (hid, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT failures, last_error FROM webhook_endpoints WHERE id=%s", (hid,)).fetchone() == (0, None)
    assert enqueue(admin) == 0                                   # the run that finished while it was off is not sent late
    assert app.execute("SELECT webhook_set_active(%s,%s,%s,true)", (hid, t, "U" * 26)).fetchone()[0] == "not_found"
    assert app.execute("SELECT webhook_set_active(%s,%s,%s,NULL)", (hid, t, u)).fetchone()[0] == "not_found"


def test_success_marks_sent_and_clears_the_failure_count(app, world, owner, admin):
    hid, _ = hook(app, world)
    backdate(owner, hid)
    finished_run(app, world, owner)
    enqueue(admin)
    did = claim(admin)[0][0]
    as_admin(owner)
    owner.execute("UPDATE webhook_endpoints SET failures=3, last_error='x' WHERE id=%s", (hid,))
    assert result(admin, did, True, 200) == "ok"
    ctx(app, world)
    assert app.execute("SELECT status, last_code FROM webhook_deliveries").fetchone() == ("sent", 200)
    assert app.execute("SELECT failures, last_error, last_ok_at IS NOT NULL FROM webhook_endpoints").fetchone() == (0, None, True)
    assert result(admin, did, True, 200) == "not_found"          # a settled message cannot be changed again


def test_failure_backs_off_then_gives_up_after_six_tries(app, world, owner, admin):
    hid, _ = hook(app, world)
    backdate(owner, hid)
    finished_run(app, world, owner)
    enqueue(admin)
    expected = [1, 5, 15, 60, 240]
    for i, mins in enumerate(expected, start=1):
        did = claim(admin)[0][0]
        assert result(admin, did, False, 500, "boom") == "ok"
        as_admin(owner)
        delta = owner.execute("SELECT (next_at - now()) FROM webhook_deliveries WHERE id=%s", (did,)).fetchone()[0].total_seconds() / 60
        assert mins - 0.5 < delta <= mins, (i, delta)
        assert claim(admin) == []                                # not due yet
        owner.execute("UPDATE webhook_deliveries SET next_at = now() - interval '1 second' WHERE id=%s", (did,))
    did = claim(admin)[0][0]
    assert result(admin, did, False, 502, "bad gateway") == "ok"  # 6th try fails -> dead
    as_admin(owner)
    assert owner.execute("SELECT status, attempts, last_code FROM webhook_deliveries").fetchone() == ("dead", 6, 502)
    assert claim(admin) == []


def test_ten_failures_in_a_row_switch_the_address_off_and_a_success_resets_the_count(app, world, owner, admin):
    hid, _ = hook(app, world)
    backdate(owner, hid)
    for _ in range(10):
        finished_run(app, world, owner)
    enqueue(admin)
    ids = [r[0] for r in claim(admin)]
    assert len(ids) == 10
    for d in ids[:9]:
        result(admin, d, False, 500, "e" * 300)
    as_admin(owner)
    assert owner.execute("SELECT active, failures, char_length(last_error) FROM webhook_endpoints").fetchone() == (True, 9, 120)
    result(admin, ids[9], True, 200)
    assert owner.execute("SELECT active, failures FROM webhook_endpoints").fetchone() == (True, 0)
    # now ten bad ones in a row
    for _ in range(10):
        finished_run(app, world, owner)
    enqueue(admin)
    as_admin(owner)
    owner.execute("UPDATE webhook_deliveries SET next_at = now() - interval '1 second'")
    ids = [r[0] for r in claim(admin, 50)]
    for d in ids[:10]:
        result(admin, d, False, 500, "x")
    as_admin(owner)
    assert owner.execute("SELECT active, failures FROM webhook_endpoints").fetchone() == (False, 10)
    owner.execute("UPDATE webhook_deliveries SET next_at = now() - interval '1 second'")
    assert claim(admin) == []                                    # a switched-off address gets nothing more


def test_delete_is_the_owners_only_and_takes_the_messages_with_it(app, world, owner, admin):
    hid, _ = hook(app, world)
    backdate(owner, hid)
    finished_run(app, world, owner)
    enqueue(admin)
    t, u = world["a"]["tenant"], world["a"]["user"]
    ctx(app, world)
    assert app.execute("SELECT webhook_delete(%s,%s,%s)", (hid, t, "U" * 26)).fetchone()[0] == "not_found"
    assert app.execute("SELECT webhook_delete(%s,%s,%s)", (hid, t, u)).fetchone()[0] == "ok"
    assert app.execute("SELECT webhook_delete(%s,%s,%s)", (hid, t, u)).fetchone()[0] == "not_found"
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM webhook_deliveries").fetchone()[0] == 0


def test_old_finished_messages_are_cleaned_up(app, world, owner, admin):
    hid, _ = hook(app, world)
    backdate(owner, hid)
    finished_run(app, world, owner)
    enqueue(admin)
    did = claim(admin)[0][0]
    result(admin, did, True, 200)
    as_admin(owner)
    owner.execute("UPDATE webhook_deliveries SET created_at = now() - interval '15 days'")
    enqueue(admin)
    assert owner.execute("SELECT count(*) FROM webhook_deliveries").fetchone()[0] == 0


def test_privacy_and_who_may_call_what(app, world, owner, admin):
    hid, _ = hook(app, world)
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM webhook_endpoints").fetchone()[0] == 0
    for sql in ("SELECT webhook_enqueue()", "SELECT * FROM webhook_claim(5)", "SELECT webhook_result(%s,true,200,NULL)"):
        with pytest.raises(psycopg.Error):
            app.execute(sql, (hid,) if "%s" in sql else None)
        app.rollback()
    ctx(app, world)
    with pytest.raises(psycopg.Error):
        app.execute("INSERT INTO webhook_endpoints (id,tenant_id,user_id,name,url,secret_cipher,secret_version) VALUES (%s,%s,%s,'x',%s,'c',1)", (ulid(), world["a"]["tenant"], world["a"]["user"], URL))
    app.rollback()
    with pytest.raises(psycopg.Error):
        app.execute(CREATE, (ulid(), world["a"]["tenant"], None, "n", URL, "c", 1))
    app.rollback()


def test_a_person_reads_only_their_own_messages_without_payload_or_secret(app, world, owner, admin):
    hid, _ = hook(app, world, name="মূল")
    backdate(owner, hid)
    rid = finished_run(app, world, owner)
    enqueue(admin)
    ctx(app, world)
    row = app.execute("""select e.name, d.run_id, d.status, d.attempts from webhook_deliveries d join webhook_endpoints e on e.id = d.endpoint_id and e.tenant_id = d.tenant_id
                         where e.user_id = %s""", (world["a"]["user"],)).fetchall()
    assert [(r[0], r[1].strip(), r[2], r[3]) for r in row] == [("মূল", rid, "queued", 0)]
    with pytest.raises(psycopg.Error):
        app.execute("SELECT payload FROM webhook_deliveries")
    app.rollback()
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM webhook_deliveries").fetchone()[0] == 0
