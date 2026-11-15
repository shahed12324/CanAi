"""Phase 28: standing security audit of the AI engine."""
import re
from pathlib import Path

from fastapi.routing import APIRoute

from app.security import require_signed_request
from app.main import app

OPEN_PATHS = {"/healthz"}                      # liveness probe only: returns a constant, touches nothing
APP_DIR = Path(__file__).resolve().parents[1] / "app"


def _calls(dependant):
    seen = []
    stack = [dependant]
    while stack:
        d = stack.pop()
        seen.append(d.call)
        stack.extend(d.dependencies)
    return seen


def _all_routes():
    """Every APIRoute of the app, including those inside included routers (FastAPI wraps those, so look through the wrapper)."""
    out = []
    for r in app.routes:
        if isinstance(r, APIRoute):
            out.append(r)
        elif hasattr(r, "original_router"):
            out.extend(x for x in r.original_router.routes if isinstance(x, APIRoute))
    return out


def test_every_route_needs_a_signed_request_except_the_liveness_probe():
    unsigned = [r.path for r in _all_routes() if r.path not in OPEN_PATHS and require_signed_request not in _calls(r.dependant)]
    assert unsigned == []


def test_the_check_is_not_vacuous_and_sees_every_router_module():
    routes = _all_routes()
    assert len([r for r in routes if require_signed_request in _calls(r.dependant)]) >= 15
    # every routes_*.py module's router is really mounted on the app (a forgotten include_router would hide routes from the check, or ship dead routes)
    modules = {p.stem for p in APP_DIR.glob("routes_*.py")}
    included = [r.original_router for r in app.routes if hasattr(r, "original_router")]
    import importlib

    for m in modules:
        assert any(importlib.import_module(f"app.{m}").router is x for x in included), m


def test_every_internal_route_lives_under_internal_v1():
    for r in _all_routes():
        if r.path not in OPEN_PATHS:
            assert r.path.startswith("/internal/v1/"), r.path


def test_no_code_execution_or_shell_in_the_engine():
    bad = []
    for p in APP_DIR.rglob("*.py"):
        text = re.sub(r"#.*", "", p.read_text(encoding="utf-8"))
        if re.search(r"(?<![\w.])(eval|exec)\s*\(|os\.system\(|shell\s*=\s*True|pickle\.loads?\(|yaml\.load\((?!.*Safe)", text):
            bad.append(p.name)
    assert bad == []


def test_no_literal_bidi_control_characters_in_source():
    """'Trojan source': invisible direction-control characters can make code read differently from how it runs.  Use \\u escapes instead."""
    bidi = re.compile("[‪-‮⁦-⁩]")
    bad = [p.name for p in APP_DIR.rglob("*.py") if bidi.search(p.read_text(encoding="utf-8"))]
    assert bad == []


def test_no_hard_coded_secrets():
    pat = re.compile(r"sk-[A-Za-z0-9]{20}|AKIA[0-9A-Z]{12}|-----BEGIN (RSA |EC )?PRIVATE|AIza[0-9A-Za-z_\-]{30}|ghp_[A-Za-z0-9]{30}")
    assert [p.name for p in APP_DIR.rglob("*.py") if pat.search(p.read_text(encoding="utf-8"))] == []
