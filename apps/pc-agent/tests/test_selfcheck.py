import pytest

from jarvis_agent import cli, devices, selfcheck, stop
from test_devices_phase6 import Fake


@pytest.fixture(autouse=True)
def _clean(tmp_path, monkeypatch):
    monkeypatch.setenv("JARVIS_AGENT_HOME", str(tmp_path / "h"))
    stop.clear()
    yield
    devices.set_backends(None)
    stop.clear()


def test_selfcheck_reports_pass_with_a_working_backend(capsys):
    devices.set_backends(Fake())
    rows = selfcheck.run()
    d = {n: st for n, st, _ in rows}
    assert d["screenshot"] == "PASS" and d["foreground window"] == "PASS" and d["screenshot kind end-to-end"] == "PASS" and d["emergency stop marker"] == "PASS"
    assert not stop.is_set()


def test_selfcheck_reports_fail_honestly_without_a_display(capsys):
    devices.set_backends(None)                                    # the real backend: no display / mss here
    rc = cli.main(["selfcheck"])
    out = capsys.readouterr().out
    assert rc == 1 and "FAIL" in out and "NOT exercised" in out
