"""Real installer check (area 14).  After installing the NSIS/MSI build on a Windows PC:
    set JARVIS_REAL_DEVICE=1 && set JARVIS_INSTALLED_AGENT="C:\\Users\\you\\AppData\\Local\\CAN AI Agent\\jarvis-agent.exe" && python -m pytest tests/real/test_real_install.py -v
Optional: JARVIS_EXPECT_SIGNED=1 also requires a valid Authenticode signature."""
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

import pytest

REAL = os.environ.get("JARVIS_REAL_DEVICE") == "1"
EXE = os.environ.get("JARVIS_INSTALLED_AGENT", "")
pytestmark = [pytest.mark.real_device, pytest.mark.skipif(not REAL, reason="set JARVIS_REAL_DEVICE=1 on the real machine"),
              pytest.mark.skipif(not EXE or not os.path.exists(EXE), reason="set JARVIS_INSTALLED_AGENT to the installed jarvis-agent.exe")]


def run(*args):
    env = dict(os.environ, JARVIS_AGENT_HOME=tempfile.mkdtemp())
    return subprocess.run([EXE, *args], capture_output=True, text=True, timeout=60, env=env)


@pytest.mark.area(14)
def test_installed_agent_starts_and_reports_the_release_version():
    r = run("status")
    assert r.returncode == 0, r.stderr
    root = Path(__file__).resolve().parents[4]
    init = root / "apps/pc-agent/jarvis_agent/__init__.py"
    if init.exists():
        want = re.search(r'__version__\s*=\s*"([^"]+)"', init.read_text()).group(1)
        assert want in r.stdout, f"installed version differs from the source version {want}: {r.stdout!r}"


@pytest.mark.area(14)
def test_installed_agent_has_crash_reporting_and_english_help():
    assert run("crash-report").returncode == 0
    h = run("--help").stdout
    assert "pair" in h and not re.search("[\u0980-\u09FF]", h)


@pytest.mark.area(14)
@pytest.mark.skipif(sys.platform != "win32", reason="Authenticode check is Windows only")
def test_signature_status_is_reported_and_valid_if_signing_was_expected():
    r = subprocess.run(["powershell", "-NoProfile", "-Command", f"(Get-AuthenticodeSignature -LiteralPath '{EXE}').Status"], capture_output=True, text=True)
    status = r.stdout.strip()
    print("Authenticode status:", status)
    if os.environ.get("JARVIS_EXPECT_SIGNED") == "1":
        assert status == "Valid", status
    else:
        assert status in ("Valid", "NotSigned", "UnknownError", "HashMismatch"), status     # unsigned is acceptable only when signing was not expected
        assert status != "HashMismatch"
