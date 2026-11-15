"""Protect the device token at rest (Phase 13).

Windows: the token is encrypted with DPAPI (CryptProtectData) for the current Windows user, so a copy of config.json taken from the disk, a backup, a
sync folder or another Windows account cannot use it.  The file then holds `dpapi1:<base64>` instead of the token.
Other systems: the file keeps the token as before (0600 file in a 0700 folder) - there is no OS key store that works the same everywhere without extra packages.

Honest limits: malware running AS THE SAME Windows user can ask DPAPI to decrypt it too, so this is protection against copied files and other accounts,
not against a compromised session.  The Windows code path has NEVER run on Windows (the tests use a fake backend).  If a protected token cannot be decrypted
(config copied to another PC/account) it is treated as 'not paired' - the owner pairs again; nothing crashes and nothing is guessed."""
from __future__ import annotations

import base64
import os
import sys

PREFIX = "dpapi1:"
_ENTROPY = b"canai-agent-device-token"


class _Dpapi:
    """ctypes wrapper around crypt32.  Only constructed on Windows."""
    def __init__(self):
        import ctypes
        from ctypes import wintypes

        class BLOB(ctypes.Structure):
            _fields_ = [("cbData", wintypes.DWORD), ("pbData", ctypes.POINTER(ctypes.c_char))]
        self.ct, self.BLOB = ctypes, BLOB
        self.crypt32 = ctypes.windll.crypt32
        self.kernel32 = ctypes.windll.kernel32

    def _blob(self, data: bytes):
        buf = self.ct.create_string_buffer(data, len(data))
        return self.BLOB(len(data), self.ct.cast(buf, self.ct.POINTER(self.ct.c_char))), buf

    def _run(self, fn, data: bytes) -> bytes:
        inp, keep = self._blob(data)
        ent, keep2 = self._blob(_ENTROPY)
        out = self.BLOB()
        if not fn(self.ct.byref(inp), None, self.ct.byref(ent), None, None, 0x1, self.ct.byref(out)):   # 0x1 = CRYPTPROTECT_UI_FORBIDDEN
            raise OSError("DPAPI call failed")
        try:
            return self.ct.string_at(out.pbData, out.cbData)
        finally:
            self.kernel32.LocalFree(out.pbData)

    def protect(self, data: bytes) -> bytes:
        return self._run(self.crypt32.CryptProtectData, data)

    def unprotect(self, data: bytes) -> bytes:
        return self._run(self.crypt32.CryptUnprotectData, data)


_backend = None
_backend_ready = False


def backend():
    """The DPAPI wrapper on Windows, otherwise None.  `JARVIS_NO_DPAPI=1` switches it off (support/debugging)."""
    global _backend, _backend_ready
    if not _backend_ready:
        _backend_ready = True
        if sys.platform == "win32" and not os.environ.get("JARVIS_NO_DPAPI"):
            try:
                _backend = _Dpapi()
            except Exception:  # noqa: BLE001
                _backend = None
    return _backend


def protect(token: str) -> str:
    """Text to store in the file.  Falls back to the plain token (file permissions only) when no OS protection is available."""
    b = backend()
    if not token or b is None or token.startswith(PREFIX):
        return token
    try:
        return PREFIX + base64.b64encode(b.protect(token.encode("utf-8"))).decode("ascii")
    except Exception:  # noqa: BLE001
        return token


def unprotect(stored: str) -> tuple[str, bool]:
    """(token, ok).  A plain value is returned as is.  A protected value that cannot be opened gives ('', False)."""
    if not isinstance(stored, str) or not stored.startswith(PREFIX):
        return (stored if isinstance(stored, str) else ""), True
    b = backend()
    if b is None:
        return "", False
    try:
        return b.unprotect(base64.b64decode(stored[len(PREFIX):], validate=True)).decode("utf-8"), True
    except Exception:  # noqa: BLE001
        return "", False
