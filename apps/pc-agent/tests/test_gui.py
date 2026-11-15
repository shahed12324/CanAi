import http.client
import json
import threading
import time

import pytest

from jarvis_agent import config as cfgmod, gui, history, policy
from jarvis_agent.config import Config

TOKEN = "t" * 20


class Win:
    def __init__(self, **kw):
        self.state = gui.State(allow_http=True, **kw)
        holder = [0]
        self.httpd = gui.ThreadingHTTPServer(("127.0.0.1", 0), gui.make_handler(self.state, TOKEN, holder))
        holder[0] = self.httpd.server_address[1]
        self.port = holder[0]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def call(self, method, path, body=None, headers=None, host=None, token=TOKEN, raw=None):
        c = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        h = {"Host": host or f"127.0.0.1:{self.port}"}
        if token is not None:
            h["X-Agent-Token"] = token
        h.update(headers or {})
        data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
        c.request(method, path, body=data, headers=h)
        r = c.getresponse()
        txt = r.read()
        out = (r.status, dict(r.getheaders()), txt)
        c.close()
        return out

    def j(self, *a, **k):
        s, h, t = self.call(*a, **k)
        return s, json.loads(t or b"{}")

    def close(self):
        self.state.halt()
        self.httpd.shutdown()
        self.httpd.server_close()


@pytest.fixture
def win():
    w = Win()
    yield w
    w.close()


def wait(cond, secs=5):
    end = time.time() + secs
    while time.time() < end:
        if cond():
            return True
        time.sleep(0.05)
    return False


# ---- guards
def test_page_served_with_strict_headers(win):
    s, h, t = win.call("GET", "/", token=None)
    assert s == 200 and b"CAN AI" in t
    assert "default-src 'none'" in h["Content-Security-Policy"] and "connect-src 'self'" in h["Content-Security-Policy"]
    assert h["X-Frame-Options"] == "DENY" and h["Cache-Control"] == "no-store"
    assert TOKEN.encode() not in t                      # the token never appears in the page itself


@pytest.mark.parametrize("host", ["evil.example.com", "127.0.0.1:1", "localhost", ""])
def test_wrong_host_refused_everywhere(win, host):
    assert win.call("GET", "/", host=host or "x")[0] == 403
    assert win.call("GET", "/api/state", host=host or "x")[0] == 403
    assert win.call("POST", "/api/policy", {"action": "add-host", "value": "a.com"}, host=host or "x")[0] == 403
    assert win.state.cfg.hosts == []


def test_localhost_name_is_accepted(win):
    assert win.call("GET", "/api/state", host=f"localhost:{win.port}")[0] == 200


@pytest.mark.parametrize("tok", [None, "", "wrong", TOKEN[:-1], TOKEN + "x"])
def test_token_required(win, tok):
    assert win.call("GET", "/api/state", token=tok)[0] == 401
    assert win.call("POST", "/api/policy", {"action": "add-host", "value": "a.com"}, token=tok)[0] == 401
    assert win.call("POST", "/api/run", {"on": True}, token=tok)[0] == 401
    assert win.call("POST", "/api/pair", {"server": "x", "code": "y"}, token=tok)[0] == 401
    assert win.state.cfg.hosts == []


def test_foreign_origin_refused_on_write(win):
    s, _ = win.j("POST", "/api/policy", {"action": "add-host", "value": "a.com"}, headers={"Origin": "https://evil.example.com"})
    assert s == 403 and win.state.cfg.hosts == []
    s, _ = win.j("POST", "/api/policy", {"action": "add-host", "value": "a.com"}, headers={"Origin": f"http://127.0.0.1:{win.port}"})
    assert s == 200 and win.state.cfg.hosts == ["a.com"]


def test_bad_bodies(win):
    assert win.call("POST", "/api/policy", raw=b"{nope")[0] == 400
    assert win.call("POST", "/api/policy", raw=b"[1,2]")[0] == 400
    assert win.call("POST", "/api/policy", raw=b"{" + b" " * 9000 + b"}")[0] == 413
    assert win.call("POST", "/api/nothing", {})[0] == 404
    assert win.call("GET", "/api/nothing")[0] == 404


def test_state_never_contains_the_device_token(win):
    win.state.cfg.server, win.state.cfg.token, win.state.cfg.device_id = "http://x", "SECRET-TOKEN-VALUE", "d1"
    s, h, t = win.call("GET", "/api/state")
    assert s == 200 and b"SECRET-TOKEN-VALUE" not in t
    d = json.loads(t)
    assert d["paired"] is True and d["server"] == "http://x"


# ---- rules
def test_policy_edits_persist_to_disk(win, root):
    assert win.j("POST", "/api/policy", {"action": "add-root", "value": str(root)})[0] == 200
    assert win.j("POST", "/api/policy", {"action": "add-host", "value": "example.com"})[0] == 200
    assert win.j("POST", "/api/policy", {"action": "enable", "value": "run_command"})[0] == 200
    on_disk = cfgmod.load()
    assert on_disk.roots == [str(root)] and on_disk.hosts == ["example.com"] and "run_command" in on_disk.kinds
    assert win.j("POST", "/api/policy", {"action": "remove-host", "value": "example.com"})[0] == 200
    assert cfgmod.load().hosts == []
    assert win.j("POST", "/api/policy", {"action": "disable", "value": "run_command"})[0] == 200
    assert "run_command" not in cfgmod.load().kinds


@pytest.mark.parametrize("body", [
    {"action": "add-root", "value": "/"}, {"action": "add-root", "value": ""}, {"action": "add-root"},
    {"action": "enable", "value": "power"}, {"action": "enable", "value": "system_setting"}, {"action": "add-nothing", "value": "x"},
    {"action": "add-host", "value": "   "}, {"action": 5, "value": "x"}, {"action": "drop-root", "value": "/tmp/x"},
])
def test_policy_refusals(win, body):
    before = cfgmod.load().__dict__.copy()
    s, d = win.j("POST", "/api/policy", body)
    assert s == 422 and d["error"]
    assert cfgmod.load().__dict__ == before


def test_state_lists_every_kind_with_risk(win):
    d = win.j("GET", "/api/state")[1]
    by = {k["id"]: k for k in d["kinds"]}
    assert set(by) == set(cfgmod.ALL_KINDS)
    assert by["delete_file"]["risk"] == "high" and by["run_command"]["risk"] == "high" and by["read_file"]["risk"] == "low" and by["write_file"]["risk"] == "medium"
    assert by["read_file"]["on"] and by["list_dir"]["on"] and by["write_file"]["on"] and not by["delete_file"]["on"] and not by["run_command"]["on"]
    assert len(d["unsupported"]) == 2


def test_policy_module_directly():
    c = Config()
    policy.apply(c, "add-app", " notepad ")
    assert c.apps == ["notepad"]
    policy.apply(c, "add-app", "notepad")
    assert c.apps == ["notepad"]                      # no duplicates
    policy.apply(c, "remove-app", "notepad")
    assert c.apps == []
    with pytest.raises(ValueError):
        policy.apply(c, "add-bogus", "x")


# ---- pairing + running
def test_pair_then_run_executes_job_and_records_history(win, server, root):
    (root / "a.txt").write_text("hello")
    assert win.j("POST", "/api/run", {"on": True})[0] == 422                 # not paired yet
    assert win.j("POST", "/api/pair", {"server": server.url, "code": "WRONG"})[0] == 422
    assert not win.state.cfg.paired
    assert win.j("POST", "/api/pair", {"server": server.url, "code": server.code})[0] == 200
    assert cfgmod.load().paired and cfgmod.load().token == server.token
    win.j("POST", "/api/policy", {"action": "add-root", "value": str(root)})
    server.queue += [{"id": f"act{1:023}", "kind": "read_file", "params": {"path": str(root / "a.txt")}},
                     {"id": f"act{2:023}", "kind": "delete_file", "params": {"path": str(root / "a.txt")}}]
    assert win.j("POST", "/api/run", {"on": True})[0] == 200
    assert wait(lambda: len(server.reports) == 2)
    assert (root / "a.txt").exists()                                          # delete_file is off by default
    rows = win.j("GET", "/api/state")[1]["history"]
    assert [r["kind"] for r in rows] == ["delete_file", "read_file"]          # newest first
    assert [r["ok"] for r in rows] == [False, True]
    assert win.j("GET", "/api/state")[1]["running"] is True
    win.j("POST", "/api/run", {"on": False})
    assert wait(lambda: not win.state.running(), 8)


def test_rule_edit_while_running_takes_effect_immediately(win, server, root):
    (root / "a.txt").write_text("hello")
    win.j("POST", "/api/pair", {"server": server.url, "code": server.code})
    win.j("POST", "/api/run", {"on": True})
    server.queue.append({"id": f"act{3:023}", "kind": "read_file", "params": {"path": str(root / "a.txt")}})
    assert wait(lambda: len(server.reports) == 1)
    assert server.reports[0][1]["ok"] is False                                # no folder allowed yet
    win.j("POST", "/api/policy", {"action": "add-root", "value": str(root)})
    server.queue.append({"id": f"act{4:023}", "kind": "read_file", "params": {"path": str(root / "a.txt")}})
    assert wait(lambda: len(server.reports) == 2)
    assert server.reports[1][1]["ok"] is True
    win.j("POST", "/api/run", {"on": False})


def test_start_twice_runs_one_loop(win, server):
    win.j("POST", "/api/pair", {"server": server.url, "code": server.code})
    win.j("POST", "/api/run", {"on": True})
    t = win.state.thread
    win.j("POST", "/api/run", {"on": True})
    assert win.state.thread is t
    win.j("POST", "/api/run", {"on": False})


def test_revoked_device_shows_error_and_stops(win, server):
    win.j("POST", "/api/pair", {"server": server.url, "code": server.code})
    server.revoked = True
    win.j("POST", "/api/run", {"on": True})
    assert wait(lambda: not win.state.running())
    assert "pair again" in win.j("GET", "/api/state")[1]["error"]


def test_pair_refuses_plain_http_unless_allowed(server):
    w = Win()
    w.state.allow_http = False
    try:
        s, d = w.j("POST", "/api/pair", {"server": "http://example.com", "code": server.code})
        assert s == 422 and not w.state.cfg.paired and server.requests == []
    finally:
        w.close()


# ---- history
def test_history_newest_first_capped_and_tolerant():
    for i in range(history.KEEP + 30):
        history.record("read_file", i % 2 == 0, f"line{i}\nsecond line", when=1000 + i)
    rows = history.recent(10)
    assert rows[0]["text"] == f"line{history.KEEP + 29}" and len(rows) == 10
    assert len(history._all()) == history.KEEP
    with open(history._file(), "a", encoding="utf-8") as f:
        f.write("garbage\n[1]\n")
    assert len(history._all()) == history.KEEP
    history.record("x", True, "y" * 1000)
    assert len(history.recent(1)[0]["text"]) == 300


def test_history_file_is_private(tmp_path):
    import os, stat
    history.record("a", True, "b")
    if os.name == "posix":
        assert stat.S_IMODE(os.stat(history._file()).st_mode) == 0o600


def test_cli_run_records_history(server, root, capsys):
    from jarvis_agent.cli import main
    (root / "a.txt").write_text("x")
    assert main(["pair", "--server", server.url, "--code", server.code, "--allow-http"]) == 0
    main(["policy", "add-root", str(root)])
    server.queue.append({"id": f"act{5:023}", "kind": "read_file", "params": {"path": str(root / "a.txt")}})
    assert main(["run", "--once", "--allow-http"]) == 0
    assert history.recent(1)[0]["kind"] == "read_file" and history.recent(1)[0]["ok"] is True


def test_window_binds_to_loopback_only():
    st = gui.State()
    srv = gui.bind(st, "tok")
    try:
        assert srv.server_address[0] == "127.0.0.1"
    finally:
        srv.server_close()


def test_serve_prints_url_with_token_in_fragment(monkeypatch, capsys):
    opened = []
    monkeypatch.setattr(gui.webbrowser, "open", lambda u: opened.append(u))
    real = gui.ThreadingHTTPServer.serve_forever
    monkeypatch.setattr(gui.ThreadingHTTPServer, "serve_forever", lambda self: (_ for _ in ()).throw(KeyboardInterrupt()))
    assert gui.serve(open_browser=True) == 0
    out = capsys.readouterr().out
    assert len(opened) == 1 and opened[0].startswith("http://127.0.0.1:") and "/#" in opened[0] and opened[0] in out
    monkeypatch.setattr(gui.webbrowser, "open", lambda u: opened.append("again"))
    assert gui.serve(open_browser=False) == 0
    assert "again" not in opened


def _launch(extra, stdin):
    import os, subprocess, sys
    env = dict(os.environ, PYTHONPATH=os.path.dirname(os.path.dirname(os.path.abspath(gui.__file__))))
    p = subprocess.Popen([sys.executable, "-m", "jarvis_agent", "gui", "--no-browser", *extra], stdin=stdin, stdout=subprocess.PIPE, env=env, text=True)
    line = p.stdout.readline()
    assert "http://127.0.0.1:" in line
    return p


def test_exit_with_parent_stops_when_stdin_closes():
    import subprocess
    p = _launch(["--exit-with-parent"], subprocess.PIPE)
    try:
        time.sleep(0.5)
        assert p.poll() is None
        p.stdin.close()
        assert p.wait(timeout=8) == 0
    finally:
        if p.poll() is None:
            p.kill()


def test_without_flag_closed_stdin_does_not_stop_it():
    import subprocess
    p = _launch([], subprocess.DEVNULL)
    try:
        time.sleep(1.5)
        assert p.poll() is None
    finally:
        p.kill()
        p.wait()
