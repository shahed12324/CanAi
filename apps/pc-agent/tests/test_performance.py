"""Phase 14 (area 20): performance, memory and regex-DoS checks.  SIMULATED on a sandbox Linux box with GENEROUS limits (they catch a 10x regression or a leak,
not a 20% slowdown).  Real numbers on a real Windows PC with a real screen/mic are NOT measured here."""
import gc
import http.client
import json
import threading
import time
import tracemalloc

import pytest

from jarvis_agent import crashreport, runner, taskengine, untrusted
from jarvis_agent.client import Client
from jarvis_agent.config import Config
from test_gui import Win

pytestmark = pytest.mark.perf


def timed(fn, *a):
    t = time.perf_counter()
    fn(*a)
    return time.perf_counter() - t


EVIL = [
    "a" * 200_000, " " * 200_000, "ignore " * 30_000, "password" + "=" * 100_000, "Bearer " + "A" * 200_000, "​" * 200_000, "-----BEGIN PRIVATE KEY-----" + "x" * 200_000,
    "1 " * 100_000, "https://" + "a:" * 50_000 + "@x", "(" * 50_000, "eyJ" + "A" * 100_000 + "." + "B" * 100_000, "ক" * 100_000,
]


@pytest.mark.parametrize("evil", EVIL, ids=[f"evil{i}" for i in range(len(EVIL))])
def test_redact_and_scan_have_no_catastrophic_backtracking(evil):
    assert timed(untrusted.redact, evil, 100) < 5.0
    assert timed(untrusted.scan, evil) < 5.0


def test_audit_chain_appends_and_verifies_fast(tmp_path):
    a = taskengine.Audit(tmp_path / "a.jsonl")
    t = timed(lambda: [a.record("t", f"s{i}", "e", "detail " * 5) for i in range(1500)])
    assert t < 30, t
    assert timed(a.verify_chain) < 10


def test_window_api_stays_responsive_under_many_requests():
    w = Win()
    t0 = time.perf_counter()
    for _ in range(300):
        assert w.call("GET", "/api/state")[0] == 200
    per = (time.perf_counter() - t0) / 300
    assert per < 0.02, per            # it was 0.2 s per poll before the CPU sample stopped blocking


def test_window_api_handles_parallel_clients():
    w = Win()
    bad = []

    def hit():
        for _ in range(40):
            if w.call("GET", "/api/state")[0] != 200:
                bad.append(1)
    ts = [threading.Thread(target=hit) for _ in range(8)]
    t0 = time.perf_counter()
    [t.start() for t in ts]; [t.join(60) for t in ts]
    assert not bad and time.perf_counter() - t0 < 60


def test_agent_loop_does_not_leak_memory(server, root):
    """300 idle polling loops (the always-on agent) must not grow memory."""
    cfg = Config(server=server.url, token=server.token, roots=[str(root)])
    c = Client(server.url, server.token)
    runner.run(cfg, c, interval=0.0, log=lambda s: None, sleep=lambda s: None, max_loops=20)            # warm up caches
    gc.collect()
    tracemalloc.start()
    base = tracemalloc.take_snapshot()
    runner.run(cfg, c, interval=0.0, log=lambda s: None, sleep=lambda s: None, max_loops=300)
    gc.collect()
    grown = sum(s.size_diff for s in tracemalloc.take_snapshot().compare_to(base, "filename"))
    tracemalloc.stop()
    assert grown < 3 * 1024 * 1024, f"grew by {grown / 1024:.0f} KB over 300 loops"


def test_many_jobs_do_not_leak_memory(server, root):
    (root / "f.txt").write_text("x" * 1000)
    cfg = Config(server=server.url, token=server.token, roots=[str(root)])
    c = Client(server.url, server.token)
    gc.collect()
    tracemalloc.start()
    base = tracemalloc.take_snapshot()
    for i in range(150):
        server.queue.append({"id": f"act{i:023}", "kind": "read_file", "risk": "low", "params": {"path": str(root / "f.txt")}})
    runner.run(cfg, c, interval=0.0, log=lambda s: None, sleep=lambda s: None, max_loops=170)
    gc.collect()
    grown = sum(s.size_diff for s in tracemalloc.take_snapshot().compare_to(base, "filename"))
    tracemalloc.stop()
    assert grown < 8 * 1024 * 1024, f"grew by {grown / 1024:.0f} KB over 150 jobs"


def test_crash_report_folder_stays_bounded_under_a_crash_loop():
    for i in range(80):
        try:
            raise RuntimeError(f"loop {i}")
        except RuntimeError as e:
            crashreport.record(type(e), e, e.__traceback__, "loop")
    assert len(crashreport.list_reports()) <= crashreport.KEEP
    assert sum(p.stat().st_size for p in crashreport.crash_dir().iterdir()) < 200_000


def test_large_file_read_is_capped_not_loaded_whole(tmp_path):
    from jarvis_agent import actions
    from jarvis_agent.config import ALL_KINDS
    big = tmp_path / "big.txt"
    with open(big, "wb") as f:
        f.seek(300 * 1024 * 1024 - 1)                                  # a sparse 300 MB file
        f.write(b"x")
    cfg = Config(roots=[str(tmp_path)], kinds=list(ALL_KINDS))
    tracemalloc.start()
    t0 = time.perf_counter()
    ok, out = actions.execute(cfg, {"kind": "read_file", "params": {"path": str(big)}})
    peak = tracemalloc.get_traced_memory()[1]
    tracemalloc.stop()
    assert time.perf_counter() - t0 < 5 and peak < 20 * 1024 * 1024, peak
