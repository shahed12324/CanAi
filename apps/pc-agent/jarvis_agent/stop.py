"""Emergency stop (F13).  One switch that every long job checks.

Three ways to flip it, all with the same effect: in this process (`request()`), by a file in the agent's home folder
(so `jarvis-agent stop` from another terminal, the desktop window or a service wrapper can stop a running agent), and
through the server's emergency-stop (the runner stops pulling jobs).  While it is set, no new action starts and every
child process this agent started is killed."""
from __future__ import annotations

import os
import signal
import subprocess
import threading
import time

from . import config as cfgmod

_event = threading.Event()
_children: set[subprocess.Popen] = set()
_lock = threading.Lock()
FILE_NAME = "STOP"


def flag_file():
    return cfgmod.home_dir() / FILE_NAME


def is_set() -> bool:
    return _event.is_set() or flag_file().exists()


def request(reason: str = "") -> None:
    """Set the switch, leave a marker on disk, and kill everything this agent started."""
    _event.set()
    d = cfgmod.home_dir()
    d.mkdir(parents=True, exist_ok=True)
    flag_file().write_text(f"{int(time.time())} {reason[:200]}\n", encoding="utf-8")
    kill_children()


def clear() -> None:
    _event.clear()
    try:
        flag_file().unlink()
    except FileNotFoundError:
        pass


def register(proc: subprocess.Popen) -> None:
    with _lock:
        _children.add(proc)


def unregister(proc: subprocess.Popen) -> None:
    with _lock:
        _children.discard(proc)


def kill_children() -> int:
    """Kill every registered child (and its process group where the OS has one).  Returns how many were still alive."""
    with _lock:
        procs = list(_children)
    killed = 0
    for p in procs:
        if p.poll() is not None:
            continue
        killed += 1
        try:
            if os.name != "nt":
                os.killpg(os.getpgid(p.pid), signal.SIGKILL)
            else:
                p.kill()
        except (ProcessLookupError, PermissionError, OSError):
            try:
                p.kill()
            except OSError:
                pass
    return killed
