"""While the agent runs something, ask the server every few seconds whether the emergency stop was pressed (website or phone) and, if so, stop at once.
A stop that cannot be checked (server unreachable) does NOT stop the job by itself: the local policy, approvals and local STOP file still apply, and an agent that cannot reach
the server cannot be handed new work anyway.  After a server stop the local STOP marker stays until a person runs `jarvis-agent resume` (a server 'release' never restarts a computer by itself)."""
from __future__ import annotations

import threading
from typing import Callable

from . import stop
from .client import AuthError, ServerError

POLL_SECONDS = 2.0


class ServerStopWatch:
    def __init__(self, client, *, interval: float = POLL_SECONDS, log: Callable[[str], None] = print, wait: Callable[[float], bool] | None = None):
        self.client = client
        self.interval = interval
        self.log = log
        self._quit = threading.Event()
        self._wait = wait or self._quit.wait
        self._t: threading.Thread | None = None
        self.fired = False
        self.errors = 0

    def _loop(self) -> None:
        while not self._quit.is_set():
            try:
                if getattr(self.client, "emergency", None) is not None and self.client.emergency():
                    self.fired = True
                    stop.request("Emergency stop from the server")
                    self.log("■ Emergency stop received from the server — running tasks stopped (resume with jarvis-agent resume)")
                    return
            except (ServerError, AuthError):
                self.errors += 1                                  # unreachable: keep going under the local rules
            except Exception:
                self.errors += 1
            if self._wait(self.interval) and self._quit.is_set():
                return

    def __enter__(self) -> "ServerStopWatch":
        self._t = threading.Thread(target=self._loop, name="server-stop-watch", daemon=True)
        self._t.start()
        return self

    def __exit__(self, *exc) -> None:
        self._quit.set()
        if self._t is not None:
            self._t.join(timeout=3)
