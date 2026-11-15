"""Plans approved on the website/phone, handed to this agent with a signature.

The database signs with HMAC-SHA256 using sha256(token) as the key (only this agent and the database know the token).  We check the signature over the
EXACT text we were sent, then execute that text and nothing else.  A verified plan is turned into this machine's own approvals, so the engine's rules
(local policy second check, per-step approval, verification, audit, emergency stop) apply unchanged.  Nothing from the network is ever trusted without a signature."""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import time
from pathlib import Path
from typing import Callable

from . import taskengine
from .config import Config, home_dir

MAX_VALID = 600           # a signature that claims to be good for more than 10 minutes is refused
SKEW = 5


class Rejected(Exception):
    pass


def _sha(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def _mac(token: str, message: str) -> str:
    key = hashlib.sha256(token.encode("utf-8")).hexdigest().encode("ascii")
    return hmac.new(key, message.encode("utf-8"), hashlib.sha256).hexdigest()


def _used_path() -> Path:
    return home_dir() / "plans-used.json"


def _load_used() -> list[str]:
    try:
        v = json.loads(_used_path().read_text(encoding="utf-8"))
        return [str(x) for x in v] if isinstance(v, list) else []
    except (OSError, ValueError):
        return []


def _remember(pid: str) -> None:
    used = (_load_used() + [pid])[-500:]
    p = _used_path()
    p.parent.mkdir(parents=True, exist_ok=True)
    tmp = p.with_suffix(".tmp")
    tmp.write_text(json.dumps(used), encoding="utf-8")
    os.replace(tmp, p)


def verify_pull(cfg: Config, pulled: dict, *, now: Callable[[], float] = time.time) -> tuple[dict, dict[str, taskengine.Approval], taskengine.Approval]:
    """Returns (plan, step_approvals, plan_approval) minted locally, or raises Rejected.  The plan id is reserved so it can never run twice."""
    try:
        pid, dev, text, exp = str(pulled["id"]), str(pulled["device_id"]), pulled["plan_text"], int(pulled["exp"])
        plan_sig, step_sigs, step_texts = str(pulled["plan_sig"]), pulled.get("step_sigs") or {}, pulled.get("step_texts") or {}
    except (KeyError, TypeError, ValueError):
        raise Rejected("The server reply is not in the right format") from None
    if not isinstance(text, str) or not isinstance(step_sigs, dict) or not isinstance(step_texts, dict):
        raise Rejected("The server reply is not in the right format")
    if not cfg.token or not cfg.device_id or dev != cfg.device_id:
        raise Rejected("The plan is for another device")
    t = now()
    if exp < t - SKEW:
        raise Rejected("The signature has expired")
    if exp > t + MAX_VALID:
        raise Rejected("The signature validity is suspiciously long")
    if not hmac.compare_digest(plan_sig, _mac(cfg.token, f"canai-plan|{dev}|{pid}|{_sha(text)}|{exp}")):
        raise Rejected("The plan signature does not match")
    if pid in _load_used():
        raise Rejected("This plan was already taken")
    try:
        body = json.loads(text)
    except ValueError:
        raise Rejected("The plan is not JSON") from None
    if not isinstance(body, dict) or not isinstance(body.get("steps"), list):
        raise Rejected("The plan structure is invalid")
    plan = {"id": "p" + pid, "steps": body["steps"]}
    try:
        taskengine.validate(plan, cfg)
    except taskengine.PlanError as e:
        raise Rejected(f"The plan is not valid: {e}") from None
    by_id = {s["id"]: s for s in plan["steps"]}
    eng_ap = taskengine.Approvals(cfg)
    step_ok: dict[str, taskengine.Approval] = {}
    for sid, sig in step_sigs.items():
        stext = step_texts.get(sid)
        if sid not in by_id or not isinstance(stext, str) or not isinstance(sig, str):
            raise Rejected(f"Step {str(sid)[:24]} has an invalid signature or text")
        if not hmac.compare_digest(sig, _mac(cfg.token, f"canai-step|{dev}|{pid}|{sid}|{_sha(stext)}|{exp}")):
            raise Rejected(f"Step {sid} signature does not match")
        try:
            same = json.loads(stext) == by_id[sid]
        except ValueError:
            same = False
        if not same:
            raise Rejected(f"Step {sid} text does not match the plan")      # what was signed must be exactly what will run
        step_ok[sid] = eng_ap.issue(taskengine.step_digest(plan["id"], by_id[sid]))
    _remember(pid)
    return plan, step_ok, eng_ap.issue(taskengine.plan_digest(plan))


def run_pulled(cfg: Config, pulled: dict, *, now: Callable[[], float] = time.time) -> tuple[str, str]:
    """Verify, run through the engine, and return (state for the server, short result text)."""
    try:
        plan, step_ok, plan_ap = verify_pull(cfg, pulled, now=now)
    except Rejected as e:
        return "failed", f"The plan was not run: {e}"
    res = taskengine.Engine(cfg).run(plan, plan_approval=plan_ap, step_approvals=step_ok)
    state = {"completed": "done", "cancelled": "stopped"}.get(res.state, "failed")
    return state, res.summary
