"""Edit the local rules.  Shared by the command line and the local window so both obey the same limits."""
from __future__ import annotations

import os

from . import guard
from .config import ALL_KINDS, Config

ACTIONS = ["add-root", "remove-root", "add-app", "remove-app", "add-command", "remove-command", "add-host", "remove-host", "enable", "disable", "vision-upload-on", "vision-upload-off"]
FIELDS = {"root": "roots", "app": "apps", "command": "commands", "host": "hosts"}


def apply(cfg: Config, action: str, value: str | None) -> None:
    """Change cfg in place.  Raises ValueError (English message) for anything not allowed."""
    if action in ("enable", "disable"):
        if value not in ALL_KINDS:
            raise ValueError("Unknown kind. Valid: " + ", ".join(ALL_KINDS))
        cfg.kinds = sorted(set(cfg.kinds) | {value} if action == "enable" else set(cfg.kinds) - {value})
        return
    if action in ("vision-upload-on", "vision-upload-off"):
        cfg.vision_upload = action.endswith("on")
        return
    verb, _, kind = str(action).partition("-")
    fieldname = FIELDS.get(kind)
    if verb not in ("add", "remove") or fieldname is None or not value or not str(value).strip():
        raise ValueError("Invalid command")
    val = os.path.abspath(str(value).strip()) if fieldname == "roots" else str(value).strip()
    if fieldname == "roots" and os.path.dirname(val) == val:
        raise ValueError("A whole drive or '/' cannot be allowed")
    if verb == "add" and fieldname in ("commands", "apps") and guard.is_dangerous_program(val):
        raise ValueError("Shells, interpreters and system tools (cmd, powershell, bash, python, curl, reg, …) can never be allowed; pick a narrow program instead")
    cur = list(getattr(cfg, fieldname))
    if verb == "add" and val not in cur:
        cur.append(val)
    if verb == "remove":
        cur = [x for x in cur if x != val]
    setattr(cfg, fieldname, cur)
