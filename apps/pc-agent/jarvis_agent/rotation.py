"""Device-token rotation.  The agent makes the new token itself, SAVES it as `token_next`, and only then asks the server to switch (authenticated with the
old token).  So whatever goes wrong - the answer is lost, the PC loses power, the server hiccups - one of the two tokens is always known to both sides:
  * server switched, answer lost  -> next time the new token is tried first and adopted;
  * server did not switch         -> the old token still works and the same new token is simply offered again.
A revoked device cannot rotate: the old token is already dead and so is any new one."""
from __future__ import annotations

import secrets
from typing import Callable

from . import config as cfgmod
from .client import AuthError, Client, ServerError
from .config import Config


class RotationFailed(Exception):
    pass


def _client(cfg: Config, token: str, factory: Callable, allow_http: bool):
    return factory(cfg.server, token, allow_http)


def _promote(cfg: Config) -> None:
    cfg.token, cfg.token_next = cfg.token_next, ""
    cfgmod.save(cfg)


def adopt_pending(cfg: Config, factory: Callable = Client, allow_http: bool = False) -> bool:
    """If a rotation was started earlier, find out whether the server completed it.  True = the new token is now the token."""
    if not cfg.token_next or not cfg.paired:
        return False
    try:
        _client(cfg, cfg.token_next, factory, allow_http).emergency()          # any authenticated call: does the NEW token work?
    except AuthError:
        return False                                                           # the server never switched; the old token is still the one
    except ServerError:
        return False                                                           # cannot tell right now; try again later
    _promote(cfg)
    return True


def rotate(cfg: Config, factory: Callable = Client, allow_http: bool = False, make: Callable[[], str] = lambda: secrets.token_hex(32)) -> str:
    """Returns "rotated" or "recovered" (an earlier attempt had already succeeded).  Raises RotationFailed with a plain reason."""
    if not cfg.paired:
        raise RotationFailed("This computer is not paired")
    if adopt_pending(cfg, factory, allow_http):
        return "recovered"
    cfg.token_next = cfg.token_next or make()
    cfgmod.save(cfg)                                                           # written down BEFORE the server hears about it
    try:
        _client(cfg, cfg.token, factory, allow_http).rotate(cfg.token_next)
    except AuthError:
        raise RotationFailed("The server no longer accepts this device (removed on the web?) — pair again") from None
    except ServerError as e:
        if e.status == 409:
            cfg.token_next = ""                                                # refused (e.g. clash): throw the candidate away
            cfgmod.save(cfg)
            raise RotationFailed("The server refused to rotate the token") from None
        try:                                                                   # the switch may have happened with the answer lost: check
            if adopt_pending(cfg, factory, allow_http):
                return "recovered"
        finally:
            pass
        raise RotationFailed(f"Could not reach the server ({e}) — try again later; the old token still works") from None
    _promote(cfg)
    return "rotated"
