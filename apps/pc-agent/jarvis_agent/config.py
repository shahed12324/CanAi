"""Local settings: where the server is, the device token, and THE LOCAL RULES (second check, independent of the server's rules)."""
from __future__ import annotations

import json
import os
import tempfile
from dataclasses import dataclass, field, asdict
from pathlib import Path

from . import secretstore

# What a freshly paired agent may do on its own machine.  Everything else must be switched on by the owner of the machine.
DEFAULT_KINDS = ["read_file", "list_dir", "write_file"]
ALL_KINDS = ["read_file", "list_dir", "write_file", "delete_file", "open_app", "browser_navigate", "run_command",
             "copy_file", "move_file", "make_dir", "trash_file", "create_xlsx", "create_docx",
             "screenshot", "input_control", "browser_extract", "window_info", "wait_window",
             "screen_read", "screen_find", "ui_click", "screen_describe", "browser_run", "make_report"]   # the last eight are local-only for now: the server does not queue them yet
MODES = ("agent", "companion")
LIST_FIELDS = {"roots", "apps", "commands", "hosts"}


def home_dir() -> Path:
    return Path(os.environ.get("JARVIS_AGENT_HOME") or (Path.home() / ".jarvis-agent"))


@dataclass
class Config:
    server: str = ""
    token: str = ""
    token_next: str = ""                                  # a replacement token made locally and saved BEFORE it is sent (rotation.py)
    device_id: str = ""
    roots: list[str] = field(default_factory=list)       # folders the agent may touch (empty = none)
    apps: list[str] = field(default_factory=list)        # program names open_app may start
    commands: list[str] = field(default_factory=list)    # program names run_command may run
    hosts: list[str] = field(default_factory=list)       # websites (and their subdomains) browser_navigate may open
    kinds: list[str] = field(default_factory=lambda: list(DEFAULT_KINDS))
    prefs: dict = field(default_factory=dict)             # desktop preferences (see prefs.py); validated on read and write
    vision_upload: bool = False                          # may screen_describe send a (secret-blacked-out) image of the front window to the AI model?  Off unless the owner turns it on
    mode: str = "agent"                                  # "companion" = this computer does nothing on request (chat/voice only); "agent" = jobs follow the rules
    token_unreadable: bool = False                       # in memory only: the saved token was protected for another Windows user/PC and could not be opened

    @property
    def paired(self) -> bool:
        return bool(self.server and self.token)


def path() -> Path:
    return home_dir() / "config.json"


def load() -> Config:
    p = path()
    if not p.exists():
        return Config()
    raw = json.loads(p.read_text(encoding="utf-8"))
    base = Config()
    for k, v in raw.items():
        if hasattr(base, k):
            setattr(base, k, v)
    base.token_unreadable = False
    for f in ("token", "token_next"):
        val, ok = secretstore.unprotect(getattr(base, f))
        setattr(base, f, val)
        if not ok:
            base.token_unreadable = True
    base.kinds = [k for k in base.kinds if k in ALL_KINDS]
    base.vision_upload = base.vision_upload is True
    if base.mode not in MODES:
        base.mode = "companion"                          # unknown value: the safe side
    return base


def save(c: Config) -> None:
    d = home_dir()
    d.mkdir(parents=True, exist_ok=True)
    try:
        os.chmod(d, 0o700)
    except OSError:
        pass
    fd, tmp = tempfile.mkstemp(dir=str(d), prefix=".cfg-")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            data = asdict(c)
            data.pop("token_unreadable", None)
            for k in ("token", "token_next"):
                data[k] = secretstore.protect(data.get(k, ""))
            json.dump(data, f, ensure_ascii=False, indent=2)
        try:
            os.chmod(tmp, 0o600)                         # the token is a password
        except OSError:
            pass
        os.replace(tmp, path())
    except Exception:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise
