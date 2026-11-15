import json
import os

import pytest

from jarvis_agent import cli, config as cfgmod, runner
from jarvis_agent.client import AuthError, Client, ServerError, normalise
from jarvis_agent.config import ALL_KINDS, Config


def action(i, kind, **params):
    return {"id": f"act{i:023}", "kind": kind, "risk": "low", "params": params}


def test_https_is_required_except_for_localhost():
    assert normalise("https://jarvis.example.com/") == "https://jarvis.example.com"
    assert normalise("http://localhost:8000") == "http://localhost:8000"
    assert normalise("http://127.0.0.1:1") == "http://127.0.0.1:1"
    with pytest.raises(ValueError):
        normalise("http://jarvis.example.com")
    assert normalise("http://jarvis.example.com", allow_http=True)
    for bad in ("ftp://x", "jarvis.example.com", "", "https://"):
        with pytest.raises(ValueError):
            normalise(bad)


def test_pair_stores_a_private_config(server, agent_home, capsys):
    assert cli.main(["pair", "--server", server.url, "--code", "PAIRCODE1"]) == 0
    c = cfgmod.load()
    assert c.paired and c.token == server.token and c.server == server.url and c.roots == []
    if os.name != "nt":
        assert oct(os.stat(cfgmod.path()).st_mode & 0o777) == "0o600"
    assert cli.main(["pair", "--server", server.url, "--code", "PAIRCODE1"]) == 1          # one-time code
    assert cfgmod.load().token == server.token                                             # a failed pair never wipes a good one
    out = capsys.readouterr().out
    assert "code is wrong" in out


def test_pair_refuses_plain_http_to_a_remote_server(agent_home, capsys):
    assert cli.main(["pair", "--server", "http://jarvis.example.com", "--code", "X"]) == 1
    assert not cfgmod.load().paired


def test_full_round_trip_runs_reports_and_sends_health(server, root):
    (root / "a.txt").write_text("হ্যালো")
    server.queue += [action(1, "read_file", path=str(root / "a.txt")), action(2, "list_dir", path=str(root)),
                     action(3, "write_file", path=str(root / "b.txt"), content="নতুন"), action(4, "delete_file", path=str(root / "a.txt"))]
    cfg = Config(server=server.url, token=server.token, roots=[str(root)])
    logs = []
    code = runner.run(cfg, Client(server.url, server.token), interval=0.01, log=logs.append, sleep=lambda s: None, max_loops=10)
    assert code == 0
    got = {r[0]: r[1] for r in server.reports}
    assert got[f"act{1:023}"] == {"ok": True, "result": "হ্যালো"}
    assert got[f"act{2:023}"]["ok"] and "a.txt" in got[f"act{2:023}"]["result"]
    assert got[f"act{3:023}"]["ok"] and (root / "b.txt").read_text(encoding="utf-8") == "নতুন"
    ok4 = got[f"act{4:023}"]
    assert ok4["ok"] is False and "disabled" in ok4["result"] and (root / "a.txt").exists()   # delete not enabled locally
    assert len(server.metrics) == 1 and {"cpu", "mem", "disk"} <= set(server.metrics[0])


def test_agent_never_runs_what_its_own_rules_forbid_even_if_the_server_says_yes(server, root, tmp_path):
    secret = tmp_path / "secret.txt"
    secret.write_text("private")
    server.queue.append(action(1, "read_file", path=str(secret)))
    cfg = Config(server=server.url, token=server.token, roots=[str(root)])
    runner.run(cfg, Client(server.url, server.token), once=True, log=lambda s: None)
    r = server.reports[0][1]
    assert r["ok"] is False and "private" not in r["result"]


def test_once_handles_exactly_one_job(server, root):
    (root / "a").write_text("1")
    server.queue += [action(1, "read_file", path=str(root / "a")), action(2, "read_file", path=str(root / "a"))]
    runner.run(Config(server=server.url, token=server.token, roots=[str(root)]), Client(server.url, server.token), once=True, log=lambda s: None)
    assert len(server.reports) == 1 and len(server.queue) == 1


def test_revoked_device_stops_the_loop_with_a_clear_exit_code(server, root):
    server.revoked = True
    logs = []
    code = runner.run(Config(server=server.url, token=server.token), Client(server.url, server.token), log=logs.append, sleep=lambda s: None, max_loops=5)
    assert code == 2 and any("Pair again" in l for l in logs)
    assert server.requests.count(("GET", "/api/pc/v1/actions/next")) <= 1


def test_server_errors_back_off_and_recover(server, root):
    (root / "a").write_text("ok")
    server.next_status = 500
    sleeps, logs = [], []
    calls = {"n": 0}

    def sleep(s):
        sleeps.append(s)
        calls["n"] += 1
        if calls["n"] == 3:
            server.next_status = 200
            server.queue.append(action(1, "read_file", path=str(root / "a")))

    runner.run(Config(server=server.url, token=server.token, roots=[str(root)]), Client(server.url, server.token), interval=1.0, log=logs.append, sleep=sleep, max_loops=6)
    assert sleeps[:3] == [1.0, 2.0, 4.0]                                                   # doubles, resets after success
    assert server.reports and server.reports[0][1]["ok"] is True


def test_backoff_is_capped(server):
    server.next_status = 500
    sleeps = []
    runner.run(Config(server=server.url, token=server.token), Client(server.url, server.token), interval=3.0, log=lambda s: None, sleep=sleeps.append, max_loops=12)
    assert max(sleeps) == runner.BACKOFF_MAX


def test_unreachable_server_is_just_a_retry(root):
    sleeps = []
    c = Client("http://127.0.0.1:1", "t" * 64)
    assert runner.run(Config(server="http://127.0.0.1:1", token="t" * 64), c, log=lambda s: None, sleep=sleeps.append, max_loops=2) == 0
    assert len(sleeps) == 1
    with pytest.raises(ServerError):
        c.next_action()


def test_a_rejected_metrics_sample_does_not_stop_work(server, root):
    (root / "a").write_text("1")
    server.metrics_status = 429
    server.queue.append(action(1, "read_file", path=str(root / "a")))
    runner.run(Config(server=server.url, token=server.token, roots=[str(root)]), Client(server.url, server.token), once=True, log=lambda s: None)
    assert len(server.reports) == 1


def test_long_results_are_cut_before_sending(server, root):
    (root / "big").write_text("x" * 100000)
    server.queue.append(action(1, "read_file", path=str(root / "big")))
    runner.run(Config(server=server.url, token=server.token, roots=[str(root)]), Client(server.url, server.token), once=True, log=lambda s: None)
    assert len(server.reports[0][1]["result"]) < 2000                                      # the database keeps at most 2000


def test_policy_commands_edit_the_local_file(agent_home, tmp_path, capsys):
    r = tmp_path / "w"
    r.mkdir()
    assert cli.main(["policy", "add-root", str(r)]) == 0
    assert cli.main(["policy", "add-root", str(r)]) == 0                                   # no duplicates
    assert cfgmod.load().roots == [str(r)]
    assert cli.main(["policy", "add-root", os.sep]) == 1                                   # never the whole drive
    assert cli.main(["policy", "add-host", "example.com"]) == 0
    assert cli.main(["policy", "add-command", "git"]) == 0 and cli.main(["policy", "add-app", "firefox"]) == 0
    c = cfgmod.load()
    assert (c.hosts, c.commands, c.apps) == (["example.com"], ["git"], ["firefox"])
    assert cli.main(["policy", "enable", "delete_file"]) == 0 and "delete_file" in cfgmod.load().kinds
    assert cli.main(["policy", "disable", "delete_file"]) == 0 and "delete_file" not in cfgmod.load().kinds
    assert cli.main(["policy", "enable", "power"]) == 1                                    # unknown / unsupported kind
    assert cli.main(["policy", "remove-root", str(r)]) == 0 and cfgmod.load().roots == []
    assert cli.main(["policy", "add-root"]) == 1                                           # value missing
    assert cli.main(["policy", "show"]) == 0


def test_run_needs_pairing_and_service_files_print(agent_home, capsys, server):
    assert cli.main(["run", "--once"]) == 1
    cli.main(["pair", "--server", server.url, "--code", "PAIRCODE1"])
    assert cli.main(["run", "--once"]) == 0
    capsys.readouterr()
    assert cli.main(["service", "systemd"]) == 0
    out = capsys.readouterr().out
    assert "ExecStart=" in out and "NoNewPrivileges=true" in out and "ProtectSystem=strict" in out
    assert cli.main(["service", "windows"]) == 0 and "schtasks" in capsys.readouterr().out
    assert cli.main(["status"]) == 0


def test_config_survives_unknown_fields_and_bad_kinds(agent_home):
    cfgmod.home_dir().mkdir(parents=True)
    cfgmod.path().write_text(json.dumps({"server": "s", "token": "t", "kinds": ["read_file", "power"], "future": 1}))
    c = cfgmod.load()
    assert c.kinds == ["read_file"] and c.paired
