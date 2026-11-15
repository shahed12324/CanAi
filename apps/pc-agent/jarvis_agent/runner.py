"""The loop: ask for one approved job, do it, report, repeat.  The agent only ever PULLS; it opens no port."""
from __future__ import annotations

import time
from typing import Callable

from . import actions, auditsync, config as cfgmod, monitor, serverplan, stop, watchdog
from .client import AuthError, Client, ServerError
from .config import Config

METRICS_EVERY = 30.0
BACKOFF_MAX = 60.0


def _adopt_rotated_token(cfg: Config, client: Client, log: Callable[[str], None]) -> bool:
    """A 401 may only mean another part of this agent (window button, `rotate-token`) just rotated the token.  Look at what is saved on disk and
    test it; a really revoked device fails the test and the loop ends as before."""
    try:
        disk = cfgmod.load()
    except (OSError, ValueError):
        return False
    old = client.token
    for cand in (disk.token, disk.token_next):
        if not cand or cand == old:
            continue
        client.token = cand
        try:
            client.emergency()
        except AuthError:
            continue
        except ServerError:
            client.token = old
            return False
        cfg.token = cand
        log("Device token rotated — continuing with the new one")
        return True
    client.token = old
    return False


def run(cfg: Config, client: Client, *, interval: float = 3.0, once: bool = False, log: Callable[[str], None] = print,
        sleep: Callable[[float], None] = time.sleep, now: Callable[[], float] = time.monotonic, max_loops: int | None = None,
        on_job: Callable[[str, bool, str], None] | None = None, should_stop: Callable[[], bool] | None = None) -> int:
    """Returns 0 on a normal stop, 2 when the server no longer accepts this device."""
    last_metrics = -METRICS_EVERY
    audit_sync = auditsync.Periodic(now=now)
    backoff = 0.0
    loops = 0
    paused = False
    while True:
        if should_stop is not None and should_stop():
            return 0
        loops += 1
        if stop.is_set():                                         # emergency stop: ask for nothing, run nothing, until `jarvis-agent resume`
            if not paused:
                log("■ Emergency stop is on — not taking new tasks (resume with jarvis-agent resume)")
                paused = True
            if once or (max_loops is not None and loops >= max_loops):
                return 0
            sleep(interval)
            continue
        if cfg.mode != "agent":                                   # Companion mode: pull nothing, run nothing (metrics still go, so the computer shows as online)
            if once or (max_loops is not None and loops >= max_loops):
                return 0
            sleep(interval)
            continue
        paused = False
        try:
            if now() - last_metrics >= METRICS_EVERY:
                last_metrics = now()
                m = monitor.sample()
                if m is not None:
                    try:
                        client.metrics(m)
                    except AuthError:
                        raise
                    except ServerError:
                        pass                                      # a missed health sample is not worth stopping for
            res = audit_sync.maybe(client)                           # copies new local audit rows to the server; never blocks a job
            if res is not None and res["status"] in ("diverged", "local_tampered"):
                log(f"⚠ Audit: {res['status']} — the local audit file and the server copy do not match ({res.get('detail', '')})")
            action = client.next_action()
            backoff = 0.0
            if action is None and hasattr(client, "next_plan"):
                plan = client.next_plan()                         # a plan the person approved on the website/phone
                if plan is not None:
                    with watchdog.ServerStopWatch(client, log=log):
                        state, text = serverplan.run_pulled(cfg, plan)
                    log(f"{'✓' if state == 'done' else '✗'} Plan {str(plan.get('id'))[:8]}: {text.splitlines()[0] if text else state}")
                    try:
                        client.report_plan(str(plan.get("id", "")), state, text)
                    except AuthError:
                        raise
                    except ServerError as e:
                        log(f"Could not send the plan report ({e})")
                    if not once:
                        continue
            if action is not None:
                aid = str(action.get("id", ""))
                with watchdog.ServerStopWatch(client, log=log):
                    ok, text = actions.execute(cfg, action)
                if on_job is not None:
                    on_job(str(action.get('kind')), ok, text)
                log(f"{'✓' if ok else '✗'} {action.get('kind')} {aid[:8]}: {text.splitlines()[0] if text else ''}")
                try:
                    client.report(aid, ok, text)
                except AuthError:
                    raise
                except ServerError as e:
                    log(f"Could not send the report ({e}); the task will stop by itself on the server")
                if not once:
                    continue                                      # more jobs may be waiting: ask again at once
        except AuthError:
            if _adopt_rotated_token(cfg, client, log):                # the token was rotated by the window / CLI while we ran: carry on with the new one
                continue
            log("The server no longer accepts this device (removed on the web?). Pair again: jarvis-agent pair")
            return 2
        except ServerError as e:
            backoff = min(BACKOFF_MAX, max(interval, backoff * 2 or interval))
            log(f"Cannot reach the server ({e}); retrying in {backoff:.0f} seconds")
        if once or (max_loops is not None and loops >= max_loops):
            return 0
        sleep(backoff or interval)
