import json
import sys
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import jarvis_one as j  # noqa: E402

KEY = "jk_live_" + "ab12" * 10
WID = "w" * 26


class Fake(BaseHTTPRequestHandler):
    seen = []
    script = {}                      # (method, path) -> (status, body, headers)

    def log_message(self, *a):
        pass

    def _go(self):
        n = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(n).decode() if n else ""
        Fake.seen.append((self.command, self.path, dict(self.headers), body))
        status, out, hdr = Fake.script.get((self.command, self.path), (404, {"error": "not_found"}, {}))
        raw = out if isinstance(out, bytes) else json.dumps(out).encode()
        self.send_response(status)
        for k, v in hdr.items():
            self.send_header(k, v)
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    do_GET = do_POST = _go


@pytest.fixture
def srv():
    Fake.seen, Fake.script = [], {}
    s = HTTPServer(("127.0.0.1", 0), Fake)
    t = threading.Thread(target=s.serve_forever, daemon=True)
    t.start()
    yield f"http://127.0.0.1:{s.server_port}/api/public/v1", s
    s.shutdown()


def client(base, **kw):
    return j.Client(KEY, base, **kw)


def test_sends_the_key_only_as_a_bearer_header_and_returns_data(srv):
    base, _ = srv
    Fake.script[("GET", "/api/public/v1/me")] = (200, {"data": {"user": {"id": "u", "name": "রহিম"}}}, {})
    assert client(base).me()["user"]["name"] == "রহিম"
    m, path, hdr, _ = Fake.seen[0]
    assert hdr["Authorization"] == "Bearer " + KEY
    assert KEY not in path
    assert hdr["Accept"] == "application/json" and hdr["User-Agent"].startswith("jarvis-one")


def test_run_workflow_posts_text_inputs_and_run_status_reads_back(srv):
    base, _ = srv
    Fake.script[("POST", f"/api/public/v1/workflows/{WID}/run")] = (202, {"data": {"run_id": "r" * 26, "status": "queued"}}, {})
    Fake.script[("GET", f"/api/public/v1/runs/{'r' * 26}")] = (200, {"data": {"run_id": "r" * 26, "status": "done", "spent": "1.0000", "max_credits": "5.0000"}}, {})
    c = client(base)
    out = c.run_workflow(WID, {"নাম": "মান", "n": 5})
    assert out["status"] == "queued"
    m, p, hdr, body = Fake.seen[0]
    assert json.loads(body) == {"inputs": {"নাম": "মান", "n": "5"}} and hdr["Content-Type"] == "application/json"
    assert c.run("r" * 26)["status"] == "done"


@pytest.mark.parametrize("status,exc", [(401, j.AuthError), (403, j.ScopeError), (404, j.NotFound), (429, j.RateLimited), (409, j.RequestFailed), (422, j.RequestFailed), (500, j.RequestFailed)])
def test_errors_map_to_their_own_classes_and_carry_status_and_code(srv, status, exc):
    base, _ = srv
    Fake.script[("GET", "/api/public/v1/credits")] = (status, {"error": "some_code", "message": "no"}, {"Retry-After": "7"})
    with pytest.raises(exc) as e:
        client(base).credits()
    assert e.value.status == status and e.value.code == "some_code"
    assert KEY not in str(e.value) and KEY not in repr(e.value)
    if status == 429:
        assert e.value.retry_after == 7.0


def test_a_non_json_error_or_success_body_is_reported_cleanly_and_a_huge_message_is_cut(srv):
    base, _ = srv
    Fake.script[("GET", "/api/public/v1/me")] = (502, b"<html>bad gateway</html>", {})
    with pytest.raises(j.RequestFailed) as e:
        client(base).me()
    assert e.value.status == 502 and "502" in str(e.value)
    Fake.script[("GET", "/api/public/v1/credits")] = (200, b"not json", {})
    with pytest.raises(j.RequestFailed):
        client(base).credits()
    Fake.script[("GET", "/api/public/v1/workflows")] = (200, b"[1,2]", {})
    with pytest.raises(j.RequestFailed):
        client(base).workflows()
    Fake.script[("GET", "/api/public/v1/me")] = (400, {"error": "x", "message": "m" * 1000}, {})
    with pytest.raises(j.RequestFailed) as e2:
        client(base).me()
    assert len(str(e2.value)) == 200


def test_a_redirect_is_not_followed_so_the_key_cannot_be_sent_elsewhere(srv):
    base, _ = srv
    Fake.script[("GET", "/api/public/v1/me")] = (302, b"", {"Location": "http://127.0.0.1:9/steal"})
    with pytest.raises(j.RequestFailed) as e:
        client(base).me()
    assert e.value.status == 302
    assert len(Fake.seen) == 1


def test_unreachable_server_is_a_clean_error_without_the_key():
    c = j.Client(KEY, "http://127.0.0.1:1/api", timeout=1)
    with pytest.raises(j.RequestFailed) as e:
        c.me()
    assert KEY not in str(e.value)


@pytest.mark.parametrize("key", ["", "jk_live_", "jk_live_" + "a" * 39, "jk_live_" + "a" * 41, "jk_live_" + "A" * 40, "sk_live_" + "a" * 40, None, 5, " jk_live_" + "a" * 40])
def test_a_malformed_key_is_refused_before_anything_is_sent(key):
    with pytest.raises(ValueError):
        j.Client(key, "https://example.com/api/public/v1")


@pytest.mark.parametrize("url", ["http://example.com/api", "ftp://example.com", "https://", "https://u:p@example.com/", "example.com/api", ""])
def test_only_https_or_local_http_base_urls(url):
    with pytest.raises(ValueError):
        j.Client(KEY, url)
    j.Client(KEY, "https://example.com/api/public/v1")
    j.Client(KEY, "http://localhost:8000/api/public/v1")


@pytest.mark.parametrize("bad", ["", "short", "A" * 26, "a" * 27, "../x", "a" * 25 + "/"])
def test_ids_are_checked_before_they_go_into_a_path(srv, bad):
    base, _ = srv
    with pytest.raises(ValueError):
        client(base).run(bad)
    with pytest.raises(ValueError):
        client(base).run_workflow(bad)
    assert Fake.seen == []


def test_inputs_must_be_text_like(srv):
    base, _ = srv
    for bad in ({"a": [1]}, {1: "x"}, {"a": None}, "x"):
        with pytest.raises(ValueError):
            client(base).run_workflow(WID, bad)
    assert Fake.seen == []


def test_repr_hides_most_of_the_key(srv):
    base, _ = srv
    r = repr(client(base))
    assert KEY not in r and KEY[:12] in r


def test_wait_for_run_polls_until_final_and_times_out(srv):
    base, _ = srv
    rid = "r" * 26
    states = iter(["queued", "running", "waiting_approval", "done"])
    c = client(base)
    now = [0.0]
    slept = []
    c.run = lambda i: {"run_id": i, "status": next(states)}
    out = c.wait_for_run(rid, timeout=100, poll=2, _sleep=lambda s: (slept.append(s), now.__setitem__(0, now[0] + s)), _now=lambda: now[0])
    assert out["status"] == "done" and slept == [2, 2, 2]
    for final in ("failed", "cancelled"):
        c.run = lambda i, f=final: {"run_id": i, "status": f}
        assert c.wait_for_run(rid)["status"] == final
    now[0] = 0.0
    c.run = lambda i: {"run_id": i, "status": "waiting_approval"}
    with pytest.raises(TimeoutError):
        c.wait_for_run(rid, timeout=5, poll=2, _sleep=lambda s: now.__setitem__(0, now[0] + s), _now=lambda: now[0])
    assert now[0] <= 5
