"""Installed command, real process, real HTTP: pair -> policy -> run."""
import os
import shutil
import subprocess
import sys

import pytest


def agent(*args, env):
    exe = shutil.which("jarvis-agent")
    cmd = [exe, *args] if exe else [sys.executable, "-m", "jarvis_agent", *args]
    return subprocess.run(cmd, capture_output=True, text=True, env=env, timeout=60)


def test_pair_policy_run_as_separate_processes(server, root, tmp_path):
    env = dict(os.environ, JARVIS_AGENT_HOME=str(tmp_path / "h"), PYTHONPATH=os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    (root / "note.txt").write_text("পরীক্ষা")
    server.queue.append({"id": "e2e" + "0" * 23, "kind": "read_file", "risk": "low", "params": {"path": str(root / "note.txt")}})
    assert agent("pair", "--server", server.url, "--code", "PAIRCODE1", env=env).returncode == 0
    assert agent("policy", "add-root", str(root), env=env).returncode == 0
    r = agent("run", "--once", env=env)
    assert r.returncode == 0, r.stderr
    assert server.reports and server.reports[0][1] == {"ok": True, "result": "পরীক্ষা"}
    assert "✓ read_file" in r.stdout
