"""File jobs beyond read/write/delete (F10): copy, move, rename, make a folder, and a reversible "trash" instead of deleting.

Same rules as actions.py: every path is checked by guard.py against this machine's allowed folders (symlinks resolved, `..` refused);
nothing is overwritten without a versioned backup; writes are atomic (temp file in the same folder, then replace)."""
from __future__ import annotations

import json
import os
import shutil
import tempfile
import time

from . import guard
from .config import Config

TRASH = ".canai-trash"
BACKUP = ".canai-backup"
MAX_COPY_BYTES = 200 * 1024 * 1024


def _stamp() -> str:
    return time.strftime("%Y%m%d-%H%M%S", time.localtime()) + f"-{int((time.time() % 1) * 1000):03d}"


def _flag(p: dict, key: str) -> bool:
    return p.get(key) is True


def _src(cfg: Config, p: dict, key: str = "src") -> str:
    parent, name = guard.parent_in_roots(p.get(key), cfg.roots)
    real = os.path.join(parent, name)
    if os.path.islink(real):
        raise guard.Refused("Symlinks are not accepted")
    if not os.path.isfile(real):
        raise guard.Refused("The source is not a regular file, or was not found")
    return real


def _dst(cfg: Config, p: dict, key: str = "dst") -> str:
    parent, name = guard.parent_in_roots(p.get(key), cfg.roots)
    if name.startswith(".") and name.lower() in (TRASH, BACKUP):
        raise guard.Refused("This name is reserved")
    if not os.path.isdir(parent):
        raise guard.Refused("The destination folder does not exist")
    return os.path.join(parent, name)


def _backup(target: str) -> str:
    folder = os.path.join(os.path.dirname(target), BACKUP)
    os.makedirs(folder, exist_ok=True)
    dest = os.path.join(folder, f"{_stamp()}-{os.path.basename(target)}")
    shutil.copy2(target, dest)
    return dest


def _ready_target(dst: str, overwrite: bool) -> str:
    """Returns the backup path (or '') — refuses to touch an existing file unless the plan said overwrite, and then only after a backup."""
    if os.path.islink(dst):
        raise guard.Refused("The destination is a link")
    if os.path.isdir(dst):
        raise guard.Refused("The destination is a folder")
    if os.path.lexists(dst):
        if not overwrite:
            raise guard.Refused("A file already exists at the destination (with overwrite a backup is kept first)")
        return _backup(dst)
    return ""


def copy_file(cfg: Config, p: dict) -> str:
    src, dst = _src(cfg, p), _dst(cfg, p)
    if os.path.realpath(src) == os.path.realpath(dst):
        raise guard.Refused("Source and destination are the same")
    if os.path.getsize(src) > MAX_COPY_BYTES:
        raise guard.Refused("File too large")
    bak = _ready_target(dst, _flag(p, "overwrite"))
    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(dst), prefix=".canai-")
    os.close(fd)
    try:
        shutil.copy2(src, tmp)
        os.replace(tmp, dst)
    except Exception:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise
    return "Copied" + (f" (backup of the old one: {os.path.basename(bak)})" if bak else "")


def move_file(cfg: Config, p: dict) -> str:
    src, dst = _src(cfg, p), _dst(cfg, p)
    if os.path.realpath(src) == os.path.realpath(dst):
        raise guard.Refused("Source and destination are the same")
    bak = _ready_target(dst, _flag(p, "overwrite"))
    try:
        os.replace(src, dst)
    except OSError:                                             # another disk: copy beside the target, swap, then remove the source
        _cross_move(src, dst)
    return "Moved" + (f" (backup of the old one: {os.path.basename(bak)})" if bak else "")


def _cross_move(src: str, dst: str) -> None:
    fd, tmp = tempfile.mkstemp(dir=os.path.dirname(dst), prefix=".canai-")
    os.close(fd)
    try:
        shutil.copy2(src, tmp)
        os.replace(tmp, dst)
    except Exception:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise
    os.remove(src)


def make_dir(cfg: Config, p: dict) -> str:
    parent, name = guard.parent_in_roots(p.get("path"), cfg.roots)
    if name.lower() in (TRASH, BACKUP):
        raise guard.Refused("This name is reserved")
    target = os.path.join(parent, name)
    if os.path.lexists(target):
        raise guard.Refused("Something with this name already exists")
    os.mkdir(target)
    return "Folder created"


def trash_file(cfg: Config, p: dict) -> str:
    """Reversible delete: the file moves into `<its folder>/.canai-trash/` with a note of where it came from.  Nothing is erased."""
    parent, name = guard.parent_in_roots(p.get("path"), cfg.roots)
    target = os.path.join(parent, name)
    if os.path.basename(parent) == TRASH:
        raise guard.Refused("The file is already in the trash")
    if os.path.islink(target):
        raise guard.Refused("Links are not accepted")
    if not os.path.isfile(target):
        raise guard.Refused("File not found, or it is a folder")
    folder = os.path.join(parent, TRASH)
    os.makedirs(folder, exist_ok=True)
    stamp = _stamp()
    dest = os.path.join(folder, f"{stamp}-{name}")
    os.replace(target, dest)
    with open(os.path.join(folder, "manifest.jsonl"), "a", encoding="utf-8") as f:
        f.write(json.dumps({"t": int(time.time()), "from": target, "to": dest}, ensure_ascii=False) + "\n")
    return f"Moved to trash ({os.path.basename(dest)}); can be restored"
