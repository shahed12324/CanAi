#!/usr/bin/env python3
"""Release consistency check (Phase 12).  Runs anywhere (no Windows needed).  Exit 0 = consistent.

  python3 scripts/release-check.py            # checks the repo
  python3 scripts/release-check.py --tag v1.0.0   # additionally: the tag must equal the version

Checks: one version everywhere (agent package, pyproject, Tauri conf, Cargo.toml, Inno Setup script), the release config bundles the agent sidecar, icon sources exist,
the shell is granted no Tauri powers, the CSP is not loosened, the shell/installer text is English, the release workflow parses and keeps its safety properties
(least-privilege token, signing only through secrets, checksums, smoke test, release only as a DRAFT)."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
BN = re.compile("[ঀ-৿]")


def read(p: str) -> str:
    return (ROOT / p).read_text(encoding="utf-8")


def versions() -> dict[str, str]:
    v = {}
    v["agent __init__"] = re.search(r'__version__\s*=\s*"([^"]+)"', read("apps/pc-agent/jarvis_agent/__init__.py")).group(1)
    v["pyproject"] = re.search(r'(?m)^version\s*=\s*"([^"]+)"', read("apps/pc-agent/pyproject.toml")).group(1)
    v["tauri.conf.json"] = json.loads(read("apps/desktop/tauri.conf.json"))["version"]
    v["Cargo.toml"] = re.search(r'(?m)^version\s*=\s*"([^"]+)"', read("apps/desktop/Cargo.toml")).group(1)
    v["installer.iss"] = re.search(r"(?m)^AppVersion=(\S+)", read("apps/pc-agent/packaging/installer.iss")).group(1)
    return v


def check(tag: str | None = None) -> list[str]:
    bad: list[str] = []
    v = versions()
    if len(set(v.values())) != 1:
        bad.append("versions differ: " + ", ".join(f"{k}={x}" for k, x in v.items()))
    ver = v["agent __init__"]
    if not re.fullmatch(r"\d+\.\d+\.\d+", ver):
        bad.append(f"version '{ver}' is not MAJOR.MINOR.PATCH")
    if tag is not None and tag != "v" + ver:
        bad.append(f"tag {tag} does not equal v{ver}")
    rel = json.loads(read("apps/desktop/tauri.release.conf.json"))
    if "binaries/jarvis-agent" not in rel["bundle"].get("externalBin", []):
        bad.append("release config does not bundle the agent sidecar")
    if not (ROOT / "apps/desktop/icons/icon.png").exists():
        bad.append("apps/desktop/icons/icon.png (source for the generated icons) is missing")
    caps = json.loads(read("apps/desktop/capabilities/default.json"))
    if caps.get("permissions"):
        bad.append("the shell was granted Tauri permissions; the agent page must get none")
    conf = json.loads(read("apps/desktop/tauri.conf.json"))
    csp = conf["app"]["security"]["csp"]
    if "unsafe-eval" in csp or "*" in csp.replace("'self'", ""):
        bad.append("CSP is loosened: " + csp)
    for p in ("apps/desktop/tauri.conf.json", "apps/desktop/ui/index.html", "apps/pc-agent/packaging/installer.iss"):
        if BN.search(read(p)):
            bad.append(f"{p} still has Bengali text (UI is English-only)")
    try:
        import yaml
        wf = yaml.safe_load(read(".github/workflows/desktop-release.yml"))
    except ImportError:
        wf = None
        bad.append("PyYAML is not installed, so the workflow permissions cannot be checked (pip install pyyaml)")
    text = read(".github/workflows/desktop-release.yml")
    if wf is not None:
        if wf.get("permissions") != {"contents": "read"}:
            bad.append("workflow default permissions must be contents: read")
        rel_job = wf["jobs"].get("draft-release", {})
        if rel_job.get("permissions") != {"contents": "write"}:
            bad.append("only the release job may write contents")
    for needle, why in (("--draft", "releases must be created as DRAFT"), ("SHA256SUMS.txt", "checksums are not produced"),
                        ("WINDOWS_CERT_PFX_BASE64", "signing must come from a secret"), ("SIGNING-STATUS.txt", "unsigned builds must say so"),
                        ("Smoke test", "installer smoke test missing"), ("scripts/release-check.py", "workflow must run this check")):
        if needle not in text:
            bad.append(f"workflow: {why}")
    if re.search(r"(?i)(pfx|cert)[^\n]*password\s*[:=]\s*['\"][^'\"$]", text):
        bad.append("workflow seems to contain a hard-coded certificate password")
    return bad


def main(argv: list[str]) -> int:
    tag = argv[argv.index("--tag") + 1] if "--tag" in argv else None
    bad = check(tag)
    if bad:
        print("RELEASE CHECK FAILED")
        for b in bad:
            print(" -", b)
        return 1
    print("release check OK: version", versions()["agent __init__"], "consistent everywhere")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
