"""Phase 14: the test-report generator keeps simulated and real evidence apart and never turns 'not run' into 'pass'."""
import importlib.util
import json
import pathlib

import pytest

SCRIPT = pathlib.Path(__file__).resolve().parents[3] / "scripts" / "test-report.py"


@pytest.fixture
def tr():
    spec = importlib.util.spec_from_file_location("test_report", SCRIPT)
    m = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    return m


def test_twenty_areas_are_defined_and_numbered(tr):
    assert [a[0] for a in tr.AREAS] == list(range(1, 21))
    assert {a[1] for a in tr.AREAS} >= {"Real microphone tests", "Windows installation tests", "Live model API tests", "Performance and memory tests"}


def test_real_column_says_not_run_without_a_result_file(tr, tmp_path):
    real = tr.load_real(tmp_path)
    for n in range(1, 21):
        cell = tr.real_cell(n, real)
        assert "PASS" not in cell and ("NOT RUN" in cell)
    assert tr.real_cell(16, real).startswith("MANUAL")
    assert tr.real_cell(18, real).startswith("MANUAL")


def test_real_results_are_read_and_a_failure_wins(tr, tmp_path):
    (tmp_path / "pc1.json").write_text(json.dumps([
        {"test": "a", "area": 15, "result": "pass", "platform": "Windows-11", "when": "2026-11-20T10:00:00"},
        {"test": "b", "area": 15, "result": "fail", "platform": "Windows-11", "when": "2026-11-20T10:01:00"},
        {"test": "c", "area": 14, "result": "pass", "platform": "Windows-11", "when": "2026-11-20T10:02:00"},
        {"test": "skipped-thing", "area": 13, "result": "skipped"},                    # a skip is not evidence
        {"test": "bad-area", "area": 99, "result": "pass"},
    ]))
    real = tr.load_real(tmp_path)
    assert tr.real_cell(15, real).startswith("FAIL") and tr.real_cell(14, real).startswith("PASS")
    assert "NOT RUN" in tr.real_cell(13, real) and 99 not in real


def test_a_validate_windows_report_counts_only_if_it_was_real(tr, tmp_path):
    (tmp_path / "v1.json").write_text(json.dumps({"tool": "jarvis-agent validate-windows", "real_device": False, "summary": {"pass": 9, "fail": 0, "skip": 0}}))
    assert "NOT RUN" in tr.real_cell(15, tr.load_real(tmp_path))
    (tmp_path / "v2.json").write_text(json.dumps({"tool": "jarvis-agent validate-windows", "real_device": True, "when": "2026-11-20", "platform": "Windows 11", "summary": {"pass": 9, "fail": 0, "skip": 0}}))
    assert tr.real_cell(15, tr.load_real(tmp_path)).startswith("PASS")


def test_real_suite_is_skipped_here_and_never_recorded_as_a_pass():
    import subprocess, sys
    r = subprocess.run([sys.executable, "-m", "pytest", "tests/real", "-q", "-p", "no:cacheprovider"], cwd=pathlib.Path(__file__).resolve().parents[1], capture_output=True, text=True)
    assert r.returncode == 0 and "skipped" in r.stdout and "passed" not in r.stdout.replace("0 passed", "")
