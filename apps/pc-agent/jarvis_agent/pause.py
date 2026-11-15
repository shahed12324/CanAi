"""Pause / continue for a running task.  Not the emergency stop: a pause keeps the task and its checkpoint, it only holds the NEXT step (or retry) back until
a person says continue.  The emergency stop always wins over a pause.  Same three channels as the stop: this process, a file in the agent home (so the
CLI and the window can pause a task run by the background loop), nothing from the network."""
from __future__ import annotations

import threading
import time
from typing import Callable

from . import config as cfgmod, stop

FILE_NAME = "PAUSE"
MAX_PAUSE = 1800.0            # a task left paused for 30 minutes is given up (cancelled, honestly reported) rather than held forever
_event = threading.Event()


def flag_file():
    return cfgmod.home_dir() / FILE_NAME


def is_set() -> bool:
    return _event.is_set() or flag_file().exists()


def request(reason: str = "") -> None:
    _event.set()
    d = cfgmod.home_dir()
    d.mkdir(parents=True, exist_ok=True)
    flag_file().write_text(f"{int(time.time())} {reason[:200]}\n", encoding="utf-8")


def clear() -> None:
    _event.clear()
    try:
        flag_file().unlink()
    except FileNotFoundError:
        pass


def wait(*, poll: float = 0.25, max_wait: float | None = None, sleep: Callable[[float], None] = time.sleep, clock: Callable[[], float] = time.monotonic,
         on_pause: Callable[[], None] | None = None, on_continue: Callable[[], None] | None = None) -> str:
    """Blocks while paused.  Returns "" (not paused / continued), "stopped" (emergency stop) or "timeout" (paused too long)."""
    max_wait = MAX_PAUSE if max_wait is None else max_wait
    if not is_set():
        return ""
    if stop.is_set():
        return "stopped"
    if on_pause:
        on_pause()
    t0 = clock()
    while is_set():
        if stop.is_set():
            return "stopped"
        if clock() - t0 >= max_wait:
            return "timeout"
        sleep(poll)
    if on_continue:
        on_continue()
    return ""
