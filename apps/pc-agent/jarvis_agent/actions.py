"""The jobs themselves.  Every job: local kind switch -> local guard -> do it without a shell -> short plain-text result (the server keeps at most 2000 characters)."""
from __future__ import annotations

import os
import subprocess
import threading
import time
import tempfile
import webbrowser

from . import browserauto, browserx, report, docs, fileops, guard, inputctl, observe, screen, stop, vision
from .config import Config

RESULT_MAX = 1900
READ_MAX_BYTES = 16384
WRITE_MAX_BYTES = 8000
LIST_MAX = 100
MAX_OUTPUT = 1024 * 1024                                         # bytes of stdout+stderr kept from one command
COMMAND_TIMEOUT = 30


def _cut(text: str) -> str:
    return text if len(text) <= RESULT_MAX else text[:RESULT_MAX] + "\n…(truncated)"


def read_file(cfg: Config, p: dict) -> str:
    real = guard.path_in_roots(p.get("path"), cfg.roots)
    if not os.path.isfile(real):
        raise guard.Refused("Not a regular file, or not found")
    with open(real, "rb") as f:
        raw = f.read(READ_MAX_BYTES + 1)
    text = raw[:READ_MAX_BYTES].decode("utf-8", errors="replace")
    return _cut(text + ("\n…(large file, first part only)" if len(raw) > READ_MAX_BYTES else ""))


def list_dir(cfg: Config, p: dict) -> str:
    real = guard.path_in_roots(p.get("path"), cfg.roots)
    if not os.path.isdir(real):
        raise guard.Refused("Not a folder, or not found")
    names = sorted(os.listdir(real), key=str.lower)
    lines = [n + ("/" if os.path.isdir(os.path.join(real, n)) else "") for n in names[:LIST_MAX]]
    if len(names) > LIST_MAX:
        lines.append(f"…{len(names) - LIST_MAX} more")
    return _cut("\n".join(lines) if lines else "(empty folder)")


def write_file(cfg: Config, p: dict) -> str:
    content = p.get("content")
    if not isinstance(content, str) or content == "" or "\x00" in content:
        raise guard.Refused("Invalid content to write")
    data = content.encode("utf-8")
    if len(data) > WRITE_MAX_BYTES:
        raise guard.Refused("Content too large")
    real = guard.path_in_roots(p.get("path"), cfg.roots)
    if os.path.isdir(real):
        raise guard.Refused("This is a folder, not a file")
    folder = os.path.dirname(real)
    if not os.path.isdir(folder):
        raise guard.Refused("The folder does not exist")
    fd, tmp = tempfile.mkstemp(dir=folder, prefix=".jarvis-")    # write beside it, then swap: a half-written file never replaces the old one
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
        os.replace(tmp, real)
    except Exception:
        if os.path.exists(tmp):
            os.unlink(tmp)
        raise
    return f"Written ({len(data)} bytes)"


def delete_file(cfg: Config, p: dict) -> str:
    parent, name = guard.parent_in_roots(p.get("path"), cfg.roots)
    target = os.path.join(parent, name)
    if os.path.isdir(target) and not os.path.islink(target):
        raise guard.Refused("Folders cannot be deleted (files only)")
    if not os.path.lexists(target):
        raise guard.Refused("File not found")
    os.remove(target)                                            # a symlink is removed itself, never followed
    return "Deleted"


def open_app(cfg: Config, p: dict) -> str:
    exe = guard.program_ok(p.get("app"), cfg.apps)
    subprocess.Popen([exe], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, close_fds=True, start_new_session=(os.name != "nt"))
    return "App started"


def browser_navigate(cfg: Config, p: dict) -> str:
    url = guard.url_ok(p.get("url"), cfg.hosts)
    if not webbrowser.open(url):
        raise guard.Refused("Could not open any browser on this computer")
    return "Opened in the browser"


def run_command(cfg: Config, p: dict) -> str:
    exe = guard.program_ok(p.get("command"), cfg.commands)
    args = guard.args_ok(p.get("args"))
    cwd = next((r for r in cfg.roots if os.path.isdir(r)), None)
    proc = subprocess.Popen([exe, *args], shell=False, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd=cwd, start_new_session=(os.name != "nt"))
    stop.register(proc)                                          # the emergency stop kills it (and its group) wherever it is
    bufs = {"out": bytearray(), "err": bytearray()}
    over = threading.Event()

    def drain(stream, key):                                      # read at most MAX_OUTPUT bytes in total; a flood stops the command instead of filling memory
        try:
            while True:
                chunk = stream.read(8192)
                if not chunk:
                    return
                room = MAX_OUTPUT - len(bufs["out"]) - len(bufs["err"])
                if room > 0:
                    bufs[key] += chunk[:room]
                if len(chunk) > room:
                    over.set()
        except (OSError, ValueError):
            return
    readers = [threading.Thread(target=drain, args=(proc.stdout, "out"), daemon=True), threading.Thread(target=drain, args=(proc.stderr, "err"), daemon=True)]
    for r in readers:
        r.start()
    deadline = time.monotonic() + COMMAND_TIMEOUT
    try:
        while proc.poll() is None:
            if over.is_set():
                stop.kill_children()
                break
            if time.monotonic() > deadline:
                stop.kill_children()
                proc.wait(5)
                raise guard.Refused(f"Did not finish in {COMMAND_TIMEOUT} seconds; stopped")
            time.sleep(0.05)
        proc.wait(5)
    finally:
        stop.unregister(proc)
        for r in readers:
            r.join(2)
    if stop.is_set():
        raise guard.Refused("Stopped by the emergency stop")
    stdout, stderr = bytes(bufs["out"]), bytes(bufs["err"])
    out = (stdout + (b"\n" + stderr if stderr else b"")).decode("utf-8", errors="replace").strip()
    if over.is_set():
        out = f"[output limit of {MAX_OUTPUT // 1024} KB reached; the command was stopped]\n" + out
    return _cut(f"exit={proc.returncode}\n{out}")


HANDLERS = {
    "read_file": read_file, "list_dir": list_dir, "write_file": write_file, "delete_file": delete_file,
    "open_app": open_app, "browser_navigate": browser_navigate, "run_command": run_command,
    "copy_file": fileops.copy_file, "move_file": fileops.move_file, "make_dir": fileops.make_dir, "trash_file": fileops.trash_file,
    "create_xlsx": docs.create_xlsx, "create_docx": docs.create_docx,
    "screenshot": screen.screenshot, "input_control": inputctl.input_control, "browser_extract": browserx.browser_extract,
    "window_info": observe.window_info, "wait_window": observe.wait_window,
    "browser_run": browserauto.run, "make_report": report.make_report, "screen_read": vision.screen_read, "screen_find": vision.screen_find, "ui_click": vision.ui_click, "screen_describe": vision.screen_describe,
}


def execute(cfg: Config, action: dict) -> tuple[bool, str]:
    """Never raises: every outcome is (ok, short text) for the report."""
    kind = action.get("kind")
    params = action.get("params")
    if not isinstance(kind, str) or not isinstance(params, dict):
        return False, "Invalid task data"
    if kind not in HANDLERS:
        return False, "This agent version does not support this kind of task"
    if cfg.mode != "agent":
        return False, "Companion mode is on — no tasks run on this computer (switch to Agent mode)"
    if stop.is_set():
        return False, "Emergency stop is on — no tasks will run (resume with jarvis-agent resume)"
    if kind not in cfg.kinds:
        return False, "This kind of task is disabled on this computer (enable it in the agent policy)"
    try:
        return True, HANDLERS[kind](cfg, params)
    except guard.Refused as e:
        return False, f"Rejected by this computer's policy: {e}"
    except OSError as e:
        return False, f"Error: {e.strerror or type(e).__name__}"
    except Exception as e:                                       # a bug must not kill the agent or leak a traceback
        return False, f"Unexpected error: {type(e).__name__}"
