"""Phase 26a: Computer Control rules — pairing, scope/allowlist, approvals, emergency stop, monitor."""
import hashlib
import json

import psycopg
import pytest
from psycopg.types.json import Jsonb

from conftest import as_admin, ulid
from test_accounting import ctx
from test_memory import second_user  # noqa: F401  (fixture)

ROOTS = ["C:\\Users\\me\\Documents", "/home/me/work"]


def h(s):
    return hashlib.sha256(s.encode()).hexdigest()


@pytest.fixture(autouse=True)
def clean(owner, world):
    yield
    as_admin(owner)
    owner.execute("ALTER TABLE pc_actions DISABLE TRIGGER pc_actions_guard_trg")
    owner.execute("ALTER TABLE pc_audit_chain DISABLE TRIGGER pc_audit_chain_append_only")           # test cleanup only: the table is append-only in real use
    owner.execute("DELETE FROM pc_audit_chain")
    owner.execute("ALTER TABLE pc_audit_chain ENABLE TRIGGER pc_audit_chain_append_only")
    for t in ("pc_metrics", "pc_actions", "pc_policies", "pc_pairings", "pc_devices"):
        owner.execute(f"DELETE FROM {t}")
    owner.execute("ALTER TABLE pc_actions ENABLE TRIGGER pc_actions_guard_trg")
    owner.execute("ALTER TABLE emergency_events DISABLE TRIGGER emergency_events_no_change")
    owner.execute("DELETE FROM emergency_events")
    owner.execute("ALTER TABLE emergency_events ENABLE TRIGGER emergency_events_no_change")


def pair(app, world, who="a", user=None, name="Laptop", platform="windows", code=None):
    code = code or ulid()
    u = ctx(app, world, who)
    r = app.execute("SELECT pc_pair_create(%s,%s,%s,%s,%s)", (world[who]["tenant"], user or u, name, platform, h(code))).fetchone()[0]
    return r, code


def device(app, world, who="a", user=None, **kw):
    r, code = pair(app, world, who, user, **kw)
    assert r == "ok"
    tok = ulid() + ulid()
    out = app.execute("SELECT pc_pair_redeem(%s,%s)", (h(code), h(tok))).fetchone()[0]
    assert out is not None
    return out["device_id"], tok


def policy(app, world, dev, who="a", user=None, roots=ROOTS, apps=("Notepad",), commands=("git",), hosts=("example.com",), modes=None):
    u = ctx(app, world, who)
    return app.execute("SELECT pc_policy_save(%s,%s,%s,%s,%s,%s,%s,%s)", (world[who]["tenant"], user or u, dev, list(roots), list(apps), list(commands), list(hosts), Jsonb(modes or {}))).fetchone()[0]


def req(app, world, dev, kind, params, who="a", user=None):
    u = ctx(app, world, who)
    return app.execute("SELECT pc_action_request(%s,%s,%s,%s,%s)", (world[who]["tenant"], user or u, dev, kind, Jsonb(params))).fetchone()[0]


def decide(app, world, aid, how="approve", who="a", user=None):
    u = ctx(app, world, who)
    return app.execute("SELECT pc_action_decide(%s,%s,%s,%s)", (world[who]["tenant"], user or u, aid, how)).fetchone()[0]


def nxt(app, world, dev, who="a"):
    ctx(app, world, who)
    return app.execute("SELECT pc_action_next(%s,%s)", (world[who]["tenant"], dev)).fetchone()[0]


def status(owner, aid):
    as_admin(owner)
    return owner.execute("SELECT status, reason FROM pc_actions WHERE id=%s", (aid,)).fetchone()


# ------------------------------------------------------------------ pairing
def test_pairing_roundtrip_and_one_time_code(app, owner, world):
    r, code = pair(app, world)
    assert r == "ok"
    tok = ulid() + ulid()
    out = app.execute("SELECT pc_pair_redeem(%s,%s)", (h(code), h(tok))).fetchone()[0]
    assert out["tenant_id"].strip() == world["a"]["tenant"]
    assert app.execute("SELECT pc_pair_redeem(%s,%s)", (h(code), h(ulid()))).fetchone()[0] is None      # second use
    assert app.execute("SELECT pc_pair_redeem(%s,%s)", (h("wrong"), h(tok))).fetchone()[0] is None
    assert app.execute("SELECT pc_pair_redeem(%s,%s)", ("not-a-hash", h(tok))).fetchone()[0] is None
    looked = app.execute("SELECT pc_agent_lookup(%s)", (h(tok),)).fetchone()[0]
    assert looked["device_id"] == out["device_id"] and looked["user_id"].strip() == world["a"]["user"]
    assert app.execute("SELECT pc_agent_lookup(%s)", (h("nope"),)).fetchone()[0] is None
    assert app.execute("SELECT pc_agent_lookup(%s)", (None,)).fetchone()[0] is None


def test_expired_pairing_code_does_not_work(app, owner, world):
    r, code = pair(app, world)
    as_admin(owner)
    owner.execute("UPDATE pc_pairings SET expires_at = now() - interval '1 second'")
    assert app.execute("SELECT pc_pair_redeem(%s,%s)", (h(code), h(ulid()))).fetchone()[0] is None


def test_pairing_input_validation_and_limits(app, owner, world):
    ctx(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    for name, plat, code in [("", "windows", h("x")), ("x" * 61, "windows", h("x")), ("a<b", "windows", h("x")), ("ok", "amiga", h("x")), ("ok", "windows", "short"), ("ok", None, h("x"))]:
        assert app.execute("SELECT pc_pair_create(%s,%s,%s,%s,%s)", (t, u, name, plat, code)).fetchone()[0] == "bad_input"
    with pytest.raises(psycopg.errors.InvalidParameterValue):
        app.execute("SELECT pc_pair_create(%s,NULL,'n','windows',%s)", (t, h("y")))
    app.rollback()
    for i in range(5):
        assert pair(app, world, name=f"p{i}")[0] == "ok"
    assert pair(app, world, name="sixth")[0] == "limit"           # 5 open pairings


def test_max_five_active_devices_per_user(app, owner, world):
    ids = [device(app, world, name=f"d{i}")[0] for i in range(5)]
    assert pair(app, world, name="six")[0] == "limit"
    ctx(app, world)
    assert app.execute("SELECT pc_device_revoke(%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], ids[0])).fetchone()[0] == "ok"
    assert pair(app, world, name="six")[0] == "ok"


def test_a_user_must_belong_to_the_workspace(app, owner, world):
    ctx(app, world)
    assert app.execute("SELECT pc_pair_create(%s,%s,'n','windows',%s)", (world["a"]["tenant"], world["b"]["user"], h("z"))).fetchone()[0] == "forbidden"


def test_pairing_is_blocked_for_other_tenants_context(app, owner, world):
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT pc_pair_create(%s,%s,'n','windows',%s)", (world["a"]["tenant"], world["a"]["user"], h("q")))


def test_revoked_device_loses_its_token_and_open_actions(app, owner, world):
    dev, tok = device(app, world)
    policy(app, world, dev)
    a = req(app, world, dev, "read_file", {"path": "/home/me/work/a.txt"})
    assert a["status"] == "pending"
    ctx(app, world)
    assert app.execute("SELECT pc_device_revoke(%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], dev)).fetchone()[0] == "ok"
    assert app.execute("SELECT pc_agent_lookup(%s)", (h(tok),)).fetchone()[0] is None
    assert status(owner, a["id"]) == ("stopped", "device_revoked")
    assert req(app, world, dev, "read_file", {"path": "/home/me/work/a.txt"})["reason"] == "not_found"
    assert nxt(app, world, dev) is None
    ctx(app, world)
    assert app.execute("SELECT pc_device_revoke(%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], dev)).fetchone()[0] == "not_found"


def test_only_the_device_owner_can_revoke_policy_or_request(app, owner, world, second_user):
    dev, _ = device(app, world)
    assert policy(app, world, dev, user=second_user) == "not_found"
    assert req(app, world, dev, "read_file", {"path": "/home/me/work/a"}, user=second_user)["reason"] == "not_found"
    ctx(app, world)
    assert app.execute("SELECT pc_device_revoke(%s,%s,%s)", (world["a"]["tenant"], second_user, dev)).fetchone()[0] == "not_found"


def test_secrets_are_not_readable_by_the_web_role(app, owner, world):
    device(app, world)
    ctx(app, world)
    assert app.execute("SELECT count(*) FROM pc_devices").fetchone()[0] == 1
    app.rollback()
    for sql in ("SELECT token_hash FROM pc_devices", "SELECT code_hash FROM pc_pairings", "SELECT * FROM pc_devices"):
        ctx(app, world)
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
        app.rollback()
    for sql in ("UPDATE pc_actions SET status='done'", "DELETE FROM pc_actions", "INSERT INTO pc_metrics (tenant_id,device_id,cpu,mem,disk) VALUES ('x','y',1,1,1)", "UPDATE pc_policies SET roots='{/}'", "UPDATE pc_devices SET name='x'"):
        ctx(app, world)
        with pytest.raises(psycopg.errors.InsufficientPrivilege):
            app.execute(sql)
        app.rollback()


def test_tenant_isolation_of_devices_and_actions(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    req(app, world, dev, "read_file", {"path": "/home/me/work/a"})
    ctx(app, world, "b")
    for t in ("pc_devices", "pc_policies", "pc_actions", "pc_pairings", "pc_metrics"):
        assert app.execute(f"SELECT count(*) FROM {t}").fetchone()[0] == 0, t
    assert req(app, world, dev, "read_file", {"path": "/home/me/work/a"}, who="b")["reason"] == "not_found"
    assert nxt(app, world, dev, who="b") is None


# ------------------------------------------------------------------ policy validation
@pytest.mark.parametrize("kw", [
    dict(roots=["relative/path"]), dict(roots=["/"]), dict(roots=["C:\\"]), dict(roots=["C:/"]), dict(roots=["///"]), dict(roots=["/home/../etc"]),
    dict(roots=["/home/me\x01"]), dict(roots=["/x" + "a" * 260]), dict(roots=["/r"] * 21),
    dict(apps=["a/b"]), dict(apps=["a\\b"]), dict(apps=[""]), dict(apps=["x" * 101]), dict(apps=["a<b"]),
    dict(commands=["rm -rf"]), dict(commands=["a/b"]), dict(commands=["x" * 65]), dict(commands=[""]),
    dict(hosts=["EXAMPLE.com"]), dict(hosts=["-bad.com"]), dict(hosts=["a b.com"]), dict(hosts=["a.com/x"]),
    dict(modes={"run_command": "allow"}), dict(modes={"delete_file": "allow"}), dict(modes={"input_control": "allow"}), dict(modes={"power": "allow"}),
    dict(modes={"hack": "ask"}), dict(modes={"read_file": "maybe"}), dict(modes={"read_file": None}),
])
def test_policy_rejects_unsafe_or_malformed_input(app, owner, world, kw):
    dev, _ = device(app, world)
    assert policy(app, world, dev, **kw) == "bad_input"


def test_policy_accepts_sane_input_and_can_be_replaced(app, owner, world):
    dev, _ = device(app, world)
    assert policy(app, world, dev, roots=["D:/Projects/", "/srv/data"], modes={"read_file": "allow", "write_file": "ask", "power": "deny", "run_command": "ask", "delete_file": "deny"}) == "ok"
    assert policy(app, world, dev, roots=["/only"], apps=(), commands=(), hosts=()) == "ok"
    as_admin(owner)
    assert owner.execute("SELECT roots, apps FROM pc_policies WHERE device_id=%s", (dev,)).fetchone() == (["/only"], [])


# ------------------------------------------------------------------ scope / allowlist
@pytest.mark.parametrize("path,expected", [
    ("C:\\Users\\me\\Documents\\a.txt", "pending"), ("c:/users/ME/documents/sub/b.txt", "pending"), ("C:\\Users\\me\\Documents", "pending"),
    ("/home/me/work/x", "pending"), ("/home/me/work", "pending"), ("/home/me/work/", "pending"),
    ("/home/me/worker/x", "outside_scope"), ("/home/me/workshop", "outside_scope"), ("/home/me", "outside_scope"), ("/etc/passwd", "outside_scope"),
    ("C:\\Users\\me\\Documentsx\\a", "outside_scope"), ("D:\\Users\\me\\Documents\\a", "outside_scope"),
    ("/home/me/work/../../etc/passwd", "bad_params"), ("/home/me/work/..", "bad_params"), ("..\\..\\x", "bad_params"), ("relative.txt", "bad_params"),
    ("/home/me/work/a\nb", "bad_params"), ("/home/me/work/a\tb", "bad_params"), ("/home/me/work/" + "a" * 600, "bad_params"),
])
def test_file_actions_stay_inside_allowed_folders(app, owner, world, path, expected):
    dev, _ = device(app, world)
    assert policy(app, world, dev) == "ok"
    r = req(app, world, dev, "read_file", {"path": path})
    assert (r["status"] if expected == "pending" else r["reason"]) == expected
    if expected != "pending":
        assert r["status"] == "blocked"


def test_path_must_be_a_string(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    for p in ({}, {"path": 5}, {"path": ["/home/me/work"]}, {"path": None}):
        assert req(app, world, dev, "write_file", p)["reason"] == "bad_params"


def test_apps_commands_hosts_allowlists(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev, apps=("Notepad", "Google Chrome"), commands=("git", "npm"), hosts=("example.com",))
    ok = lambda k, p: req(app, world, dev, k, p)["status"] == "pending"
    why = lambda k, p: req(app, world, dev, k, p)["reason"]
    assert ok("open_app", {"app": "notepad"}) and ok("open_app", {"app": "GOOGLE CHROME"})
    assert why("open_app", {"app": "cmd"}) == "outside_scope" and why("open_app", {"app": "Notepad.exe"}) == "outside_scope" and why("open_app", {}) == "bad_params"
    assert ok("run_command", {"command": "git", "args": ["status"]}) and ok("run_command", {"command": "GIT"})
    assert why("run_command", {"command": "powershell"}) == "outside_scope" and why("run_command", {"command": "git; rm -rf /"}) == "outside_scope"
    assert why("run_command", {"command": "git", "args": "status"}) == "bad_params" and why("run_command", {"command": "git", "args": ["a"] * 21}) == "bad_params"
    assert why("run_command", {"command": "git", "args": [5]}) == "bad_params" and why("run_command", {"command": "git", "args": ["x" * 201]}) == "bad_params"
    assert why("run_command", {"command": "git", "args": ["a\nb"]}) == "bad_params" and why("run_command", {"command": 5}) == "bad_params"
    assert ok("browser_navigate", {"url": "https://example.com/a?b=1"}) and ok("browser_navigate", {"url": "https://www.example.com"}) and ok("browser_navigate", {"url": "https://sub.example.com:8443/x"})
    assert why("browser_navigate", {"url": "http://example.com"}) == "bad_params" and why("browser_navigate", {"url": "https://evilexample.com"}) == "outside_scope"
    assert why("browser_navigate", {"url": "https://example.com.evil.net/"}) == "outside_scope" and why("browser_navigate", {"url": "https://example.com@evil.net/"}) == "bad_params"
    assert why("browser_navigate", {"url": "javascript:alert(1)"}) == "bad_params" and why("browser_navigate", {"url": "https://exa mple.com"}) == "bad_params"
    assert why("browser_navigate", {"url": "https://127.0.0.1/"}) == "outside_scope" and why("browser_navigate", {"url": 5}) == "bad_params"


def test_empty_policy_allows_nothing(app, owner, world):
    dev, _ = device(app, world)               # fresh device: empty lists
    assert req(app, world, dev, "read_file", {"path": "/home/me/work/a"})["reason"] == "outside_scope"
    assert req(app, world, dev, "open_app", {"app": "Notepad"})["reason"] == "outside_scope"
    assert req(app, world, dev, "run_command", {"command": "git"})["reason"] == "outside_scope"
    assert req(app, world, dev, "browser_navigate", {"url": "https://example.com"})["reason"] == "outside_scope"


def test_unscoped_kinds_validate_their_parameters(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    r = lambda k, p: req(app, world, dev, k, p)
    assert r("input_control", {"action": "click", "x": 5, "y": 5})["status"] == "pending" and r("input_control", {"action": "format"})["reason"] == "bad_params"
    assert r("system_setting", {"setting": "volume", "value": 30})["status"] == "pending" and r("system_setting", {"setting": "volume"})["reason"] == "bad_params"
    assert r("system_setting", {"setting": "firewall", "value": 0})["reason"] == "bad_params"
    assert r("power", {"action": "restart"})["status"] == "pending" and r("power", {"action": "hibernate-and-wipe"})["reason"] == "bad_params"


def test_unknown_kind_or_bad_envelope_is_not_even_recorded(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    for k, p in (("format_disk", {}), ("read_file", None)):
        u = ctx(app, world)
        out = app.execute("SELECT pc_action_request(%s,%s,%s,%s,%s)", (world["a"]["tenant"], u, dev, k, None if p is None else Jsonb(p))).fetchone()[0]
        assert out["status"] == "rejected_input"
    out = app.execute("SELECT pc_action_request(%s,%s,%s,'read_file',%s)", (world["a"]["tenant"], world["a"]["user"], dev, Jsonb([1]))).fetchone()[0]
    assert out["status"] == "rejected_input"
    out = app.execute("SELECT pc_action_request(%s,%s,%s,'read_file',%s)", (world["a"]["tenant"], world["a"]["user"], dev, Jsonb({"path": "/home/me/work/" + "a" * 4100}))).fetchone()[0]
    assert out["status"] == "rejected_input"
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM pc_actions").fetchone()[0] == 0


# ------------------------------------------------------------------ modes and approval
def test_modes_decide_blocked_pending_or_auto_approved(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev, modes={"read_file": "allow", "write_file": "deny", "open_app": "allow", "list_dir": "ask", "browser_navigate": "allow"})
    assert req(app, world, dev, "read_file", {"path": "/home/me/work/a"})["status"] == "approved"           # low + allow
    assert req(app, world, dev, "open_app", {"app": "Notepad"})["status"] == "approved"                      # medium + allow
    w = req(app, world, dev, "write_file", {"path": "/home/me/work/a"})
    assert (w["status"], w["reason"]) == ("blocked", "denied_by_policy")
    assert req(app, world, dev, "list_dir", {"path": "/home/me/work"})["status"] == "pending"
    assert req(app, world, dev, "run_command", {"command": "git"})["status"] == "pending"                    # default ask
    assert req(app, world, dev, "read_file", {"path": "/etc/passwd"})["status"] == "blocked"                 # allow does not widen scope


def test_high_and_critical_risk_always_need_a_human_even_if_the_row_says_allow(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    as_admin(owner)
    owner.execute("UPDATE pc_policies SET modes = %s WHERE device_id = %s", (Jsonb({"run_command": "allow", "power": "allow", "delete_file": "allow", "input_control": "allow"}), dev))
    assert req(app, world, dev, "run_command", {"command": "git"})["status"] == "pending"
    assert req(app, world, dev, "power", {"action": "shutdown"})["status"] == "pending"
    assert req(app, world, dev, "delete_file", {"path": "/home/me/work/a"})["status"] == "pending"
    assert req(app, world, dev, "input_control", {"action": "key", "keys": "ctrl+s"})["status"] == "pending"
    assert req(app, world, dev, "power", {"action": "shutdown"})["risk"] == "critical" and req(app, world, dev, "run_command", {"command": "git"})["risk"] == "high"


def test_decide_flow_and_agent_pull(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    a = req(app, world, dev, "read_file", {"path": "/home/me/work/a.txt"})
    assert nxt(app, world, dev) is None                                    # pending is not handed out
    assert decide(app, world, a["id"], "approve") == "ok"
    assert status(owner, a["id"]) == ("approved", None)
    got = nxt(app, world, dev)
    assert got["id"] == a["id"] and got["kind"] == "read_file" and got["params"] == {"path": "/home/me/work/a.txt"}
    assert status(owner, a["id"])[0] == "running"
    assert nxt(app, world, dev) is None                                    # not handed out twice
    ctx(app, world)
    assert app.execute("SELECT pc_action_report(%s,%s,%s,true,'ok')", (world["a"]["tenant"], dev, a["id"])).fetchone()[0] == "ok"
    assert status(owner, a["id"])[0] == "done"
    assert app.execute("SELECT pc_action_report(%s,%s,%s,true,'again')", (world["a"]["tenant"], dev, a["id"])).fetchone()[0] == "not_running"
    assert decide(app, world, a["id"], "approve") == "not_pending"


def test_reject_and_input_checks_on_decide(app, owner, world, second_user):
    dev, _ = device(app, world)
    policy(app, world, dev)
    a = req(app, world, dev, "write_file", {"path": "/home/me/work/a"})
    assert decide(app, world, a["id"], "maybe") == "bad_input"
    assert decide(app, world, a["id"], "approve", user=second_user) == "not_found"        # not the device owner
    assert decide(app, world, ulid(), "approve") == "not_found"
    assert decide(app, world, a["id"], "reject") == "ok"
    assert status(owner, a["id"])[0] == "rejected"
    assert nxt(app, world, dev) is None
    assert decide(app, world, a["id"], "approve") == "not_pending"
    ctx(app, world, "b")
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT pc_action_decide(%s,%s,%s,'approve')", (world["a"]["tenant"], world["a"]["user"], a["id"]))


def test_expired_actions_cannot_be_approved_or_run(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev, modes={"read_file": "allow"})
    pend = req(app, world, dev, "write_file", {"path": "/home/me/work/a"})
    auto = req(app, world, dev, "read_file", {"path": "/home/me/work/a"})
    as_admin(owner)
    owner.execute("UPDATE pc_actions SET expires_at = now() - interval '1 second'")
    assert decide(app, world, pend["id"], "approve") == "expired"
    assert status(owner, pend["id"])[0] == "expired"
    assert nxt(app, world, dev) is None
    assert status(owner, auto["id"])[0] == "expired"


def test_approval_gives_a_fresh_ten_minutes(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    a = req(app, world, dev, "read_file", {"path": "/home/me/work/a"})
    as_admin(owner)
    owner.execute("UPDATE pc_actions SET expires_at = now() + interval '5 seconds' WHERE id=%s", (a["id"],))
    assert decide(app, world, a["id"], "approve") == "ok"
    as_admin(owner)
    assert owner.execute("SELECT expires_at > now() + interval '9 minutes' FROM pc_actions WHERE id=%s", (a["id"],)).fetchone()[0]


def test_report_only_by_the_running_devices_own_action(app, owner, world):
    d1, _ = device(app, world, name="one")
    d2, _ = device(app, world, name="two")
    policy(app, world, d1)
    policy(app, world, d2)
    a = req(app, world, d1, "read_file", {"path": "/home/me/work/a"})
    decide(app, world, a["id"])
    nxt(app, world, d1)
    ctx(app, world)
    assert app.execute("SELECT pc_action_report(%s,%s,%s,true,'x')", (world["a"]["tenant"], d2, a["id"])).fetchone()[0] == "not_running"
    assert app.execute("SELECT pc_action_report(%s,%s,%s,NULL,'x')", (world["a"]["tenant"], d1, a["id"])).fetchone()[0] == "bad_input"
    assert app.execute("SELECT pc_action_report(%s,%s,%s,false,%s)", (world["a"]["tenant"], d1, a["id"], "e" * 5000)).fetchone()[0] == "ok"
    as_admin(owner)
    assert owner.execute("SELECT status, length(result) FROM pc_actions WHERE id=%s", (a["id"],)).fetchone() == ("failed", 2000)


def test_agent_of_one_device_cannot_pull_another_devices_actions(app, owner, world):
    d1, _ = device(app, world, name="one")
    d2, _ = device(app, world, name="two")
    policy(app, world, d1)
    a = req(app, world, d1, "read_file", {"path": "/home/me/work/a"})
    decide(app, world, a["id"])
    assert nxt(app, world, d2) is None
    assert nxt(app, world, d1)["id"] == a["id"]


# ------------------------------------------------------------------ emergency stop
def stop(owner, world, who="a", action="stop"):
    as_admin(owner)
    owner.execute("INSERT INTO emergency_events (tenant_id, action, by_user) VALUES (%s,%s,%s)", (world[who]["tenant"], action, world[who]["user"]))


def test_emergency_stop_blocks_new_requests_and_stops_open_ones(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev, modes={"read_file": "allow"})
    pend = req(app, world, dev, "write_file", {"path": "/home/me/work/a"})
    appr = req(app, world, dev, "read_file", {"path": "/home/me/work/a"})
    run = req(app, world, dev, "read_file", {"path": "/home/me/work/b"})
    assert nxt(app, world, dev)["id"] == appr["id"]                        # appr is now running
    stop(owner, world)
    r = req(app, world, dev, "read_file", {"path": "/home/me/work/c"})
    assert (r["status"], r["reason"]) == ("blocked", "emergency_stop")
    assert nxt(app, world, dev) is None
    for a in (pend, appr, run):
        assert status(owner, a["id"]) == ("stopped", "emergency_stop")
    ctx(app, world)
    assert app.execute("SELECT pc_action_report(%s,%s,%s,true,'late')", (world["a"]["tenant"], dev, appr["id"])).fetchone()[0] == "not_running"


def test_decide_during_emergency_stops_instead_of_approving(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    a = req(app, world, dev, "write_file", {"path": "/home/me/work/a"})
    stop(owner, world)
    assert decide(app, world, a["id"], "approve") == "emergency_stop"
    assert status(owner, a["id"]) == ("stopped", "emergency_stop")


def test_resume_lifts_the_block_and_other_tenants_are_unaffected(app, owner, world):
    devb, _ = device(app, world, who="b")
    policy(app, world, devb, who="b")
    dev, _ = device(app, world)
    policy(app, world, dev)
    stop(owner, world)
    assert req(app, world, devb, "read_file", {"path": "/home/me/work/a"}, who="b")["status"] == "pending"
    assert req(app, world, dev, "read_file", {"path": "/home/me/work/a"})["status"] == "blocked"
    stop(owner, world, action="resume")
    assert req(app, world, dev, "read_file", {"path": "/home/me/work/a"})["status"] == "pending"


def test_missing_policy_row_blocks(app, owner, world):
    dev, _ = device(app, world)
    as_admin(owner)
    owner.execute("DELETE FROM pc_policies WHERE device_id=%s", (dev,))
    r = req(app, world, dev, "read_file", {"path": "/home/me/work/a"})
    assert (r["status"], r["reason"]) == ("blocked", "no_policy")


# ------------------------------------------------------------------ audit trail integrity
def test_actions_are_an_immutable_audit_trail(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    a = req(app, world, dev, "read_file", {"path": "/home/me/work/a"})
    as_admin(owner)
    for sql in ("UPDATE pc_actions SET kind='power' WHERE id=%s", "UPDATE pc_actions SET risk='critical' WHERE id=%s", "UPDATE pc_actions SET params='{}' WHERE id=%s",
                "UPDATE pc_actions SET device_id=device_id, requested_by=%s WHERE id=%s", "DELETE FROM pc_actions WHERE id=%s"):
        with pytest.raises(psycopg.errors.InvalidParameterValue):
            owner.execute(sql, (world["b"]["user"], a["id"]) if "requested_by" in sql else (a["id"],))
        owner.rollback()
        as_admin(owner)
    for bad in ("running", "done", "failed"):                         # pending cannot skip approval
        with pytest.raises(psycopg.errors.InvalidParameterValue):
            owner.execute("UPDATE pc_actions SET status=%s WHERE id=%s", (bad, a["id"]))
        owner.rollback()
        as_admin(owner)
    owner.execute("UPDATE pc_actions SET status='rejected' WHERE id=%s", (a["id"],))
    for back in ("pending", "approved", "running"):                   # finished stays finished
        with pytest.raises(psycopg.errors.InvalidParameterValue):
            owner.execute("UPDATE pc_actions SET status=%s WHERE id=%s", (back, a["id"]))
        owner.rollback()
        as_admin(owner)


# ------------------------------------------------------------------ system monitor
def test_metrics_validation_rate_limit_and_isolation(app, owner, world):
    dev, _ = device(app, world)
    ctx(app, world)
    t = world["a"]["tenant"]
    add = lambda *a: app.execute("SELECT pc_metrics_add(%s,%s,%s::numeric,%s::numeric,%s::numeric,%s::numeric,%s::numeric,%s::numeric,%s::boolean)", (t, dev, *a)).fetchone()[0]
    assert add(10, 20, 30, None, None, None, None) == "ok"
    assert add(11, 21, 31, None, None, None, None) == "too_fast"
    for bad in [(101, 1, 1, None, None, None, None), (-1, 1, 1, None, None, None, None), (1, 1, None, None, None, None, None), (1, 101, 1, None, None, None, None), (1, 1, 101, None, None, None, None),
                (1, 1, 1, 101, None, None, None), (1, 1, 1, None, 101, None, None), (1, 1, 1, None, None, 151, None), (1, 1, 1, None, None, -51, None)]:
        assert add(*bad) == "bad_input"
    as_admin(owner)
    owner.execute("UPDATE pc_metrics SET created_at = now() - interval '10 seconds'")
    assert add(50, 50, 50, 60, 80, 55.5, False) == "ok"
    as_admin(owner)
    assert owner.execute("SELECT count(*) FROM pc_metrics").fetchone()[0] == 2
    assert req(app, world, dev, "read_file", {"path": "/x"}, who="b")["reason"] == "not_found"
    ctx(app, world, "b")
    assert app.execute("SELECT count(*) FROM pc_metrics").fetchone()[0] == 0
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT pc_metrics_add(%s,%s,1::numeric,1::numeric,1::numeric,NULL,NULL,NULL,NULL)", (world["a"]["tenant"], dev))
    app.rollback()
    ctx(app, world, "b")
    assert app.execute("SELECT pc_metrics_add(%s,%s,1::numeric,1::numeric,1::numeric,NULL,NULL,NULL,NULL)", (world["b"]["tenant"], dev)).fetchone()[0] == "not_found"


def test_metrics_purge_is_admin_only_and_keeps_recent_rows(app, owner, admin, world):
    dev, _ = device(app, world)
    ctx(app, world)
    app.execute("SELECT pc_metrics_add(%s,%s,1::numeric,1::numeric,1::numeric,NULL,NULL,NULL,NULL)", (world["a"]["tenant"], dev))
    with pytest.raises(psycopg.errors.InsufficientPrivilege):
        app.execute("SELECT pc_metrics_purge(7)")
    app.rollback()
    as_admin(owner)
    owner.execute("INSERT INTO pc_metrics (tenant_id, device_id, cpu, mem, disk, created_at) VALUES (%s,%s,1,1,1, now() - interval '8 days')", (world["a"]["tenant"], dev))
    as_admin(admin)
    assert admin.execute("SELECT pc_metrics_purge(7)").fetchone()[0] == 1
    assert admin.execute("SELECT count(*) FROM pc_metrics").fetchone()[0] == 1


def test_nul_bytes_cannot_enter_params_at_all(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    with pytest.raises(psycopg.errors.UntranslatableCharacter):
        req(app, world, dev, "read_file", {"path": "/home/me/work/a\x00b"})


def test_report_for_an_action_that_was_never_pulled_is_refused(app, owner, world):
    dev, _ = device(app, world)
    policy(app, world, dev)
    a = req(app, world, dev, "read_file", {"path": "/home/me/work/a"})
    decide(app, world, a["id"])
    ctx(app, world)
    assert app.execute("SELECT pc_action_report(%s,%s,%s,true,'sneaky')", (world["a"]["tenant"], dev, a["id"])).fetchone()[0] == "not_running"
    assert status(owner, a["id"])[0] == "approved"


def test_revoked_device_cannot_send_metrics(app, owner, world):
    dev, _ = device(app, world)
    ctx(app, world)
    app.execute("SELECT pc_device_revoke(%s,%s,%s)", (world["a"]["tenant"], world["a"]["user"], dev))
    assert app.execute("SELECT pc_metrics_add(%s,%s,1::numeric,1::numeric,1::numeric,NULL,NULL,NULL,NULL)", (world["a"]["tenant"], dev)).fetchone()[0] == "not_found"


def test_the_web_roles_read_queries_work_with_the_granted_columns(app, owner, world):
    """The exact SELECTs PgPcRepo issues (devices, policy, actions join, latest metric) must run as the web role."""
    dev, _ = device(app, world)
    policy(app, world, dev, modes={"read_file": "allow"})
    req(app, world, dev, "read_file", {"path": "/home/me/work/a"})
    ctx(app, world)
    t, u = world["a"]["tenant"], world["a"]["user"]
    assert len(app.execute("SELECT id, name, platform, last_seen_at, user_id FROM pc_devices WHERE tenant_id=%s AND user_id=%s AND revoked_at IS NULL ORDER BY paired_at", (t, u)).fetchall()) == 1
    row = app.execute("SELECT to_jsonb(roots), to_jsonb(apps), to_jsonb(commands), to_jsonb(hosts), modes FROM pc_policies WHERE tenant_id=%s AND device_id=%s", (t, dev)).fetchone()
    assert row[0] == ROOTS and row[4] == {"read_file": "allow"}
    acts = app.execute("SELECT a.id, a.device_id, d.name, a.kind, a.risk, a.params, a.status, a.reason, a.result, a.created_at FROM pc_actions a JOIN pc_devices d ON d.id = a.device_id WHERE a.tenant_id=%s AND d.user_id=%s ORDER BY a.created_at DESC LIMIT 30", (t, u)).fetchall()
    assert len(acts) == 1 and acts[0][6] == "approved"
    assert app.execute("SELECT cpu, mem, disk, gpu, battery, temp_c, charging, created_at FROM pc_metrics WHERE tenant_id=%s AND device_id=%s ORDER BY created_at DESC LIMIT 1", (t, dev)).fetchone() is None
