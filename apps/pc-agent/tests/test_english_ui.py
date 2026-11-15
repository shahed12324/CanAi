"""The agent's own UI and messages are English-only (Phase 11).

Bengali stays only where it is *functional*: the voice language choice and speech
recognition/stop/wake word lists, lip-sync letter tables, language detection, and the
detection patterns that spot prompt-injection or sensitive windows written in Bengali.
"""
import pathlib, re

ROOT = pathlib.Path(__file__).resolve().parent.parent / "jarvis_agent"
BN = re.compile("[ঀ-৿]")

# file -> predicate for lines where Bengali is allowed
ALLOWED = {
    "ui/voice-session.js": lambda l: True,                       # wake / stop word lists
    "ui/character.js": lambda l: True,                           # viseme letter tables
    "screen.py": lambda l: "private browsing" in l or "bkash" in l,             # sensitive-window title patterns
    "untrusted.py": lambda l: l.lstrip().startswith(("r\"", "(re.compile(r\"")),    # injection patterns written in Bengali
    "ui/voice.js": lambda l: "বাংলা" in l or "ঀ" in l,     # voice language option, language detection
}


def test_no_bengali_in_ui_or_messages():
    bad = []
    for p in list(ROOT.glob("*.py")) + list((ROOT / "ui").glob("*")):
        if p.suffix not in (".py", ".js", ".html", ".css"):
            continue
        rel = p.relative_to(ROOT).as_posix()
        ok = ALLOWED.get(rel)
        for n, line in enumerate(p.read_text(encoding="utf-8").splitlines(), 1):
            if BN.search(line) and not (ok and ok(line)):
                bad.append(f"{rel}:{n}: {line.strip()[:80]}")
    assert not bad, "Bengali text left in English-only UI/messages:\n" + "\n".join(bad)


def test_bengali_voice_is_still_offered():
    js = (ROOT / "ui/voice.js").read_text(encoding="utf-8")
    assert "'bn-BD'" in js and "বাংলা" in js


def test_ui_has_no_language_switch():
    js = (ROOT / "ui/app.js").read_text(encoding="utf-8")
    assert "data-pref=language" not in js and "LANG = 'en'" in js


def test_old_language_pref_in_config_does_not_break():
    from jarvis_agent import prefs
    assert prefs.clean("language", "bn") == "bn"
    assert prefs.DEFAULTS["language"] == "en"
