"""Plans approved on the website arrive signed; the agent verifies the exact text, then runs them through the same engine as everything else."""
import hashlib
import hmac
import json
import time

import pytest

from jarvis_agent import runner, serverplan, stop
from jarvis_agent.client import ServerError
from jarvis_agent.config import ALL_KINDS, Config

TOKEN = "t" * 64
DEV = "dev00000000000000000000001"
PID = "abc00000000000000000000001"


@pytest.fixture(autouse=True)
def _clean_stop():
    stop.clear()
    yield
    stop.clear()


def mac(msg, token=TOKEN):
    return hmac.new(hashlib.sha256(token.encode()).hexdigest().encode(), msg.encode(), hashlib.sha256).hexdigest()


def sha(t):
    return hashlib.sha256(t.encode()).hexdigest()


def cfg(root, **kw):
    return Config(roots=[str(root)], kinds=list(ALL_KINDS), device_id=DEV, token=TOKEN, server="https://x.example", **kw)


def pulled(root, steps=None, approve=("s3",), exp=None, pid=PID, token=TOKEN):
    """Builds exactly what pc_plan_next() returns (see tests/db/test_pc_plans.py for the SQL side of the same contract)."""
    steps = steps or [
        {"id": "s1", "kind": "make_dir", "params": {"path": str(root / "out")}},
        {"id": "s2", "kind": "create_xlsx", "params": {"path": str(root / "out" / "r.xlsx"), "sheets": {"S": [["A", 1]]}},
         "verify": {"type": "file_exists", "path": str(root / "out" / "r.xlsx")}},
        {"id": "s3", "kind": "trash_file", "params": {"path": str(root / "old.txt")}},
    ]
    exp = exp or int(time.time()) + 300
    text = json.dumps({"steps": steps})
    texts = {s["id"]: json.dumps(s) for s in steps}
    return {"id": pid, "device_id": DEV, "plan_text": text, "exp": exp, "needs": ["s3"], "step_texts": texts,
            "plan_sig": mac(f"canai-plan|{DEV}|{pid}|{sha(text)}|{exp}", token),
            "step_sigs": {sid: mac(f"canai-step|{DEV}|{pid}|{sid}|{sha(texts[sid])}|{exp}", token) for sid in approve}}


def test_signed_plan_runs_and_risky_step_needs_its_signature(root):
    (root / "old.txt").write_text("x")
    state, text = serverplan.run_pulled(cfg(root), pulled(root))
    assert state == "done", text
    assert (root / "out" / "r.xlsx").exists() and not (root / "old.txt").exists() and (root / ".canai-trash").exists()


def test_without_the_step_signature_the_risky_step_does_not_run(root):
    (root / "old.txt").write_text("x")
    state, text = serverplan.run_pulled(cfg(root), pulled(root, approve=()))
    assert state == "failed" and (root / "old.txt").exists() and (root / "out" / "r.xlsx").exists()     # safe steps ran, the risky one was refused


def test_tampered_plan_text_is_rejected(root):
    p = pulled(root)
    p["plan_text"] = p["plan_text"].replace("out", "evil")
    state, text = serverplan.run_pulled(cfg(root), p)
    assert state == "failed" and "signature does not match" in text and not (root / "evil").exists()


def test_swapped_step_text_is_rejected_even_with_valid_plan_signature(root):
    (root / "old.txt").write_text("x")
    (root / "keep.txt").write_text("y")
    p = pulled(root)
    step = json.loads(p["step_texts"]["s3"])
    step["params"]["path"] = str(root / "keep.txt")
    p["step_texts"]["s3"] = json.dumps(step)                               # signature of the original text no longer matches
    assert "signature does not match" in serverplan.run_pulled(cfg(root), p)[1]
    p2 = pulled(root)
    plan = json.loads(p2["plan_text"])
    plan["steps"][2]["params"]["path"] = str(root / "keep.txt")
    p2["plan_text"] = json.dumps(plan)                                      # plan text changed: plan signature fails
    assert "signature does not match" in serverplan.run_pulled(cfg(root), p2)[1]
    assert (root / "keep.txt").exists() and (root / "old.txt").exists()


def test_step_text_that_disagrees_with_the_plan_is_rejected(root):
    p = pulled(root)
    other = {"id": "s3", "kind": "trash_file", "params": {"path": str(root / "other.txt")}}
    p["step_texts"]["s3"] = json.dumps(other)
    p["step_sigs"]["s3"] = mac(f"canai-step|{DEV}|{PID}|s3|{sha(p['step_texts']['s3'])}|{p['exp']}")   # validly signed, but not what the plan says
    assert "does not match" in serverplan.run_pulled(cfg(root), p)[1]


def test_wrong_device_wrong_key_expired_and_far_future_are_rejected(root):
    (root / "old.txt").write_text("x")
    c = cfg(root)
    assert "another device" in serverplan.run_pulled(c, pulled(root) | {"device_id": "other"})[1]
    assert "signature does not match" in serverplan.run_pulled(c, pulled(root, token="z" * 64))[1]
    assert "expired" in serverplan.run_pulled(c, pulled(root, exp=int(time.time()) - 60))[1]
    assert "suspiciously long" in serverplan.run_pulled(c, pulled(root, exp=int(time.time()) + 100000))[1]
    assert (root / "old.txt").exists() and not (root / "out").exists()


def test_a_plan_cannot_be_replayed(root):
    (root / "old.txt").write_text("x")
    p = pulled(root)
    assert serverplan.run_pulled(cfg(root), p)[0] == "done"
    (root / "old.txt").write_text("again")
    state, text = serverplan.run_pulled(cfg(root), p)
    assert state == "failed" and "already taken" in text and (root / "old.txt").exists()


def test_local_policy_still_applies_to_a_signed_plan(root, tmp_path):
    outside = tmp_path / "elsewhere"
    outside.mkdir()
    steps = [{"id": "s1", "kind": "make_dir", "params": {"path": str(outside / "x")}}]
    state, text = serverplan.run_pulled(cfg(root), pulled(root, steps=steps, approve=()))
    assert state == "failed" and not (outside / "x").exists()               # the server said yes; this machine's own rules said no
    c = Config(roots=[str(root)], kinds=["read_file"], device_id=DEV, token=TOKEN)
    steps = [{"id": "s1", "kind": "make_dir", "params": {"path": str(root / "y")}}]
    assert serverplan.run_pulled(c, pulled(root, steps=steps, approve=(), pid="p2" + "0" * 24))[0] == "failed" and not (root / "y").exists()


def test_malformed_replies_never_crash(root):
    c = cfg(root)
    for bad in ({}, {"id": 1}, {"id": "a", "device_id": DEV, "plan_text": 5, "exp": 1, "plan_sig": "x"}, pulled(root) | {"exp": "soon"}, pulled(root, pid="q" + "0" * 25) | {"step_sigs": []}):
        assert serverplan.run_pulled(c, bad)[0] == "failed"
    p = pulled(root)
    p["plan_text"], p["plan_sig"] = "not json", mac(f"canai-plan|{DEV}|{PID}|{sha('not json')}|{p['exp']}")
    assert "not JSON" in serverplan.run_pulled(c, p)[1]


def test_emergency_stop_cancels_a_signed_plan(root):
    stop.request("test")
    state, text = serverplan.run_pulled(cfg(root), pulled(root, approve=()))
    assert state == "stopped" and not (root / "out").exists()


# ---- the runner
class StubClient:
    def __init__(self, plans):
        self.plans, self.reports = list(plans), []

    def next_action(self):
        return None

    def next_plan(self):
        return self.plans.pop(0) if self.plans else None

    def report_plan(self, pid, state, result):
        self.reports.append((pid, state, result))

    def metrics(self, m):
        pass


def test_runner_pulls_a_plan_runs_it_and_reports(root):
    (root / "old.txt").write_text("x")
    cl = StubClient([pulled(root)])
    logs = []
    assert runner.run(cfg(root), cl, once=True, log=logs.append, sleep=lambda s: None) == 0
    assert cl.reports and cl.reports[0][:2] == (PID, "done") and not (root / "old.txt").exists()


def test_runner_reports_a_rejected_plan_as_failed_and_runs_nothing(root):
    (root / "old.txt").write_text("x")
    cl = StubClient([pulled(root, token="z" * 64)])
    runner.run(cfg(root), cl, once=True, log=lambda s: None, sleep=lambda s: None)
    assert cl.reports[0][1] == "failed" and (root / "old.txt").exists()


def test_older_server_without_plans_is_not_an_error(root, monkeypatch):
    from jarvis_agent.client import Client
    c = Client("https://x.example", "t" * 64)
    monkeypatch.setattr(c, "_call", lambda *a, **k: (_ for _ in ()).throw(ServerError(404, "")))
    assert c.next_plan() is None
    monkeypatch.setattr(c, "_call", lambda *a, **k: (_ for _ in ()).throw(ServerError(500, "")))
    with pytest.raises(ServerError):
        c.next_plan()
