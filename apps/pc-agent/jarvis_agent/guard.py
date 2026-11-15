"""The agent's OWN checks.  The server already decided, but this machine decides again: a wrong, old or forged answer from anywhere must not be enough."""
from __future__ import annotations

import os
import re
import shutil
from urllib.parse import urlsplit

CTRL = re.compile(r"[\x00-\x1f\x7f]")
MAX_PATH = 520


class Refused(Exception):
    """This machine's rules say no.  The message is safe to send back to the server."""


# Windows rules, applied on EVERY platform (a plan written for a Windows PC must not rely on how this machine happens to parse names):
_WIN_RESERVED = re.compile(r"^(con|prn|aux|nul|conin\$|conout\$|com[0-9\u00b9\u00b2\u00b3]|lpt[0-9\u00b9\u00b2\u00b3])$", re.I)
_WIN_BAD_CHARS = re.compile(r'[<>"|?*]')

# Programs that can run ANYTHING (shells, interpreters, script hosts) or that administer the system.  They can never go on the "commands" / "apps" list,
# even if the owner asks, and are refused again at run time if a config file was edited by hand.  A narrow program (git, ffmpeg…) is the safe choice.
DANGEROUS_PROGRAMS = frozenset("""
cmd command powershell pwsh powershell_ise bash sh zsh fish dash ksh csh tcsh wsl wsl bash.exe
python python2 python3 pythonw py pyw perl ruby php lua node nodejs deno bun java javaw
wscript cscript mshta rundll32 regsvr32 regsvcs regasm installutil msbuild cmstp odbcconf forfiles pcalua
reg regedit regedt32 net net1 sc schtasks at wmic wevtutil bcdedit bcdboot diskpart format fsutil vssadmin cipher takeown icacls cacls attrib
shutdown logoff taskkill tskill runas psexec msiexec certutil bitsadmin curl wget ssh scp sftp ftp tftp telnet nc ncat netcat socat
sudo su doas chmod chown mount umount dd mkfs fdisk systemctl service crontab launchctl osascript
rm rmdir del erase rd mv move xcopy robocopy
""".split())


def program_base(name: str) -> str:
    n = name.lower()
    for ext in (".exe", ".com", ".bat", ".cmd", ".ps1", ".vbs", ".js", ".msc"):
        if n.endswith(ext):
            n = n[: -len(ext)]
    return n


def is_dangerous_program(name: object) -> bool:
    if not isinstance(name, str):
        return True
    b = program_base(name.strip())
    return b in DANGEROUS_PROGRAMS or bool(re.fullmatch(r"python[0-9.]*|pip[0-9.]*|pypy[0-9]*|node[0-9.]*|ruby[0-9.]*|perl[0-9.]*|php[0-9.]*", b))


def _inside(real: str, root_real: str) -> bool:
    a, b = os.path.normcase(real), os.path.normcase(root_real)
    return a == b or a.startswith(b.rstrip(os.sep) + os.sep)


def _real_roots(roots: list[str]) -> list[str]:
    out = []
    for r in roots:
        rr = os.path.realpath(r)
        if os.path.dirname(rr) == rr:                    # "/" or "C:\" : the whole drive is never a valid folder
            continue
        out.append(rr)
    return out


def clean_path(p: object) -> str:
    if not isinstance(p, str) or p == "" or len(p) > MAX_PATH or CTRL.search(p):
        raise Refused("Invalid path")
    if ".." in re.split(r"[\\/]+", p):
        raise Refused("'..' is not allowed in a path")
    if not os.path.isabs(p):
        raise Refused("An absolute path is required")
    if p.startswith(("\\\\?\\", "\\\\.\\", "//?/", "//./")):
        raise Refused("Device and extended-length paths are not allowed")
    body = re.sub(r"^[A-Za-z]:", "", p, count=1) if re.match(r"^[A-Za-z]:[\\/]", p) else p
    if ":" in body or _WIN_BAD_CHARS.search(body):
        raise Refused("A path may not contain : < > \" | ? * (alternate streams, wildcards)")
    for part in re.split(r"[\\/]+", body):
        if part and (_WIN_RESERVED.match(part.split(".")[0].strip()) or part != part.rstrip(" .") and part not in (".", "..")):
            raise Refused("This name is reserved or ends with a dot/space")
    return p


def path_in_roots(p: object, roots: list[str]) -> str:
    """Resolve symlinks and return the real path, only if it is inside an allowed folder (after resolving)."""
    p = clean_path(p)
    real = os.path.realpath(p)
    for rr in _real_roots(roots):
        if _inside(real, rr):
            return real
    raise Refused("This folder is not on this computer's allowed list")


def parent_in_roots(p: object, roots: list[str]) -> tuple[str, str]:
    """For delete: the folder must be allowed; the name itself is NOT resolved (a symlink is removed, never followed). Returns (real parent, name)."""
    p = clean_path(p)
    name = os.path.basename(p.rstrip("\\/"))
    if name in ("", ".", ".."):
        raise Refused("Invalid path")
    parent = path_in_roots(os.path.dirname(p.rstrip("\\/")) or os.sep, roots)
    return parent, name


def program_ok(name: object, allowed: list[str]) -> str:
    """Program NAME only (no folders, no spaces, no shell characters) and on this machine's list.  Returns the executable path."""
    if not isinstance(name, str) or not re.fullmatch(r"[A-Za-z0-9._+-]{1,60}", name):
        raise Refused("Invalid program name")
    if is_dangerous_program(name):
        raise Refused("Shells, interpreters and system tools can never be run by a task")
    if name.lower() not in {a.lower() for a in allowed}:
        raise Refused("This program is not on this computer's allowed list")
    # Look only in absolute PATH folders and never in the current folder (a planted program next to the agent must not win).
    safe_path = os.pathsep.join(d for d in os.environ.get("PATH", "").split(os.pathsep) if d and os.path.isabs(d))
    exe = shutil.which(name, path=safe_path)
    if exe is None:
        raise Refused("The program was not found on this computer")
    exe = os.path.abspath(exe)
    if os.path.dirname(os.path.normcase(exe)) == os.path.normcase(os.path.abspath(os.getcwd())) and os.path.normcase(os.getcwd()) not in [os.path.normcase(d) for d in safe_path.split(os.pathsep)]:
        raise Refused("The program was found only in the current folder; refusing")
    return exe


def args_ok(args: object) -> list[str]:
    if args is None:
        return []
    if not isinstance(args, list) or len(args) > 20 or not all(isinstance(a, str) and len(a) <= 200 and not CTRL.search(a) for a in args):
        raise Refused("Invalid argument")
    return list(args)


def url_ok(url: object, hosts: list[str]) -> str:
    if not isinstance(url, str) or len(url) > 2000 or CTRL.search(url) or re.search(r"\s", url):
        raise Refused("Invalid address")
    u = urlsplit(url)
    if u.scheme.lower() != "https" or not u.hostname or u.username or u.password or "@" in u.netloc:
        raise Refused("Only https addresses (without a username)")
    host = u.hostname.lower()
    for h in hosts:
        h = h.lower().strip(".")
        if h and (host == h or host.endswith("." + h)):
            return url
    raise Refused("This site is not on this computer's allowed list")
