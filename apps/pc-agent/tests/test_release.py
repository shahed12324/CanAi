"""Phase 12: the release consistency check (scripts/release-check.py) really catches the mistakes it claims to."""
import importlib.util
import pathlib

import pytest

SCRIPT = pathlib.Path(__file__).resolve().parents[3] / "scripts" / "release-check.py"


@pytest.fixture
def rc():
    spec = importlib.util.spec_from_file_location("release_check", SCRIPT)
    m = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    return m


def patched(rc, monkeypatch, path_part, fn):
    orig = rc.read
    monkeypatch.setattr(rc, "read", lambda p: fn(orig(p)) if path_part in p else orig(p))


def test_repo_is_consistent(rc):
    assert rc.check() == []
    assert rc.check("v" + rc.versions()["agent __init__"]) == []


def test_wrong_tag_is_refused(rc):
    assert any("tag" in b for b in rc.check("v0.0.1"))


def test_version_mismatch_is_caught(rc, monkeypatch):
    patched(rc, monkeypatch, "Cargo.toml", lambda t: t.replace('version = "1.0.0"', 'version = "0.9.0"', 1))
    assert any("versions differ" in b for b in rc.check())


@pytest.mark.parametrize("needle,msg", [
    ("--draft", "DRAFT"), ("SHA256SUMS.txt", "checksums"), ("WINDOWS_CERT_PFX_BASE64", "secret"),
    ("SIGNING-STATUS.txt", "unsigned"), ("Smoke test", "smoke"),
])
def test_workflow_safety_properties_are_required(rc, monkeypatch, needle, msg):
    patched(rc, monkeypatch, "desktop-release.yml", lambda t: t.replace(needle, "REMOVED"))
    assert any(msg in b for b in rc.check())


def test_write_permission_outside_the_release_job_is_caught(rc, monkeypatch):
    patched(rc, monkeypatch, "desktop-release.yml", lambda t: t.replace("permissions:\n  contents: read", "permissions:\n  contents: write", 1))
    assert any("permissions" in b for b in rc.check())


def test_loosened_csp_and_tauri_powers_are_caught(rc, monkeypatch):
    patched(rc, monkeypatch, "tauri.conf.json", lambda t: t.replace("default-src 'self'", "default-src * 'unsafe-eval'"))
    assert any("CSP" in b for b in rc.check())
    monkeypatch.undo()
    patched(rc, monkeypatch, "capabilities/default.json", lambda t: t.replace('"permissions": []', '"permissions": ["shell:allow-execute"]'))
    assert any("permissions" in b for b in rc.check())


def test_bengali_in_shell_text_is_caught(rc, monkeypatch):
    patched(rc, monkeypatch, "index.html", lambda t: t + "এজেন্ট")
    assert any("Bengali" in b for b in rc.check())
