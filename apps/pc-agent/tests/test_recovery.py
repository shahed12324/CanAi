"""Phase 14 (area 19): crash and recovery, simulated on Linux with REAL process kills (SIGKILL), not mocks.
Windows process termination is a different mechanism and is covered only by the real-device checklist."""
import json
import os
import signal
import subprocess
import sys
import time

import pytest

from jarvis_agent import config as cfgmod, taskengine
from jarvis_agent.config import Config

pytestmark = pytest.mark.skipif(sys.platform == "win32", reason="uses SIGKILL")


def test_config_save_that_dies_midway_keeps_the_old_file_and_leaves_no_temp(agent_home, monkeypatch):
    cfgmod.save(Config(server="https://x.example", token="A" * 64, roots=["/data"]))
    before = cfgmod.path().read_text()
    with monkeypatch.context() as m:
        m.setattr(os, "replace", lambda *a: (_ for _ in ()).throw(OSError("disk went away")))
        with pytest.raises(OSError):
            cfgmod.save(Config(server="https://x.example", token="B" * 64))
    assert cfgmod.path().read_text() == before
    assert cfgmod.load().token == "A" * 64
    assert [p.name for p in cfgmod.path().parent.iterdir() if p.name.startswith(".cfg-")] == []


def test_a_killed_writer_never_leaves_a_half_written_config(agent_home):
    """Hammer config.save in a child process, SIGKILL it at random moments, and check that the file is always complete valid JSON."""
    code = ("import sys; sys.path.insert(0, %r)\nfrom jarvis_agent import config as c\ni=0\nwhile True:\n i+=1\n c.save(c.Config(server='https://x.example', token=('%%064d' %% i), roots=['/r%%d'%%i]*50))\n" % os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    for _ in range(6):
        p = subprocess.Popen([sys.executable, "-c", code], env=dict(os.environ))
        for _w in range(100):
            if cfgmod.path().exists():
                break
            time.sleep(0.05)
        time.sleep(0.25)
        p.send_signal(signal.SIGKILL)
        p.wait()
        raw = cfgmod.path().read_text()
        data = json.loads(raw)                                     # raises if the file was ever half written
        assert len(data["token"]) == 64
        cfgmod.load()


def test_audit_chain_survives_a_kill_and_still_verifies(tmp_path):
    f = tmp_path / "audit.jsonl"
    code = ("import sys; sys.path.insert(0, %r)\nfrom jarvis_agent import taskengine as t\na=t.Audit(%r)\ni=0\nwhile True:\n i+=1\n a.record('t','s','e%%d'%%i,'detail')\n" % (os.path.dirname(os.path.dirname(os.path.abspath(__file__))), str(f)))
    p = subprocess.Popen([sys.executable, "-c", code])
    time.sleep(0.6)
    p.send_signal(signal.SIGKILL)
    p.wait()
    a = taskengine.Audit(f)
    ok, n = a.verify_chain()
    if not ok:                                                      # a kill can cut the LAST line in half: that must be the only damage, and appending must still work
        lines = f.read_text().splitlines()
        f.write_text("\n".join(lines[:-1]) + "\n")
        ok, n = taskengine.Audit(f).verify_chain()
    assert ok and n > 10
    a2 = taskengine.Audit(f)
    a2.record("t", "s", "after-restart", "x")
    assert a2.verify_chain()[0]


def test_a_task_interrupted_by_a_kill_resumes_without_repeating_finished_work(tmp_path):
    root = tmp_path / "r"
    root.mkdir()
    (root / "in.txt").write_text("data")
    plan = {"id": "crash1", "steps": [
        {"id": "s1", "kind": "write_file", "params": {"path": str(root / "a.txt"), "content": "one"}},
        {"id": "s2", "kind": "run_command", "params": {"command": "sleep", "args": ["4"]}},
        {"id": "s3", "kind": "write_file", "params": {"path": str(root / "c.txt"), "content": "three"}}]}
    pj = tmp_path / "plan.json"
    pj.write_text(json.dumps(plan))
    pkg = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    code = ("import sys, json; sys.path.insert(0, %r)\nfrom jarvis_agent import taskengine as t, config as c\n"
            "cfg=c.Config(roots=[%r], commands=['sleep'], kinds=list(c.ALL_KINDS))\neng=t.Engine(cfg)\nplan=json.load(open(%r))\n"
            "eng.run(plan, plan_approval=eng.approvals.issue(t.plan_digest(plan)), step_approvals={'s2': eng.approvals.issue(t.step_digest(plan['id'], plan['steps'][1]))})\n" % (pkg, str(root), str(pj)))
    env = dict(os.environ, JARVIS_AGENT_HOME=str(tmp_path / "home"))
    p = subprocess.Popen([sys.executable, "-c", code], env=env, start_new_session=True)
    for _ in range(60):
        if (root / "a.txt").exists():
            break
        time.sleep(0.1)
    time.sleep(0.5)
    os.killpg(p.pid, signal.SIGKILL)                                # kill the agent AND its child
    p.wait()
    assert (root / "a.txt").read_text() == "one" and not (root / "c.txt").exists()
    mtime = os.path.getmtime(root / "a.txt")
    # a fresh process (the restarted agent) resumes the same plan with a shorter sleep
    plan["steps"][1]["params"]["args"] = ["0"]
    os.environ["JARVIS_AGENT_HOME"] = str(tmp_path / "home")
    try:
        cfg = Config(roots=[str(root)], commands=["sleep"], kinds=list(cfgmod.ALL_KINDS))
        eng = taskengine.Engine(cfg)
        res = eng.run(plan, plan_approval=eng.approvals.issue(taskengine.plan_digest(plan)), step_approvals={'s2': eng.approvals.issue(taskengine.step_digest(plan['id'], plan['steps'][1]))}, resume=True)
    finally:
        os.environ.pop("JARVIS_AGENT_HOME", None)
    assert res.state == "completed", res.summary
    assert "Already done" in res.step("s1").evidence and os.path.getmtime(root / "a.txt") == mtime     # finished work was not repeated
    assert (root / "c.txt").read_text() == "three"
