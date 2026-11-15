"""The phone's API client and the Laravel routes must agree: every call the app can make has a matching server route."""
import re
from pathlib import Path

ROOT = Path(__file__).parents[2] / "apps"
PHP_MOBILE = (ROOT / "web-overlay/routes/api_mobile.php").read_text()
PHP_V1 = (ROOT / "web-overlay/routes/api_v1.php").read_text()
TS = (ROOT / "mobile/src/api/endpoints.ts").read_text()


def norm(path: str) -> str:
    path = re.sub(r"(?<=/)\$\{[^}]*\}", "{x}", path)      # ${id} as a whole path part is a parameter
    path = re.sub(r"\$\{[^}]*\}", "", path).split("?")[0]   # ${...} glued to a word is an optional suffix such as a query string
    return re.sub(r"\{[^}]+\}", "{x}", path)


def server_routes() -> set[tuple[str, str]]:
    out = set()
    for m in re.finditer(r"Route::(get|post|put|delete)\('([^']+)'", PHP_MOBILE):
        out.add((m.group(1).upper(), "/mobile/" + norm(m.group(2))))
    for m in re.finditer(r"Route::(get|post|put|delete)\('(auth/[^']+)'", PHP_V1):
        out.add((m.group(1).upper(), "/" + norm(m.group(2))))
    return out


def client_calls() -> set[tuple[str, str]]:
    out = set()
    for m in re.finditer(r"request<[^>]*(?:<[^>]*>[^>]*)*>\('(GET|POST|PUT|DELETE)',\s*(?:`([^`]+)`|'([^']+)')", TS):
        out.add((m.group(1), norm(m.group(2) or m.group(3))))
    return out


def test_every_client_call_has_a_server_route():
    calls, routes = client_calls(), server_routes()
    assert len(calls) >= 20, calls
    assert calls - routes == set(), f"app calls routes the server does not have: {sorted(calls - routes)}"


def test_every_mobile_route_is_used_by_the_app():
    unused = {r for r in server_routes() if r[1].startswith("/mobile/")} - client_calls()
    assert unused == set(), f"server routes the app never calls (dead or forgotten): {sorted(unused)}"


def test_server_never_exposes_the_bearer_token_in_a_url():
    assert "token" not in " ".join(p for _, p in client_calls()).lower().replace("push", "")
