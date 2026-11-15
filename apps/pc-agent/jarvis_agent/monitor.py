"""Health numbers for the website's monitor.  Uses psutil when installed, else /proc on Linux; anything it cannot read is simply left out."""
from __future__ import annotations

import os
import shutil
import time


def _cpu_proc() -> float | None:
    def read() -> tuple[int, int]:
        with open("/proc/stat", encoding="utf-8") as f:
            v = [int(x) for x in f.readline().split()[1:]]
        return sum(v), v[3] + (v[4] if len(v) > 4 else 0)
    try:
        t1, i1 = read()
        time.sleep(0.2)
        t2, i2 = read()
        return round(100.0 * (1 - (i2 - i1) / (t2 - t1)), 2) if t2 > t1 else 0.0
    except (OSError, ValueError, IndexError):
        return None


def _mem_proc() -> float | None:
    try:
        d = {}
        with open("/proc/meminfo", encoding="utf-8") as f:
            for line in f:
                k, _, v = line.partition(":")
                d[k] = int(v.split()[0])
        return round(100.0 * (1 - d["MemAvailable"] / d["MemTotal"]), 2)
    except (OSError, ValueError, KeyError, ZeroDivisionError):
        return None


def _clamp(x: float | None) -> float | None:
    return None if x is None else max(0.0, min(100.0, float(x)))


def sample(interval: float | None = 0.2) -> dict | None:
    """{cpu, mem, disk, battery?, charging?}, or None when even cpu / mem / disk cannot be read (the server needs those three)."""
    cpu = mem = battery = charging = None
    try:
        import psutil  # type: ignore
        cpu, mem = psutil.cpu_percent(interval=interval), psutil.virtual_memory().percent
        b = psutil.sensors_battery()
        if b is not None:
            battery, charging = b.percent, bool(b.power_plugged)
    except Exception:
        cpu, mem = _cpu_proc(), _mem_proc()
    try:
        du = shutil.disk_usage(os.path.abspath(os.sep))
        disk = round(100.0 * du.used / du.total, 2)
    except OSError:
        disk = None
    cpu, mem, disk = _clamp(cpu), _clamp(mem), _clamp(disk)
    if cpu is None or mem is None or disk is None:
        return None
    out: dict = {"cpu": cpu, "mem": mem, "disk": disk}
    if battery is not None:
        out["battery"], out["charging"] = _clamp(battery), bool(charging)
    return out
