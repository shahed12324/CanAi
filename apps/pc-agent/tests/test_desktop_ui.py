"""Phase B: the premium desktop window's local backend (static UI, prefs, emergency stop, folder browser, task list, cloud proxy)."""
import os
import time

import pytest

from jarvis_agent import config as cfgmod, localview, prefs, stop as stopmod, taskengine
from jarvis_agent.client import AuthError, ServerError
from jarvis_agent.config import Config

from test_gui import TOKEN, Win


@pytest.fixture
def win():
    w = Win()
    yield w
    w.close()
    stopmod.clear()


# ---------------------------------------------------------------- static UI
def test_every_ui_file_is_served_with_the_right_type_and_strict_csp(win):
    for name, ctype in (("app.css", "text/css"), ("app.js", "text/javascript"), ("logo.svg", "image/svg+xml")):
        s, h, t = win.call("GET", "/ui/" + name, token=None)
        assert s == 200 and h["Content-Type"].startswith(ctype) and len(t) > 100
        assert h["X-Content-Type-Options"] == "nosniff"
    csp = win.call("GET", "/", token=None)[1]["Content-Security-Policy"]
    assert "script-src 'self'" in csp and "unsafe-inline" not in csp and "connect-src 'self'" in csp and "frame-ancestors 'none'" in csp


@pytest.mark.parametrize("p", ["/ui/../config.py", "/ui/..%2fconfig.py", "/ui/nope.js", "/ui/", "/ui/index.html.bak", "/ui/__init__.py", "/ui/%2e%2e/gui.py"])
def test_ui_path_cannot_leave_the_ui_folder(win, p):
    s, _, t = win.call("GET", p, token=None)
    assert s == 404 and b"def " not in t and b"import " not in t


def test_index_page_has_no_inline_script_and_no_token(win):
    t = win.call("GET", "/", token=None)[2].decode()
    assert "<script src=" in t and "<script>" not in t and TOKEN not in t and "onclick" not in t


def test_ui_javascript_never_writes_html_strings():
    """The XSS guard: all dynamic text goes through textContent / text nodes."""
    js = open(os.path.join(os.path.dirname(__file__), "..", "jarvis_agent", "ui", "app.js"), encoding="utf-8").read()
    assert ".innerHTML" not in js and "insertAdjacentHTML" not in js and "document.write" not in js and "eval(" not in js


def test_all_fourteen_menu_screens_have_a_renderer():
    js = open(os.path.join(os.path.dirname(__file__), "..", "jarvis_agent", "ui", "app.js"), encoding="utf-8").read()
    for v in ("home", "chat", "agents", "workflows", "computer", "browser", "files", "memory", "tools", "pairing", "approvals", "hist", "monitor", "rules"):
        assert f"{v}: v" in js.replace("  ", " "), v


# ---------------------------------------------------------------- prefs
def test_prefs_defaults_and_validation():
    assert prefs.merged(None)["theme"] == "dark" and prefs.merged({"theme": "evil"})["theme"] == "dark"
    assert prefs.clean("language", "bn") == "bn" and prefs.clean("voice_rate", 1.5) == 1.5
    for k, v in [("nope", 1), ("theme", "pink"), ("compact", "yes"), ("voice_rate", 9), ("voice_rate", True), ("voice_name", "a\x00b"), ("voice_name", "x" * 500), (5, 1)]:
        with pytest.raises(ValueError):
            prefs.clean(k, v)


def test_wake_word_is_off_by_default():
    assert prefs.merged({})["wake_word"] is False


def test_prefs_endpoint_persists_and_refuses_bad_values(win):
    s, j = win.j("POST", "/api/prefs", {"key": "theme", "value": "light"})
    assert s == 200 and j["prefs"]["theme"] == "light"
    assert cfgmod.load().prefs["theme"] == "light"
    assert win.j("GET", "/api/state")[1]["prefs"]["theme"] == "light"
    for body in ({"key": "theme", "value": "pink"}, {"key": "nope", "value": 1}, {"value": 1}, {"key": "compact", "value": "x"}):
        assert win.j("POST", "/api/prefs", body)[0] == 422
    assert cfgmod.load().prefs == {"theme": "light"}


def test_prefs_endpoint_needs_token_and_same_origin(win):
    assert win.j("POST", "/api/prefs", {"key": "theme", "value": "light"}, token="x" * 20)[0] == 401
    assert win.j("POST", "/api/prefs", {"key": "theme", "value": "light"}, headers={"Origin": "http://evil.example"})[0] == 403


# ---------------------------------------------------------------- emergency stop
def test_emergency_stop_sets_flag_file_and_resume_clears_it(win):
    assert win.j("GET", "/api/state")[1]["emergency"] is False
    s, j = win.j("POST", "/api/stop", {})
    assert s == 200 and j["emergency"] is True and stopmod.flag_file().exists() and stopmod.is_set()
    assert win.j("GET", "/api/state")[1]["emergency"] is True
    assert win.j("POST", "/api/resume", {})[0] == 200
    assert not stopmod.flag_file().exists() and win.j("GET", "/api/state")[1]["emergency"] is False


def test_emergency_stop_kills_a_running_child(win):
    import subprocess, sys
    p = subprocess.Popen([sys.executable, "-c", "import time; time.sleep(60)"], start_new_session=True)
    stopmod.register(p)
    win.j("POST", "/api/stop", {})
    assert p.wait(timeout=5) is not None
    stopmod.unregister(p)


def test_stop_needs_the_token(win):
    assert win.j("POST", "/api/stop", {}, token="bad")[0] == 401 and not stopmod.is_set()


# ---------------------------------------------------------------- folder browser
@pytest.fixture
def tree(tmp_path):
    root = tmp_path / "work"
    (root / "sub").mkdir(parents=True)
    (root / "a.txt").write_text("hello")
    (root / "sub" / "b.txt").write_text("x")
    (tmp_path / "secret").mkdir()
    (tmp_path / "secret" / "pw.txt").write_text("pw")
    return tmp_path


def test_files_lists_only_allowed_folders_and_navigates(win, tree):
    root = str((tree / "work").resolve())
    assert win.j("GET", "/api/files")[1]["entries"] == []
    win.j("POST", "/api/policy", {"action": "add-root", "value": root})
    s, j = win.j("GET", "/api/files")
    assert j["roots"] is True and [e["path"] for e in j["entries"]] == [root]
    s, j = win.j("GET", "/api/files?path=" + root)
    assert s == 200 and j["entries"][0]["name"] == "sub" and j["entries"][0]["dir"] is True and {e["name"] for e in j["entries"]} == {"sub", "a.txt"}
    assert j["parent"] == ""                                              # top of an allowed folder: up goes back to the list
    s, j = win.j("GET", "/api/files?path=" + root + "/sub")
    assert j["parent"] == root and [e["name"] for e in j["entries"]] == ["b.txt"]


@pytest.mark.parametrize("rel", ["/../secret", "/sub/../../secret", "/sub/../.."])
def test_files_refuses_traversal(win, tree, rel):
    root = str((tree / "work").resolve())
    win.j("POST", "/api/policy", {"action": "add-root", "value": root})
    assert win.j("GET", "/api/files?path=" + root + rel)[0] == 422


def test_files_refuses_outside_symlink_and_non_dirs(win, tree):
    root = tree / "work"
    win.j("POST", "/api/policy", {"action": "add-root", "value": str(root.resolve())})
    os.symlink(tree / "secret", root / "link")
    assert win.j("GET", "/api/files?path=" + str(root / "link"))[0] == 422        # symlink out of the root
    assert win.j("GET", "/api/files?path=" + str(tree / "secret"))[0] == 422
    assert win.j("GET", "/api/files?path=" + str((root / "a.txt").resolve()))[0] == 422
    assert win.j("GET", "/api/files?path=relative/x")[0] == 422
    assert win.j("GET", "/api/files?path=/etc")[0] == 422
    assert win.j("GET", "/api/files", token="bad")[0] == 401


def test_files_skips_root_slash_and_caps_big_folders(tmp_path):
    cfg = Config(roots=["/", str(tmp_path)])
    assert [e["path"] for e in localview.list_folder(cfg, None)["entries"]] == [os.path.realpath(str(tmp_path))]
    for i in range(520):
        (tmp_path / f"f{i:04}").write_text("")
    d = localview.list_folder(cfg, str(tmp_path))
    assert len(d["entries"]) == 500 and d["truncated"] is True


# ---------------------------------------------------------------- local task list (from the real audit log)
def test_task_summaries_follow_real_engine_events():
    rows = [
        {"t": 1, "task": "T1", "step": "", "event": "task_started", "detail": "2 ধাপ"},
        {"t": 2, "task": "T1", "step": "s1", "event": "act", "detail": "read_file"},
        {"t": 3, "task": "T1", "step": "", "event": "task_completed", "detail": ""},
        {"t": 4, "task": "T2", "step": "", "event": "task_started", "detail": ""},
        {"t": 5, "task": "T2", "step": "s1", "event": "act", "detail": "x"},
        {"t": 6, "task": "T3", "step": "", "event": "plan_refused", "detail": "bad"},
        {"t": 7, "task": "T4", "step": "", "event": "task_started", "detail": ""},
        {"t": 8, "task": "T4", "step": "", "event": "task_cancelled", "detail": "জরুরি স্টপ"},
    ]
    s = {x["task"]: x for x in localview.task_summaries(rows)}
    assert s["T1"]["state"] == "done" and s["T1"]["steps"] == 1 and s["T2"]["state"] == "running"
    assert s["T3"]["state"] == "failed" and s["T4"]["state"] == "stopped"
    assert [x["task"] for x in localview.task_summaries(rows)] == ["T4", "T3", "T2", "T1"]      # newest first
    assert len(localview.task_summaries(rows, limit=2)) == 2


def test_tasks_endpoint_reads_the_audit_and_checks_the_chain(win):
    a = taskengine.Audit()
    a.record("TASKX", "", "task_started", "1 ধাপ")
    a.record("TASKX", "", "task_completed", "")
    s, j = win.j("GET", "/api/tasks")
    assert s == 200 and j["audit_ok"] is True and j["audit_rows"] == 2 and j["tasks"][0]["state"] == "done"
    lines = open(a.path, encoding="utf-8").read().splitlines()
    open(a.path, "w", encoding="utf-8").write("\n".join([lines[0].replace("1 ধাপ", "99 ধাপ")] + lines[1:]) + "\n")
    assert win.j("GET", "/api/tasks")[1]["audit_ok"] is False                                        # tamper is visible in the UI data
    assert win.j("GET", "/api/tasks", token="bad")[0] == 401


# ---------------------------------------------------------------- cloud proxy
class FakeCloud:
    calls = []
    behave = "ok"

    def __init__(self, server, token="", allow_http=False):
        self.token = token

    def desktop(self, method, path, body=None):
        FakeCloud.calls.append((method, path, body, self.token))
        if FakeCloud.behave == "404":
            raise ServerError(404)
        if FakeCloud.behave == "auth":
            raise AuthError(401)
        if FakeCloud.behave == "offline":
            raise ServerError(0, "URLError")
        if FakeCloud.behave == "422":
            raise ServerError(422, "bad_goal")
        return {"echo": path, "body": body, "items": []}


@pytest.fixture
def cwin():
    FakeCloud.calls, FakeCloud.behave = [], "ok"
    w = Win(client_factory=FakeCloud)
    w.state.cfg = Config(server="https://x.example", token="T" * 64, device_id="D" * 26)
    yield w
    w.close()


def test_cloud_proxy_forwards_with_the_device_token_and_never_leaks_it(cwin):
    s, j = cwin.j("POST", "/api/cloud/chat", {"message": "hi"})
    assert s == 200 and j["echo"] == "chat" and FakeCloud.calls[-1] == ("POST", "chat", {"message": "hi"}, "T" * 64)
    assert "T" * 64 not in str(j) and "T" * 64 not in str(cwin.j("GET", "/api/state")[1])
    s, j = cwin.j("GET", "/api/cloud/workflows/runs")
    assert s == 200 and FakeCloud.calls[-1][:2] == ("GET", "workflows/runs")
    assert cwin.j("GET", "/api/cloud/chat/conversations/01ARZ3NDEKTSV4RRFFQ69G5FAV")[0] == 200


@pytest.mark.parametrize("bad", ["../x", "a/../b", "a//b", "a%2e%2e/b", "-x", "x" * 100, "a;b"])
def test_cloud_proxy_refuses_odd_paths(cwin, bad):
    s, _ = cwin.j("GET", "/api/cloud/" + bad)
    assert s in (404, 400) and FakeCloud.calls == []


@pytest.mark.parametrize("mode,status,err", [("404", 501, "server_too_old"), ("auth", 401, "device_revoked"), ("offline", 502, "offline"), ("422", 422, "bad_goal")])
def test_cloud_proxy_maps_errors_for_the_page(cwin, mode, status, err):
    FakeCloud.behave = mode
    s, j = cwin.j("POST", "/api/cloud/plan", {"goal": "x"})
    assert (s, j["error"]) == (status, err)


def test_cloud_proxy_forwards_only_safe_queries(cwin):
    assert cwin.j("GET", "/api/cloud/memory?q=hello%20world")[0] == 200
    assert FakeCloud.calls[-1][1] == "memory?q=hello+world"
    assert cwin.j("GET", "/api/cloud/chat/conversations?q=%E0%A6%AC%E0%A6%BE%E0%A6%82%E0%A6%B2%E0%A6%BE")[0] == 200      # Bengali, percent-encoded as a browser sends it
    n = len(FakeCloud.calls)
    for bad in ("memory?b=1", "memory?q=a&q=b", "memory?q=" + "x" * 200, "memory?q=%00", "memory?q=1&limit=2&x=3&y=4", "memory?q=../../x&z=1"):
        assert cwin.j("GET", "/api/cloud/" + bad)[0] == 400, bad
    assert len(FakeCloud.calls) == n                                            # every bad query was refused before any call


def test_cloud_proxy_needs_pairing_token_and_origin(win):
    assert win.j("GET", "/api/cloud/bootstrap")[0] == 409                      # not paired
    assert win.j("GET", "/api/cloud/bootstrap", token="bad")[0] == 401
    assert win.j("POST", "/api/cloud/chat", {"message": "x"}, headers={"Origin": "http://evil.example"})[0] == 403
    assert win.j("POST", "/api/cloud/chat", {}, host="evil.example.com")[0] == 403


def test_cloud_body_limit_is_larger_than_policy_but_bounded(cwin):
    assert cwin.j("POST", "/api/cloud/chat", {"message": "x" * 20000})[0] == 200
    assert cwin.j("POST", "/api/cloud/chat", {"message": "x" * 70000})[0] == 413
    assert cwin.j("POST", "/api/prefs", {"key": "voice_name", "value": "x" * 20000})[0] == 413


# ---------------------------------------------------------------- unpair
def test_unpair_forgets_the_token_only_on_this_computer(cwin):
    s, _ = cwin.j("POST", "/api/unpair", {})
    c = cfgmod.load()
    assert s == 200 and c.token == "" and c.server == "" and c.device_id == ""
    assert cwin.j("GET", "/api/state")[1]["paired"] is False
    assert cwin.j("POST", "/api/unpair", {}, token="bad")[0] == 401


# ---------------------------------------------------------------- Phase C: character + voice files
import hashlib
from pathlib import Path

UI = Path(__file__).resolve().parent.parent / "jarvis_agent" / "ui"


def test_character_and_voice_scripts_are_served(win):
    for name in ("character.js", "voice.js", "voice-session.js"):
        s, h, t = win.call("GET", "/ui/" + name, token=None)
        assert s == 200 and h["Content-Type"].startswith("text/javascript") and len(t) > 500, name
    assert b"voice.js" in win.call("GET", "/", token=None)[2]


def test_voice_session_is_the_same_tested_file_as_the_website():
    web = Path(__file__).resolve().parents[2] / "web-overlay" / "public" / "js" / "voice-session.js"
    if not web.exists():
        pytest.skip("website source not next to the agent (e.g. the Windows package)")
    assert hashlib.sha256(web.read_bytes()).hexdigest() == hashlib.sha256((UI / "voice-session.js").read_bytes()).hexdigest()


def test_microphone_is_never_opened_without_a_user_action():
    """No auto-start: getUserMedia / recognition start appear only inside functions reached from a key, a click or the consent-gated switch."""
    js = (UI / "voice.js").read_text(encoding="utf-8")
    assert "wake_word" in js and "hf-consent" in js                      # consent box gate
    assert "setInterval(function () { begin(" not in js and "DOMContentLoaded" not in js
    top_level = [ln for ln in js.splitlines() if ln.startswith("  begin(") or ln.startswith("  startHandsFree(") or ln.startswith("  stream(")]
    assert top_level == []


def test_wake_word_and_hands_free_default_off():
    assert prefs.merged({})["wake_word"] is False and prefs.merged({})["voice_reply"] is False
    for k, v in [("stt_provider", "azure"), ("character_color", "red"), ("character_personality", "evil"), ("character_name", "x" * 30), ("push_to_talk_key", "a"), ("voice_lang", "fr-FR")]:
        with pytest.raises(ValueError):
            prefs.clean(k, v)
    assert prefs.clean("character_color", "mint") == "mint" and prefs.clean("character_name", "Jarvis") == "Jarvis"


def test_voice_audio_bodies_get_a_bigger_but_bounded_limit(cwin):
    big = {"audio_b64": "A" * 1_000_000, "mime": "audio/webm"}
    assert cwin.j("POST", "/api/cloud/voice/listen", big)[0] == 200
    try:                                       # the server may close the socket before the client finished sending the oversized body
        assert cwin.j("POST", "/api/cloud/voice/listen", {"audio_b64": "A" * 3_000_000})[0] == 413
    except (BrokenPipeError, ConnectionResetError):
        pass
    assert cwin.j("POST", "/api/cloud/chat", {"message": "x" * 100000})[0] == 413              # other cloud calls keep the small limit


# ---------------------------------------------------------------- Phase D: live progress + pause endpoints
def test_live_pause_and_continue_endpoints(win):
    from jarvis_agent import pause, progress
    pause.clear(); progress.reset()
    s, j = win.j("GET", "/api/live")
    assert s == 200 and j == {"current": None, "last": None, "paused": False, "emergency": False}
    progress.begin("tX", [("s1", "write_file")]); progress.step("s1", "running", "working")
    assert win.j("GET", "/api/live")[1]["current"]["steps"][0]["state"] == "running"
    assert win.j("POST", "/api/pause", {})[1]["paused"] is True and win.j("GET", "/api/live")[1]["paused"] is True
    assert win.j("POST", "/api/continue", {})[1]["paused"] is False and win.j("GET", "/api/live")[1]["paused"] is False
    progress.end("completed")
    assert win.j("GET", "/api/live")[1]["last"]["state"] == "completed"
    pause.clear(); progress.reset()


def test_live_and_pause_need_the_token_and_the_origin(win):
    for m, p in (("GET", "/api/live"), ("POST", "/api/pause"), ("POST", "/api/continue")):
        assert win.call(m, p, {} if m == "POST" else None, token="wrong")[0] == 401
    assert win.call("POST", "/api/pause", {}, headers={"Origin": "http://evil.example"})[0] == 403
