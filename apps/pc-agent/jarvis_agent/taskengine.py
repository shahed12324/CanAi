"""Plan -> permission check -> act -> observe -> verify -> report (F11, F12), with approvals bound to the exact action (F20), a hash-chained
audit trail (F22) and the emergency stop (F13).

What this is: a LOCAL engine inside the agent.  A plan is a fixed list of steps made by the user or approved by them; text found on the way
(files, web pages) is data and can never add, change or reorder a step.  What it is not: it does not plan from natural language — that is the
AI engine's job and is not connected here — and the server does not queue these new kinds yet (see docs/CAN_AI_GAP_AUDIT.md).

State of a task:  queued -> running -> completed | failed | cancelled   (a step may also be skipped, or wait in awaiting_approval)"""
from __future__ import annotations

import concurrent.futures
import hashlib
import hmac
import json
import os
import re
import secrets
import tempfile
import time
from dataclasses import dataclass, field
from typing import Callable

from . import actions, config as cfgmod, guard, pause, progress, stop, untrusted, verify
from .config import Config

MAX_STEPS = 20
MAX_PARAMS_BYTES = 8000
STEP_TIMEOUT = 60.0
APPROVAL_TTL = 300.0
HIGH = {"high", "critical"}
RISK = {"read_file": "low", "list_dir": "low", "verify_file": "low", "write_file": "medium", "copy_file": "medium", "move_file": "high", "make_dir": "medium",
        "create_xlsx": "medium", "create_docx": "medium", "open_app": "medium", "browser_navigate": "medium", "delete_file": "high", "trash_file": "high", "run_command": "high",
        "screenshot": "high", "input_control": "high", "browser_extract": "medium", "window_info": "medium", "wait_window": "low",
        "screen_read": "high", "screen_find": "high", "ui_click": "high", "screen_describe": "high", "browser_run": "high", "make_report": "medium"}
TAINTS_OUTPUT = {"read_file", "list_dir", "browser_navigate", "run_command", "browser_extract", "window_info", "screen_read", "screen_find", "screen_describe", "browser_run", "make_report"}      # what these return came from outside: it is data, never instructions
WRITES = {"write_file": "file_exists", "copy_file": "file_exists", "move_file": "file_exists", "create_xlsx": "file_exists", "create_docx": "file_exists", "make_report": "xlsx_report", "screenshot": "file_exists"}
REF = re.compile(r"\{\{\s*(s[0-9A-Za-z_-]{0,20})\.out\s*\}\}")
ID = re.compile(r"[A-Za-z0-9_-]{1,40}")


class PlanError(ValueError):
    pass


def canonical(obj: object) -> str:
    return json.dumps(obj, sort_keys=True, ensure_ascii=False, separators=(",", ":"))


def step_digest(task_id: str, step: dict) -> str:
    """What an approval is bound to: this task, this step, this kind, exactly these parameters."""
    return hashlib.sha256(canonical({"task": task_id, "step": step.get("id"), "kind": step.get("kind"), "params": step.get("params", {})}).encode()).hexdigest()


def plan_digest(plan: dict) -> str:
    return hashlib.sha256(canonical({"id": plan.get("id"), "steps": [_digest_step(s) for s in plan.get("steps", [])]}).encode()).hexdigest()


def _digest_step(s: dict) -> dict:
    d = {"id": s.get("id"), "kind": s.get("kind"), "params": s.get("params", {})}
    for k in ("needs", "when", "continue_on_error"):                 # only when present, so plans without them keep the digest they always had
        if k in s:
            d[k] = s[k]
    return d


# ---------------------------------------------------------------- audit

class Audit:
    """Append-only JSON lines; every row carries the hash of the previous row, so a changed or removed row breaks the chain."""

    def __init__(self, path=None, clock: Callable[[], float] = time.time):
        self.path = path or (cfgmod.home_dir() / "audit.jsonl")
        self.clock = clock

    def _last(self) -> str:
        if not os.path.exists(self.path):
            return "0" * 64
        last = "0" * 64
        with open(self.path, encoding="utf-8") as f:
            for line in f:
                try:
                    last = json.loads(line)["h"]
                except (ValueError, KeyError):
                    pass
        return last

    def record(self, task: str, step: str, event: str, detail: object = "") -> None:
        os.makedirs(os.path.dirname(self.path), exist_ok=True)
        row = {"t": int(self.clock()), "task": task, "step": step, "event": event, "detail": untrusted.redact(detail, 300), "prev": self._last()}
        row["h"] = hashlib.sha256(canonical(row).encode()).hexdigest()
        with open(self.path, "a", encoding="utf-8") as f:
            f.write(json.dumps(row, ensure_ascii=False) + "\n")
        try:
            os.chmod(self.path, 0o600)
        except OSError:
            pass

    def verify_chain(self) -> tuple[bool, int]:
        prev, n = "0" * 64, 0
        if not os.path.exists(self.path):
            return True, 0
        with open(self.path, encoding="utf-8") as f:
            for line in f:
                row = json.loads(line)
                h = row.pop("h")
                if row.get("prev") != prev or hashlib.sha256(canonical(row).encode()).hexdigest() != h:
                    return False, n
                prev, n = h, n + 1
        return True, n

    def rows(self, task: str | None = None) -> list[dict]:
        if not os.path.exists(self.path):
            return []
        with open(self.path, encoding="utf-8") as f:
            out = [json.loads(x) for x in f if x.strip()]
        return [r for r in out if task is None or r["task"] == task]


# ---------------------------------------------------------------- approvals

@dataclass
class Approval:
    digest: str
    expires: float
    nonce: str
    device: str
    sig: str


class Approvals:
    """Signed, expiring, single-use approvals bound to a digest and a device.  `issue()` is what a person's "yes" (desktop button, mobile PIN step-up)
    calls; a plan file cannot contain one, because the signing key never leaves this machine.  A spoken "yes" is not an approval for anything high-risk:
    the caller decides how a person confirms, this class only refuses anything not issued for the exact action."""

    def __init__(self, cfg: Config, clock: Callable[[], float] = time.time):
        self.cfg, self.clock = cfg, clock
        self.key_path = cfgmod.home_dir() / "approval.key"
        self.used_path = cfgmod.home_dir() / "approvals-used.json"

    def _key(self) -> bytes:
        if not self.key_path.exists():
            self.key_path.parent.mkdir(parents=True, exist_ok=True)
            fd = os.open(self.key_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "wb") as f:
                f.write(secrets.token_bytes(32))
        return self.key_path.read_bytes()

    def _sig(self, digest: str, expires: float, nonce: str, device: str) -> str:
        return hmac.new(self._key(), f"{digest}|{int(expires)}|{nonce}|{device}".encode(), hashlib.sha256).hexdigest()

    def issue(self, digest: str, ttl: float = APPROVAL_TTL) -> Approval:
        exp, nonce = self.clock() + min(ttl, 900.0), secrets.token_hex(12)
        return Approval(digest, exp, nonce, self.cfg.device_id, self._sig(digest, exp, nonce, self.cfg.device_id))

    def _used(self) -> set[str]:
        try:
            return set(json.loads(self.used_path.read_text(encoding="utf-8")))
        except (OSError, ValueError):
            return set()

    def consume(self, a: object, digest: str) -> None:
        """Raises guard.Refused unless `a` is a valid, unexpired, unused approval for exactly `digest` on this device.  Marks it used."""
        if not isinstance(a, Approval):
            raise guard.Refused("No approval")
        if a.digest != digest:
            raise guard.Refused("The approval is for another task (parameters changed)")
        if self.clock() >= a.expires:
            raise guard.Refused("The approval has expired")
        if a.device != self.cfg.device_id:
            raise guard.Refused("The approval is for another device")
        if not hmac.compare_digest(a.sig, self._sig(a.digest, a.expires, a.nonce, a.device)):
            raise guard.Refused("The approval signature is invalid")
        used = self._used()
        if a.nonce in used:
            raise guard.Refused("This approval was already used")
        used.add(a.nonce)
        fd, tmp = tempfile.mkstemp(dir=str(self.used_path.parent), prefix=".used-")
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(sorted(used)[-2000:], f)
        os.replace(tmp, self.used_path)


# ---------------------------------------------------------------- plan

def validate(plan: object, cfg: Config) -> dict:
    if not isinstance(plan, dict) or not isinstance(plan.get("id"), str) or not ID.fullmatch(plan["id"]):
        raise PlanError("Invalid plan id")
    steps = plan.get("steps")
    if not isinstance(steps, list) or not 1 <= len(steps) <= MAX_STEPS:
        raise PlanError(f"There must be 1–{MAX_STEPS} steps")
    seen: set[str] = set()
    for s in steps:
        if not isinstance(s, dict) or not isinstance(s.get("id"), str) or not ID.fullmatch(s["id"]) or s["id"] in seen:
            raise PlanError("Invalid or duplicate step id")
        seen.add(s["id"])
        if s.get("kind") not in actions.HANDLERS and s.get("kind") != "verify_file":
            raise PlanError(f"Unknown kind: {str(s.get('kind'))[:30]}")
        if not isinstance(s.get("params", {}), dict) or len(canonical(s.get("params", {}))) > MAX_PARAMS_BYTES:
            raise PlanError("Invalid or too large parameters")
        if not isinstance(s.get("retries", 0), int) or not 0 <= s.get("retries", 0) <= 2:
            raise PlanError("retries must be 0–2")
        needs, when = s.get("needs", []), s.get("when")
        if not isinstance(needs, list) or len(needs) > 5 or any(not isinstance(n, str) or n not in seen or n == s["id"] for n in needs):
            raise PlanError("needs must list up to 5 EARLIER step ids")
        if when is not None:
            ok = isinstance(when, dict) and isinstance(when.get("step"), str) and when["step"] in seen and when["step"] != s["id"] \
                and set(when) in ({"step", "contains"}, {"step", "not_contains"})
            key = "contains" if ok and "contains" in when else "not_contains"
            if not ok or not isinstance(when.get(key), str) or not 1 <= len(when[key]) <= 120:
                raise PlanError("when must be {step: an earlier id, contains|not_contains: 1-120 characters}")
        if "continue_on_error" in s and not isinstance(s["continue_on_error"], bool):
            raise PlanError("continue_on_error must be true or false")
        for ref in REF.findall(canonical(s.get("params", {}))):
            if ref not in seen or ref == s["id"]:
                raise PlanError(f"Step {s['id']} needs the output of a step that has not run yet: {ref}")
    return plan


@dataclass
class StepResult:
    id: str
    kind: str
    state: str = "queued"
    attempts: int = 0
    evidence: str = ""
    error: str = ""
    tainted: bool = False


@dataclass
class TaskResult:
    id: str
    state: str = "queued"
    steps: list[StepResult] = field(default_factory=list)
    summary: str = ""

    def step(self, sid: str) -> StepResult:
        return next(s for s in self.steps if s.id == sid)


class Engine:
    def __init__(self, cfg: Config, *, audit: Audit | None = None, approvals: Approvals | None = None, clock: Callable[[], float] = time.time,
                 step_timeout: float = STEP_TIMEOUT, checkpoint_dir=None):
        self.cfg, self.audit, self.clock = cfg, audit or Audit(clock=clock), clock
        self.approvals = approvals or Approvals(cfg, clock)
        self.step_timeout = step_timeout
        self.cp_dir = checkpoint_dir or (cfgmod.home_dir() / "tasks")

    # -- checkpoint (idempotency / resume)
    def _cp_path(self, task_id: str):
        return self.cp_dir / f"{task_id}.json"

    def _load_cp(self, task_id: str) -> dict:
        try:
            return json.loads(self._cp_path(task_id).read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return {}

    def _save_cp(self, task_id: str, cp: dict) -> None:
        self.cp_dir.mkdir(parents=True, exist_ok=True)
        fd, tmp = tempfile.mkstemp(dir=str(self.cp_dir), prefix=".cp-")
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(cp, f, ensure_ascii=False)
        os.replace(tmp, self._cp_path(task_id))

    # -- one step
    def _substitute(self, params: object, outputs: dict[str, tuple[str, bool]], tid: str, sid: str) -> tuple[object, bool]:
        """Replace {{sN.out}} with that step's output.  Returns (params, tainted).  Raises guard.Refused when the data looks like an injection."""
        tainted = False

        def sub(v: object) -> object:
            nonlocal tainted
            if isinstance(v, str):
                def rep(m: re.Match) -> str:
                    nonlocal tainted
                    text, from_outside = outputs[m.group(1)]
                    hits = untrusted.scan(text)
                    if hits:
                        self.audit.record(tid, sid, "injection_blocked", f"{m.group(1)}: {hits[0]}")
                        raise guard.Refused("Instruction-like text was found in the output — blocked")
                    tainted = tainted or from_outside
                    return text
                return REF.sub(rep, v)
            if isinstance(v, list):
                return [sub(x) for x in v]
            if isinstance(v, dict):
                return {k: sub(x) for k, x in v.items()}
            return v
        return sub(params), tainted

    def _act(self, kind: str, params: dict) -> tuple[bool, str]:
        if kind == "verify_file":
            return verify.check(self.cfg, params)
        return actions.execute(self.cfg, {"kind": kind, "params": params})

    def _needs_approval(self, step: dict, params: dict, tainted: bool) -> str:
        if RISK.get(step["kind"], "high") in HIGH:
            return "high risk"
        if params.get("overwrite") is True:
            return "overwrite"
        if tainted:
            return "came from external data"
        if step.get("needs_confirm") is True:
            return "requested by the plan"
        return ""

    def run(self, plan: dict, *, plan_approval: Approval | None, step_approvals: dict[str, Approval] | None = None, resume: bool = False) -> TaskResult:
        """Runs the plan.  `plan_approval` must have been issued for `plan_digest(plan)`; steps that need it also need an approval in `step_approvals`."""
        validate(plan, self.cfg)
        tid = plan["id"]
        res = TaskResult(tid, "queued", [StepResult(s["id"], s["kind"]) for s in plan["steps"]])
        step_approvals = step_approvals or {}
        try:
            self.approvals.consume(plan_approval, plan_digest(plan))
        except guard.Refused as e:
            res.state = "failed"
            for s in res.steps:
                s.state = "skipped"
            res.summary = f"The plan did not start: {e}"
            self.audit.record(tid, "", "plan_refused", str(e))
            return res
        self.audit.record(tid, "", "task_started", f"{len(plan['steps'])} steps")
        progress.begin(tid, [(x["id"], x["kind"]) for x in plan["steps"]])
        res.state = "running"
        cp = self._load_cp(tid) if resume else {}
        outputs: dict[str, tuple[str, bool]] = {}
        failed = False
        for step, sr in zip(plan["steps"], res.steps):
            sid, kind = step["id"], step["kind"]
            held = self._hold(tid, sid)
            if held or stop.is_set():
                sr.state, sr.error = "cancelled", ("Paused too long — the task was abandoned" if held == "timeout" else "")
                continue
            if failed and not step.get("continue_on_error"):
                sr.state = "skipped"
                continue
            unmet = [n for n in step.get("needs", []) if res.step(n).state not in ("completed",)]
            if unmet:
                sr.state, sr.error = "skipped", "depends on " + ", ".join(unmet) + " which did not complete"
                self.audit.record(tid, sid, "needs_unmet", ",".join(unmet))
                failed = failed or not step.get("continue_on_error")
                continue
            when = step.get("when")
            if when:
                text = outputs.get(when["step"], ("", False))[0].lower()
                hit = when.get("contains", when.get("not_contains", "")).lower() in text
                if (hit if "not_contains" in when else not hit) or when["step"] not in outputs:
                    sr.state, sr.evidence = "not_needed", f"condition on {when['step']} not met"
                    self.audit.record(tid, sid, "condition_false", when["step"])
                    continue
            if kind != "verify_file" and kind not in self.cfg.kinds:
                sr.state, sr.error, failed = "failed", "This kind is not enabled on this computer (local policy)", True
                self.audit.record(tid, sid, "blocked_policy", kind)
                continue
            try:
                params, tainted = self._substitute(step.get("params", {}), outputs, tid, sid)
            except guard.Refused as e:
                sr.state, sr.error, failed = "failed", str(e), True
                continue
            sr.tainted = tainted
            spec = step.get("verify") or ({"type": WRITES[kind], "path": params.get("dst") or params.get("path")} if kind in WRITES else
                                           {"type": "file_absent", "path": params.get("path")} if kind == "trash_file" else None)
            # resume: a step the checkpoint says is done is skipped only if its evidence still holds
            if resume and cp.get(sid, {}).get("done") and spec and verify.check(self.cfg, spec)[0]:
                sr.state, sr.evidence = "completed", "Already done; re-checked and it matches"
                outputs[sid] = (cp[sid].get("out", ""), kind in TAINTS_OUTPUT)
                self.audit.record(tid, sid, "skipped_done", sr.evidence)
                continue
            why = self._needs_approval(step, params, tainted)
            if why:
                sr.state = "awaiting_approval"
                try:
                    self.approvals.consume(step_approvals.get(sid), step_digest(tid, {"id": sid, "kind": kind, "params": params}))
                except guard.Refused as e:
                    sr.state, sr.error, failed = "failed", f"Approval needed ({why}): {e}", True
                    self.audit.record(tid, sid, "approval_denied", f"{why}: {e}")
                    continue
                self.audit.record(tid, sid, "approved", why)
            out = self._run_step(tid, step, sr, params, spec)
            if sr.state == "completed":
                outputs[sid] = (out, kind in TAINTS_OUTPUT)
                cp[sid] = {"done": True, "out": out[:2000]}
                self._save_cp(tid, cp)
            else:
                failed = failed or sr.state == "failed"
        if stop.is_set() or any(s.state == "cancelled" for s in res.steps):
            res.state = "cancelled"
            for s in res.steps:
                if s.state in ("queued", "awaiting_approval"):
                    s.state = "cancelled"
            self.audit.record(tid, "", "task_cancelled", "emergency stop" if stop.is_set() else "paused too long")
        elif all(s.state in ("completed", "not_needed") for s in res.steps):
            res.state = "completed"
            self.audit.record(tid, "", "task_completed", "")
        else:
            res.state = "failed"
            self.audit.record(tid, "", "task_failed", "")
        res.summary = self.report(res)
        for sr in res.steps:
            progress.step(sr.id, sr.state, sr.evidence or sr.error)
        progress.end(res.state)
        return res

    def _hold(self, tid: str, sid: str) -> str:
        """Waits while the task is paused.  "" = go on; "stopped" / "timeout" = do not run this step."""
        def on_pause():
            self.audit.record(tid, sid, "paused", "")
            progress.state("paused")

        def on_continue():
            self.audit.record(tid, sid, "continued", "")
            progress.state("running")
        return pause.wait(on_pause=on_pause, on_continue=on_continue)

    def _run_step(self, tid: str, step: dict, sr: StepResult, params: dict, spec: dict | None) -> str:
        kind = step["kind"]
        tries = 1 + int(step.get("retries", 0))
        out = ""
        for attempt in range(1, tries + 1):
            if attempt > 1 and self._hold(tid, step["id"]):
                sr.state = "cancelled"
                return ""
            if stop.is_set():
                sr.state = "cancelled"
                return ""
            sr.attempts, sr.state = attempt, "running"
            progress.step(step["id"], "running", f"{kind} (attempt {attempt})")
            self.audit.record(tid, step["id"], "act", f"{kind} (attempt {attempt})")
            ex = concurrent.futures.ThreadPoolExecutor(max_workers=1)
            fut = ex.submit(self._act, kind, params)
            try:
                ok, out = fut.result(timeout=self.step_timeout)
            except concurrent.futures.TimeoutError:
                ok, out = False, f"Did not finish in {self.step_timeout:.0f} seconds"
                stop.kill_children()
            finally:
                ex.shutdown(wait=False, cancel_futures=True)
            if stop.is_set():
                sr.state, sr.error = "cancelled", "emergency stop"
                return ""
            if not ok:
                sr.error = untrusted.redact(out, 300)
                self.audit.record(tid, step["id"], "act_failed", sr.error)
                if "allowed list" in out or "policy" in out:           # policy refusals are not retried
                    break
                continue
            sr.state = "verifying"
            if spec is not None:
                vok, ev = verify.check(self.cfg, spec)
                self.audit.record(tid, step["id"], "verify", f"{'ok' if vok else 'FAILED'}: {ev}")
                if not vok:
                    sr.error = "Verification failed: " + ev
                    continue
                sr.evidence = ev
            else:
                sr.evidence = untrusted.redact(out.splitlines()[0] if out else "", 200)
            sr.state, sr.error = "completed", ""
            progress.step(step["id"], "completed", sr.evidence)
            return out
        sr.state = "failed"
        progress.step(step["id"], "failed", sr.error)
        return ""

    @staticmethod
    def report(res: TaskResult) -> str:
        """Built only from step states — a task is 'completed' only when every step is verified."""
        mark = {"completed": "✓", "failed": "✗", "skipped": "–", "not_needed": "○", "cancelled": "■", "awaiting_approval": "?", "queued": "·", "running": "…", "verifying": "…"}
        lines = [f"{mark.get(s.state, '?')} {s.id} {s.kind}: " + (s.evidence if s.state == 'completed' else (s.error or s.state)) for s in res.steps]
        head = {"completed": "All steps finished and verified", "failed": "The task did not finish — some steps failed", "cancelled": "The task was stopped (emergency stop)"}.get(res.state, res.state)
        return head + "\n" + "\n".join(lines)
