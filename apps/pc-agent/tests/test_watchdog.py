import time

import pytest

from jarvis_agent import actions, runner, stop, watchdog
from jarvis_agent.client import ServerError
from jarvis_agent.config import ALL_KINDS, Config


@pytest.fixture(autouse=True)
def _clean():
    stop.clear()
    yield
    stop.clear()


class C:
    """A fake server connection: emergency() answers from a script."""
    def __init__(self, answers):
        self.answers = list(answers)
        self.polls = 0
        self.reports = []
        self.jobs = []

    def emergency(self):
        self.polls += 1
        a = self.answers.pop(0) if self.answers else False
        if isinstance(a, Exception):
            raise a
        return a

    def metrics(self, m): pass
    def next_action(self): return self.jobs.pop(0) if self.jobs else None
    def report(self, i, ok, t): self.reports.append((i, ok, t))


def test_server_stop_kills_a_running_command_within_a_couple_of_seconds(tmp_path):
    cfg = Config(roots=[str(tmp_path)], commands=["sleep"], kinds=list(ALL_KINDS))
    c = C([False, True])
    t0 = time.time()
    with watchdog.ServerStopWatch(c, interval=0.2, log=lambda s: None) as w:
        ok, out = actions.execute(cfg, {"id": "a", "kind": "run_command", "params": {"command": "sleep", "args": ["30"]}})
    assert time.time() - t0 < 5
    assert w.fired and stop.is_set()
    assert not ok and "top" in out


def test_no_stop_when_server_says_all_clear(tmp_path):
    with watchdog.ServerStopWatch(C([False, False, False]), interval=0.05, log=lambda s: None) as w:
        time.sleep(0.3)
    assert not w.fired and not stop.is_set()


def test_unreachable_server_does_not_stop_the_job_by_itself():
    c = C([ServerError(0, "URLError")] * 5)
    with watchdog.ServerStopWatch(c, interval=0.05, log=lambda s: None) as w:
        time.sleep(0.3)
    assert not w.fired and w.errors >= 2 and not stop.is_set()


def test_old_client_without_emergency_is_fine():
    class Old: pass
    with watchdog.ServerStopWatch(Old(), interval=0.05, log=lambda s: None) as w:
        time.sleep(0.15)
    assert not w.fired


def test_runner_job_is_stopped_by_server_and_agent_then_stays_paused(tmp_path):
    cfg = Config(roots=[str(tmp_path)], commands=["sleep"], kinds=list(ALL_KINDS))
    c = C([True] * 50)
    c.jobs = [{"id": "J" * 26, "kind": "run_command", "params": {"command": "sleep", "args": ["30"]}}]
    logs = []
    t0 = time.time()
    runner.run(cfg, c, interval=0.01, sleep=lambda s: None, max_loops=3, log=logs.append)
    assert time.time() - t0 < 8
    assert c.reports and c.reports[0][1] is False
    assert stop.is_set()                                         # stays set until a person runs `resume`
    assert any("mergency stop" in l for l in logs)


def test_client_emergency_parses_answer_and_tolerates_404(monkeypatch):
    from jarvis_agent.client import Client
    cl = Client("https://x.example.com", "tok")
    monkeypatch.setattr(cl, "_call", lambda m, p, b=None: {"active": True})
    assert cl.emergency() is True
    monkeypatch.setattr(cl, "_call", lambda m, p, b=None: {"active": "yes"})
    assert cl.emergency() is False                               # only a real boolean true counts
    def boom(m, p, b=None): raise ServerError(404, "")
    monkeypatch.setattr(cl, "_call", boom)
    assert cl.emergency() is False
