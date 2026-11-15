"""Phase 10: audit log sync.  The fake server applies the same rules as the SQL (recomputes every hash, checks every link, append-only)."""
import json
import os

import pytest

from jarvis_agent import auditsync, runner, taskengine
from jarvis_agent.client import Client
from jarvis_agent.config import Config


@pytest.fixture
def audit(tmp_path):
    return taskengine.Audit(tmp_path / "audit.jsonl", clock=lambda: 1_700_000_000)


def fill(a, n, prefix="e"):
    for i in range(n):
        a.record("t1", f"s{i}", f"{prefix}{i}", f"detail {i} ধাপ")


def cli(server):
    return Client(server.url, "x" * 64, allow_http=True)


@pytest.fixture(autouse=True)
def _ok_auth(server):
    server.token = "x" * 64


def test_first_sync_uploads_everything_and_the_server_copy_matches(server, audit):
    fill(audit, 5)
    r = auditsync.sync(cli(server), audit)
    assert r["status"] == "synced" and r["uploaded"] == 5 and r["server_rows"] == 5 and r["local_rows"] == 5
    assert [x["h"] for x in server.audit_rows] == [x["h"] for x in audit.rows()]


def test_only_new_rows_go_next_time_and_nothing_when_already_in_sync(server, audit):
    fill(audit, 3)
    auditsync.sync(cli(server), audit)
    fill(audit, 2, "n")
    r = auditsync.sync(cli(server), audit)
    assert r["uploaded"] == 2 and len(server.audit_rows) == 5
    n_calls = len(server.audit_sync_calls)
    r = auditsync.sync(cli(server), audit)
    assert r["status"] == "synced" and r["uploaded"] == 0 and len(server.audit_sync_calls) == n_calls


def test_big_logs_go_in_batches_of_200(server, audit):
    fill(audit, 450)
    r = auditsync.sync(cli(server), audit)
    assert r["status"] == "synced" and r["uploaded"] == 450
    assert [len(c["rows"]) for c in server.audit_sync_calls] == [200, 200, 50]


def test_a_rewritten_local_file_is_reported_as_diverged_and_nothing_new_is_uploaded(server, audit):
    fill(audit, 4)
    auditsync.sync(cli(server), audit)
    lines = open(audit.path, encoding="utf-8").read().splitlines()
    # an attacker rewrites the local file with a DIFFERENT but internally consistent history of the same length
    other = taskengine.Audit(audit.path.parent / "other.jsonl", clock=lambda: 1_700_000_000)
    for i in range(4):
        other.record("t1", f"s{i}", "act", "harmless")
    os.replace(other.path, audit.path)
    assert audit.verify_chain() == (True, 4)                                   # the local chain alone looks fine ...
    before = list(server.audit_rows)
    r = auditsync.sync(cli(server), audit)
    assert r["status"] == "diverged" and server.audit_rows == before            # ... the server copy exposes it
    assert lines != open(audit.path, encoding="utf-8").read().splitlines()


def test_a_truncated_local_file_is_diverged(server, audit):
    fill(audit, 4)
    auditsync.sync(cli(server), audit)
    keep = open(audit.path, encoding="utf-8").read().splitlines()[:2]
    open(audit.path, "w", encoding="utf-8").write("\n".join(keep) + "\n")
    assert audit.verify_chain() == (True, 2)
    assert auditsync.sync(cli(server), audit)["status"] == "diverged"


def test_a_tampered_local_chain_is_never_uploaded(server, audit):
    fill(audit, 3)
    lines = open(audit.path, encoding="utf-8").read().splitlines()
    open(audit.path, "w", encoding="utf-8").write("\n".join([lines[0].replace("e0", "zz")] + lines[1:]) + "\n")
    r = auditsync.sync(cli(server), audit)
    assert r["status"] == "local_tampered" and server.audit_rows == [] and server.audit_sync_calls == []


def test_a_garbled_line_is_tampering_not_a_crash(server, audit):
    fill(audit, 2)
    open(audit.path, "a", encoding="utf-8").write("{not json\n")
    assert auditsync.sync(cli(server), audit)["status"] == "local_tampered"


def test_the_server_refuses_a_batch_that_does_not_continue_its_chain(server, audit):
    fill(audit, 3)
    auditsync.sync(cli(server), audit)
    rows = audit.rows()
    c = cli(server)
    from jarvis_agent.client import ServerError
    forged = dict(rows[0], prev="f" * 64)
    with pytest.raises(ServerError) as e:
        c.audit_sync(3, [forged])
    assert e.value.status == 409 and e.value.body["status"] == "chain_broken" and e.value.body["seq"] == 3
    with pytest.raises(ServerError) as e2:
        c.audit_sync(1, rows[1:2])
    assert e2.value.body["status"] == "seq_mismatch"


def test_old_server_offline_server_and_revoked_device_are_reported_plainly(server, audit):
    fill(audit, 2)
    server.audit_mode = "old"
    assert auditsync.sync(cli(server), audit)["status"] == "server_too_old"
    server.audit_mode = "down"
    assert auditsync.sync(cli(server), audit)["status"] == "error"
    dead = Client("http://127.0.0.1:9", "x" * 64, allow_http=True)
    assert auditsync.sync(dead, audit)["status"] == "offline"
    server.audit_mode = "ok"
    server.revoked = True
    from jarvis_agent.client import AuthError
    with pytest.raises(AuthError):
        auditsync.sync(cli(server), audit)


def test_text_that_is_awkward_for_json_survives_the_round_trip(server, audit):
    for d in ['quote " and \\ back', "line\nbreak\ttab", "emoji 😀 ü 日本語", "ctrl \x01 char", "x" * 300]:
        audit.record("t", "s", "act", d)
    assert auditsync.sync(cli(server), audit)["status"] == "synced"


def test_periodic_syncs_only_when_the_file_changed_and_not_too_often(server, audit):
    clock = [0.0]
    p = auditsync.Periodic(every=300, audit=audit, now=lambda: clock[0])
    c = cli(server)
    assert p.maybe(c) is None                                                  # no file yet
    fill(audit, 2)
    assert p.maybe(c)["status"] == "synced"
    assert p.maybe(c) is None                                                  # nothing new
    fill(audit, 1, "n")
    clock[0] = 100
    assert p.maybe(c) is None                                                  # new rows, but too soon after the last upload
    clock[0] = 301
    assert p.maybe(c)["uploaded"] == 1
    assert p.maybe(object()) is None                                           # a client without audit support (old test doubles) is skipped


def test_diverged_is_logged_by_the_run_loop(server, audit, monkeypatch, tmp_path):
    monkeypatch.setenv("JARVIS_AGENT_HOME", str(tmp_path / "h"))
    a = taskengine.Audit()
    fill(a, 3)
    auditsync.sync(cli(server), a)
    lines = open(a.path, encoding="utf-8").read().splitlines()
    other = taskengine.Audit(tmp_path / "o.jsonl", clock=lambda: 1_700_000_000)
    for i in range(3):
        other.record("t1", f"s{i}", "act", "harmless")
    os.replace(other.path, a.path)
    logs = []
    server.queue.clear()
    runner.run(Config(server=server.url, token=server.token, device_id="d", mode="agent"), cli(server), once=True, log=logs.append, sleep=lambda s: None)
    assert any("Audit" in x and "diverged" in x for x in logs)


# ---------------------------------------------------------------- the local window
from test_gui import TOKEN, Win  # noqa: E402


@pytest.fixture
def sw(server, tmp_path, monkeypatch):
    monkeypatch.setenv("JARVIS_AGENT_HOME", str(tmp_path / "home"))
    w = Win()
    w.state.cfg = Config(server=server.url, token=server.token, device_id="d1")
    yield w
    w.close()


def test_window_button_syncs_and_the_task_list_reports_the_state(server, sw):
    a = taskengine.Audit()
    fill(a, 3)
    st, js = sw.j("POST", "/api/audit/sync", {})
    assert st == 200 and js["status"] == "synced" and js["uploaded"] == 3
    st, tasks = sw.j("GET", "/api/tasks")
    assert tasks["audit_ok"] is True and tasks["audit_sync"]["status"] == "synced" and tasks["audit_sync"]["server_rows"] == 3


def test_window_reports_a_diverged_copy_and_guards_the_route(server, sw, tmp_path):
    a = taskengine.Audit()
    fill(a, 3)
    sw.j("POST", "/api/audit/sync", {})
    other = taskengine.Audit(tmp_path / "o.jsonl", clock=lambda: 1_700_000_000)
    for i in range(3):
        other.record("t1", f"s{i}", "act", "x")
    os.replace(other.path, a.path)
    assert sw.j("POST", "/api/audit/sync", {})[1]["status"] == "diverged"
    assert sw.j("POST", "/api/audit/sync", {}, token="wrong")[0] == 401
    assert sw.j("POST", "/api/audit/sync", {}, host="evil.example")[0] == 403
    sw.state.cfg = Config()
    assert sw.j("POST", "/api/audit/sync", {})[0] == 409                      # not paired
    sw.state.cfg = Config(server=server.url, token=server.token, device_id="d1")
    server.revoked = True
    assert sw.j("POST", "/api/audit/sync", {})[0] == 401                      # device removed
