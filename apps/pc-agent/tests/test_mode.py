import pytest

from jarvis_agent import actions, cli, config as cfgmod, runner, stop
from jarvis_agent.config import ALL_KINDS, Config
from test_gui import Win  # noqa: F401  (fixture `win` is defined in test_gui)


@pytest.fixture(autouse=True)
def _clean():
    stop.clear()
    yield
    stop.clear()


def test_default_is_agent_and_unknown_value_falls_to_the_safe_side(tmp_path, monkeypatch):
    monkeypatch.setenv("JARVIS_AGENT_HOME", str(tmp_path))
    assert Config().mode == "agent"
    cfgmod.save(Config(roots=["/x"]))
    p = cfgmod.path()
    p.write_text(p.read_text().replace('"mode": "agent"', '"mode": "hacker"'))
    assert cfgmod.load().mode == "companion"
    p.write_text('{"roots": ["/x"]}')                                   # an older config without the field
    assert cfgmod.load().mode == "agent"


def test_companion_mode_refuses_every_action(tmp_path):
    (tmp_path / "a.txt").write_text("hi")
    c = Config(roots=[str(tmp_path)], kinds=list(ALL_KINDS), mode="companion")
    ok, out = actions.execute(c, {"id": "x", "kind": "read_file", "params": {"path": str(tmp_path / "a.txt")}})
    assert not ok and "Companion" in out
    c.mode = "agent"
    assert actions.execute(c, {"id": "x", "kind": "read_file", "params": {"path": str(tmp_path / "a.txt")}})[0]


class Client:
    def __init__(self):
        self.pulled = 0
    def metrics(self, m): pass
    def next_action(self): self.pulled += 1; return None
    def next_plan(self): self.pulled += 1; return None


def test_runner_pulls_nothing_in_companion_mode(tmp_path):
    c = Config(roots=[str(tmp_path)], mode="companion")
    cl = Client()
    assert runner.run(c, cl, interval=0, sleep=lambda s: None, max_loops=4, log=lambda s: None) == 0
    assert cl.pulled == 0
    c.mode = "agent"
    runner.run(c, cl, interval=0, sleep=lambda s: None, max_loops=1, log=lambda s: None)
    assert cl.pulled >= 1


def test_cli_mode(tmp_path, monkeypatch, capsys):
    monkeypatch.setenv("JARVIS_AGENT_HOME", str(tmp_path))
    assert cli.main(["mode"]) == 0 and "agent" in capsys.readouterr().out
    assert cli.main(["mode", "companion"]) == 0 and cfgmod.load().mode == "companion"
    with pytest.raises(SystemExit):
        cli.main(["mode", "root"])
    assert cfgmod.load().mode == "companion"


def test_gui_mode_switch(tmp_path, monkeypatch):
    monkeypatch.setenv("JARVIS_AGENT_HOME", str(tmp_path))
    w = Win()
    assert w.j("GET", "/api/state")[1]["mode"] == "agent"
    assert w.j("POST", "/api/mode", {"mode": "companion"}) == (200, {"ok": True, "mode": "companion"})
    assert w.j("GET", "/api/state")[1]["mode"] == "companion" and cfgmod.load().mode == "companion"
    for bad in ({"mode": "root"}, {"mode": 5}, {}):
        assert w.j("POST", "/api/mode", bad)[0] == 422
    assert w.j("POST", "/api/mode", {"mode": "agent"}, token="wrong")[0] == 401
    assert w.j("GET", "/api/state")[1]["mode"] == "companion"
    w.httpd.shutdown()
