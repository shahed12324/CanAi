"""Text from a web page, a file, a PDF, an e-mail or the screen is DATA, never an instruction (security rule 3).

`scan()` finds instruction-like text and secrets inside such data; the task engine refuses to let flagged data flow into a later action,
and `redact()` keeps secrets out of every log line.  This is a safety net on top of the real rule — untrusted text can never change which
actions run, because the plan is fixed and approved before it starts — not a promise that every injection is recognised."""
from __future__ import annotations

import re
import unicodedata

INJECTION = [
    r"ignore (all |any )?(the )?(previous|prior|above|earlier) (instructions|rules|prompts?)",
    r"disregard (the |all )?(previous|prior|above|system)",
    r"you are now\b", r"new instructions?:", r"system prompt", r"do not (tell|inform) the user",
    r"(upload|send|post|email|e-mail|forward|exfiltrate)\b.{0,60}\b(password|passwords|secret|secrets|token|api[ _-]?key|credentials?|cookies?|ssh|private key)",
    r"\b(curl|wget|powershell|cmd\.exe|bash -c|invoke-webrequest)\b.{0,80}https?://",
    r"forget (everything|all|your) (you|previous|prior|above|instructions|rules)", r"(override|bypass|disable) (the |all |your )?(safety|security|rules|policy|restrictions|guardrails)",
    r"(reveal|show|print|repeat|leak) (me )?(your |the )?(system|hidden|initial) (prompt|instructions)", r"\b(developer|jailbreak|dan|god) mode\b",
    r"(run|execute|paste|type) (the |this |these )?(following )?(command|commands|script|code)\b", r"</?(system|assistant|developer)>|\[/?inst\]|<\|im_(start|end)\|>",
    r"\bact as (an? |the )?(admin(istrator)?|root|owner|user)\b", r"(transfer|send|wire|pay)\b.{0,40}\b(money|funds|bitcoin|btc|usd|dollars?|taka)\b", r"(approve|confirm|accept) (this|the) (request|action|plan) (for me|on my behalf)",
    r"(পূর্বের|আগের) (সব )?(নির্দেশ|নিয়ম).{0,20}(উপেক্ষা|ভুলে)", r"পাসওয়ার্ড.{0,40}(পাঠাও|আপলোড)",
]
_INJ = [re.compile(p, re.I | re.S) for p in INJECTION]

SECRETS = [
    (re.compile(r"\bsk-[A-Za-z0-9_-]{16,}"), "[key]"),
    (re.compile(r"\bBearer\s+[A-Za-z0-9._~+/=-]{16,}", re.I), "Bearer [token]"),
    (re.compile(r"(?i)\b(password|passwd|pwd|passphrase|secret|client[_-]?secret|token|access[_-]?token|refresh[_-]?token|api[_-]?key|private[_-]?key|cookie|session[_-]?id)\b[\"']?\s*[:=]\s*[\"']?[^\s\"',;}&]+"), r"\1=[secret]"),
    (re.compile(r"(?i)\bauthorization\b[\"']?\s*[:=]\s*[\"']?(?:(?:basic|bearer|digest|token)\s+)?[^\s\"',;}&]+"), "authorization=[secret]"),
    (re.compile(r"(?i)\b(password|passwd|pwd)\b[\"']?\s*(is|was)\s+\S+"), r"\1 [secret]"),
    (re.compile(r"(পাসওয়ার্ড|পাসওয়ার্ডটি|পিন)\s*[:=]?\s*\S+"), r"\1 [secret]"),
    (re.compile(r"\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{4,}"), "[jwt]"),
    (re.compile(r"\b(?:AKIA|ASIA)[0-9A-Z]{16}\b"), "[aws key]"),
    (re.compile(r"\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|xox[abprs]-[A-Za-z0-9-]{10,}|AIza[0-9A-Za-z_-]{30,}|sk_live_[0-9A-Za-z]{16,})\b"), "[key]"),
    (re.compile(r"\b(Basic|Digest|Token)\s+[A-Za-z0-9+/=._-]{16,}", re.I), r"\1 [token]"),
    (re.compile(r"(?i)\b([a-z][a-z0-9+.-]{1,15}://)[^\s/@:]+:[^\s/@]+@"), r"\1[user]:[secret]@"),
    (re.compile(r"\b[0-9a-f]{64}\b"), "[token]"),
    (re.compile(r"\b(?:\d[ -]?){13,19}\b"), "[card/number]"),
    (re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----", re.S), "[private key]"),
]


_INVISIBLE = re.compile("[\u00ad\u034f\u061c\u115f\u1160\u17b4\u17b5\u180b-\u180e\u200b-\u200f\u202a-\u202e\u2060-\u206f\u3164\ufe00-\ufe0f\ufeff\uffa0\U000e0000-\U000e007f]")


def normalise(text: str) -> str:
    """What a reader would SEE: invisible/bidi characters removed, full-width and look-alike forms folded (NFKC), case folded, whitespace collapsed.
    An instruction hidden as 'i​gnore pre­vious ｉｎｓｔｒｕｃｔｉｏｎｓ' is then found like a plain one."""
    t = _INVISIBLE.sub("", text)
    t = unicodedata.normalize("NFKC", t)
    return re.sub(r"[\s_]+", " ", t)


def scan(text: object) -> list[str]:
    """Names of the instruction-like patterns found in `text` (empty list = nothing suspicious)."""
    if not isinstance(text, str):
        return []
    t = text if len(text) <= 200_000 else text[:200_000]
    n = normalise(t)
    return [p.pattern[:40] for p in _INJ if p.search(t) or p.search(n)]


def redact(text: object, limit: int = 400) -> str:
    s = text if isinstance(text, str) else str(text)
    for rx, rep in SECRETS:
        s = rx.sub(rep, s)
    return s if len(s) <= limit else s[:limit] + "…"
