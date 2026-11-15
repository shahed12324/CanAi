"""Device-token rotation, agent side, against the fake server (which switches tokens exactly like Laravel's /rotate)."""
import pytest

from jarvis_agent import cli, config as cfgmod, rotation
from jarvis_agent.client import Client
from jarvis_agent.config import Config
from conftest import FakeServer


@pytest.fixture
def srv():
    s = FakeServer()
    yield s


def paired(srv):
    cfg = Config(server=srv.url, token=srv.token, device_id="d" * 26)
    cfgmod.save(cfg)
    return cfg


def fac(server, token, allow_http=False):
    return Client(server, token, True)


def test_rotation_switches_both_sides_and_old_token_dies(srv):
    cfg, old = paired(srv), srv.token
    assert rotation.rotate(cfg, fac) == "rotated"
    assert cfg.token != old and len(cfg.token) == 64 and srv.token == cfg.token and cfg.token_next == ""
    assert cfgmod.load().token == srv.token                                 # persisted
    with pytest.raises(Exception):
        Client(srv.url, old, True).emergency()                              # the old token is refused now
    assert Client(srv.url, cfg.token, True).emergency() in (True, False)


def test_lost_reply_is_recovered_without_re_pairing(srv):
    cfg = paired(srv)
    srv.rotate_lose_reply = True                                            # server switched, answer never arrived
    assert rotation.rotate(cfg, fac) == "recovered"
    assert cfg.token == srv.token and cfg.token_next == ""


def test_server_down_keeps_the_old_token_and_retries_the_same_candidate(srv):
    cfg, old = paired(srv), srv.token
    seen = []
    srv_down = Client(srv.url, old, True)

    def broken(server, token, allow_http=False):
        c = Client("http://127.0.0.1:9", token, True)                       # nothing listens there
        return c
    with pytest.raises(rotation.RotationFailed):
        rotation.rotate(cfg, broken)
    cand = cfgmod.load().token_next
    assert len(cand) == 64 and cfgmod.load().token == old                   # candidate written down, old token still in use
    assert rotation.rotate(cfgmod.load(), fac) == "rotated"                 # later: the SAME candidate is offered and accepted
    assert cfgmod.load().token == cand and srv.token == cand


def test_revoked_device_cannot_rotate_and_says_so(srv):
    cfg = paired(srv)
    srv.revoked = True
    with pytest.raises(rotation.RotationFailed) as e:
        rotation.rotate(cfg, fac)
    assert "pair again" in str(e.value)


def test_unpaired_agent_refuses():
    with pytest.raises(rotation.RotationFailed):
        rotation.rotate(Config(), fac)


def test_adopt_pending_ignores_a_candidate_the_server_never_saw(srv):
    cfg = paired(srv)
    cfg.token_next = "f" * 64
    cfgmod.save(cfg)
    assert rotation.adopt_pending(cfg, fac) is False and cfg.token == srv.token and cfg.token_next == "f" * 64


def test_cli_rotate_token(srv, capsys):
    paired(srv)
    assert cli.main(["rotate-token", "--allow-http"]) == 0
    assert srv.token == cfgmod.load().token and "New token" in capsys.readouterr().out


def test_window_rotate_endpoint_and_token_never_returned():
    from test_desktop_ui import Win
    srv = FakeServer()
    w = Win()
    try:
        w.state.cfg = Config(server=srv.url, token=srv.token, device_id="d" * 26)
        w.state.allow_http = True
        old = srv.token
        s, j = w.j("POST", "/api/rotate", {})
        assert s == 200 and j["ok"] and srv.token != old
        assert srv.token not in str(j) and srv.token not in str(w.j("GET", "/api/state")[1])
        assert w.call("POST", "/api/rotate", {}, token="bad")[0] == 401
    finally:
        w.close()


def test_a_running_loop_survives_a_rotation_done_elsewhere(srv):
    from jarvis_agent.runner import run
    cfg = paired(srv)
    client = Client(srv.url, cfg.token, True)
    other = cfgmod.load()                                           # e.g. `jarvis-agent rotate-token` in another terminal
    assert rotation.rotate(other, fac) == "rotated"
    srv.queue.append({"id": "act" + "0" * 22 + "1", "kind": "list_dir", "risk": "low", "params": {"path": "/nowhere"}})
    logs = []
    code = run(cfg, client, interval=0.01, once=False, log=logs.append, sleep=lambda s: None, max_loops=6)
    assert code == 0 and client.token == other.token and any("new one" in l for l in logs)
    assert srv.reports                                              # it kept working: the queued job was fetched and reported


def test_a_really_revoked_device_still_ends_the_loop(srv):
    from jarvis_agent.runner import run
    cfg = paired(srv)
    srv.revoked = True
    assert run(cfg, Client(srv.url, cfg.token, True), interval=0.01, log=lambda m: None, sleep=lambda s: None, max_loops=3) == 2
