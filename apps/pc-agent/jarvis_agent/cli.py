from __future__ import annotations

import argparse
import getpass
import json
import os
import platform
import sys
import time

from . import __version__, config as cfgmod, crashreport, gui, history, pause, policy, rotation, stop, taskengine
from .client import AuthError, Client, ServerError, normalise
from .config import Config
from .runner import run

SYSTEMD = """[Unit]
Description=JARVIS ONE computer agent
After=network-online.target
Wants=network-online.target

[Service]
User={user}
Environment=JARVIS_AGENT_HOME={home}
ExecStart={exe} -m jarvis_agent run
Restart=always
RestartSec=10
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths={home} {roots}

[Install]
WantedBy=multi-user.target
"""


def _p(*a: object) -> None:
    print(*a, flush=True)


def cmd_pair(args: argparse.Namespace) -> int:
    cfg = cfgmod.load()
    try:
        server = normalise(args.server, args.allow_http)
    except ValueError as e:
        _p("✗", e)
        return 1
    try:
        r = Client(server, allow_http=args.allow_http).pair(args.code)
    except ServerError as e:
        _p("✗ Pairing failed:", "The code is wrong or expired (10 minutes)" if e.status == 422 else f"Could not reach the server ({e})")
        return 1
    cfg.server, cfg.token, cfg.device_id = server, str(r["token"]), str(r["device_id"])
    cfgmod.save(cfg)
    _p(f"✓ Paired. Device {cfg.device_id}")
    _p("Now set this computer's policy — at first no folder is allowed:")
    _p("  jarvis-agent policy add-root /path/folder")
    _p("Then run: jarvis-agent run")
    return 0


def cmd_policy(args: argparse.Namespace) -> int:
    cfg = cfgmod.load()
    if args.action == "show":
        _p("Folders:", cfg.roots or "(none)")
        _p("Apps:", cfg.apps or "(none)")
        _p("Commands:", cfg.commands or "(none)")
        _p("Sites:", cfg.hosts or "(none)")
        _p("Enabled kinds:", cfg.kinds)
        return 0
    try:
        policy.apply(cfg, args.action, args.value)
    except ValueError as e:
        _p("✗", e)
        return 1
    cfgmod.save(cfg)
    _p("✓ Saved")
    return 0


def cmd_run(args: argparse.Namespace) -> int:
    cfg = cfgmod.load()
    if not cfg.paired:
        _p("✗ Pair first: jarvis-agent pair --server https://… --code …")
        return 1
    if not cfg.roots:
        _p("⚠ No folder is allowed, so all file tasks will be rejected. Add one: jarvis-agent policy add-root /path")
    rotation.adopt_pending(cfg, allow_http=args.allow_http)                      # finish a rotation interrupted earlier, if any
    _p(f"jarvis-agent {__version__} is running ({platform.system()}). Press Ctrl+C to stop")
    try:
        return run(cfg, Client(cfg.server, cfg.token, args.allow_http), interval=max(1.0, args.interval), once=args.once, on_job=history.record)
    except KeyboardInterrupt:
        _p("Stopped")
        return 0


def cmd_gui(args: argparse.Namespace) -> int:
    return gui.serve(port=args.port, open_browser=not args.no_browser, allow_http=args.allow_http, exit_with_parent=args.exit_with_parent)


def cmd_status(_: argparse.Namespace) -> int:
    cfg = cfgmod.load()
    if cfg.token_unreadable:
        _p("⚠ The saved token was protected for another Windows user or computer and cannot be opened here. Pair again: jarvis-agent pair")
    _p("Paired:", "yes — " + cfg.server if cfg.paired else "no")
    _p("Version:", __version__, "·", platform.platform())
    _p("Config:", cfgmod.path())
    return 0


def cmd_service(args: argparse.Namespace) -> int:
    cfg = cfgmod.load()
    if args.target == "systemd":
        _p(SYSTEMD.format(user=getpass.getuser(), home=cfgmod.home_dir(), exe=sys.executable, roots=" ".join(cfg.roots)).rstrip())
        _p("\n# Save as /etc/systemd/system/jarvis-agent.service, then: systemctl enable --now jarvis-agent")
    else:
        _p(f'schtasks /Create /SC ONLOGON /TN "JARVIS Agent" /TR "\\"{sys.executable}\\" -m jarvis_agent run" /RL LIMITED')
    return 0


def cmd_stop(args: argparse.Namespace) -> int:
    stop.request("jarvis-agent stop")
    _p("■ Emergency stop is on. A running agent will not take new tasks; programs started by this agent were stopped.")
    _p("To resume: jarvis-agent resume")
    return 0


def cmd_mode(a) -> int:
    c = cfgmod.load()
    if a.value is None:
        print(f"Mode: {c.mode}")
        return 0
    c.mode = a.value
    cfgmod.save(c)
    print(f"Mode changed to {c.mode}" + (" — no tasks will run on this computer." if c.mode == "companion" else "."))
    return 0


def cmd_rotate(args: argparse.Namespace) -> int:
    cfg = cfgmod.load()
    try:
        how = rotation.rotate(cfg, allow_http=args.allow_http)
    except rotation.RotationFailed as e:
        _p("✗", e)
        return 1
    _p("✓ New token is active; the old one no longer works" + (" (the earlier attempt had succeeded on the server; now confirmed)" if how == "recovered" else ""))
    return 0


def cmd_pause(_: argparse.Namespace) -> int:
    pause.request("jarvis-agent pause")
    _p("⏸ Paused: the running task will wait after the current step. To continue: jarvis-agent continue (emergency stop is separate)")
    return 0


def cmd_continue(_: argparse.Namespace) -> int:
    pause.clear()
    _p("▶ Pause removed")
    return 0


def cmd_resume(_: argparse.Namespace) -> int:
    stop.clear()
    _p("✓ Emergency stop removed")
    return 0


def cmd_task(args: argparse.Namespace) -> int:
    """Run a plan file.  Shows every step first; asks a person to approve the plan and each risky step (never approves by itself)."""
    import json
    cfg = cfgmod.load()
    try:
        raw = json.loads(open(args.plan, encoding="utf-8").read())
        if isinstance(raw, dict) and isinstance(raw.get("plan"), dict) and "steps" not in raw:
            raw = raw["plan"]                                   # the planner's reply {status, plan, review} can be saved as it is
        plan = taskengine.validate(raw, cfg)
    except (OSError, ValueError) as e:
        _p("✗ Invalid plan:", e)
        return 1
    _p(f"Plan {plan['id']} — {len(plan['steps'])} steps:")
    for s in plan["steps"]:
        _p(f"  {s['id']}: {s['kind']} [{taskengine.RISK.get(s['kind'], 'high')}] {json.dumps(s.get('params', {}), ensure_ascii=False)[:160]}")
    for s in plan["steps"]:
        why = taskengine.Engine._needs_approval(None, s, s.get("params", {}), False)
        if why:
            _p(f"    ↳ {s['id']}: separate approval needed ({why})")
    if args.dry_run:
        _p("(dry-run: nothing was run)")
        return 0
    if not sys.stdin.isatty():
        _p("✗ A human is needed for approval (run in a terminal)")
        return 1
    if input("Run this plan? (type yes): ").strip().lower() != "yes":
        _p("Cancelled")
        return 1
    eng = taskengine.Engine(cfg)
    step_ok: dict[str, taskengine.Approval] = {}
    for s in plan["steps"]:
        if taskengine.RISK.get(s["kind"], "high") in taskengine.HIGH or s.get("params", {}).get("overwrite") is True or s.get("needs_confirm"):
            if input(f"Step {s['id']} ({s['kind']}) — give separate approval? (yes): ").strip().lower() == "yes":
                step_ok[s["id"]] = eng.approvals.issue(taskengine.step_digest(plan["id"], s))
    res = eng.run(plan, plan_approval=eng.approvals.issue(taskengine.plan_digest(plan)), step_approvals=step_ok, resume=args.resume)
    _p(res.summary)
    return 0 if res.state == "completed" else 1


def cmd_crash_report(args) -> int:
    if args.clear:
        _p("✓ Deleted", crashreport.clear(), "crash report(s)")
        return 0
    if args.show:
        r = crashreport.read(args.show)
        if r is None:
            _p("✗ No such report")
            return 1
        _p(json.dumps(r, ensure_ascii=False, indent=1))
        return 0
    if args.bundle:
        _p(crashreport.bundle())
        return 0
    rows = crashreport.list_reports()
    if not rows:
        _p("No crash reports. (Reports stay on this computer; nothing is sent automatically.)")
    for r in rows:
        _p(time.strftime("%Y-%m-%d %H:%M", time.localtime(r["time"])), r["name"], r["type"], "-", r["message"][:80])
    return 0


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="jarvis-agent", description="JARVIS ONE computer agent")
    sub = ap.add_subparsers(dest="cmd", required=True)
    p = sub.add_parser("pair", help="Pair this computer with the one-time code from the web")
    p.add_argument("--server", required=True)
    p.add_argument("--code", required=True)
    p.add_argument("--allow-http", action="store_true")
    p.set_defaults(fn=cmd_pair)
    p = sub.add_parser("policy", help="This computer's own policy")
    p.add_argument("action", choices=["show", "add-root", "remove-root", "add-app", "remove-app", "add-command", "remove-command", "add-host", "remove-host", "enable", "disable"])
    p.add_argument("value", nargs="?")
    p.set_defaults(fn=cmd_policy)
    p = sub.add_parser("run", help="Start working continuously")
    p.add_argument("--interval", type=float, default=3.0)
    p.add_argument("--once", action="store_true")
    p.add_argument("--allow-http", action="store_true")
    p.set_defaults(fn=cmd_run)
    p = sub.add_parser("gui", help="Open a window on this computer (in a browser, localhost only)")
    p.add_argument("--port", type=int, default=0)
    p.add_argument("--no-browser", action="store_true")
    p.add_argument("--exit-with-parent", action="store_true", help="Exit too when stdin closes (when the launching app dies)")
    p.add_argument("--allow-http", action="store_true")
    p.set_defaults(fn=cmd_gui)
    p = sub.add_parser("mode", help="Companion (runs nothing) or Agent (works within the policy) mode")
    p.add_argument("value", nargs="?", choices=cfgmod.MODES)
    p.set_defaults(fn=cmd_mode)
    sub.add_parser("selfcheck", help="Check whether screen/window/stop etc. work on this computer (does not click or type anything)").set_defaults(fn=lambda _a: __import__("jarvis_agent.selfcheck", fromlist=["main"]).main())
    p = sub.add_parser("validate-windows", help="Real check: run real typing/clicks/keys in its own test window and report PASS/FAIL (runs only with --yes)")
    p.add_argument("--yes", action="store_true")
    p.set_defaults(fn=lambda a: __import__("jarvis_agent.validate", fromlist=["main"]).main(["--yes"] if a.yes else []))
    sub.add_parser("status").set_defaults(fn=cmd_status)
    p = sub.add_parser("service", help="Print a file to run it as a service (installs nothing)")
    p.add_argument("target", choices=["systemd", "windows"])
    p.set_defaults(fn=cmd_service)
    sub.add_parser("stop", help="Emergency stop: no new tasks, running programs stopped").set_defaults(fn=cmd_stop)
    sub.add_parser("resume", help="Remove the emergency stop").set_defaults(fn=cmd_resume)
    p = sub.add_parser("rotate-token", help="Rotate the device token (the old one stops working at once)")
    p.add_argument("--allow-http", action="store_true")
    p.set_defaults(fn=cmd_rotate)
    sub.add_parser("pause", help="Hold the running task before the next step").set_defaults(fn=cmd_pause)
    sub.add_parser("continue", help="Remove the pause").set_defaults(fn=cmd_continue)
    p = sub.add_parser("task", help="Run the steps of a plan file (showing, approving and verifying them)")
    p.add_argument("plan")
    p.add_argument("--resume", action="store_true")
    p.add_argument("--dry-run", action="store_true", help="Only show what would happen and where approval is needed; run nothing")
    p.set_defaults(fn=cmd_task)
    p = sub.add_parser("crash-report", help="Show, copy or delete the local crash reports (nothing is sent automatically)")
    p.add_argument("--show", metavar="NAME", help="Print one report")
    p.add_argument("--bundle", action="store_true", help="Print all reports as one text you can paste into a support message")
    p.add_argument("--clear", action="store_true", help="Delete all crash reports")
    p.set_defaults(fn=cmd_crash_report)
    args = ap.parse_args(argv)
    crashreport.install()
    try:
        return int(args.fn(args))
    except (KeyboardInterrupt, SystemExit):
        raise
    except Exception as e:  # noqa: BLE001
        name = crashreport.record(type(e), e, e.__traceback__, args.cmd)
        _p("✗ Unexpected error:", type(e).__name__)
        if name:
            _p("A crash report was saved on this computer (nothing was sent). See it with: jarvis-agent crash-report")
        return 70
