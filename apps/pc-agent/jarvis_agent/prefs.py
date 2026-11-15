"""Desktop preferences: a closed list of keys, each with a closed set of values.  Anything else is refused, never stored."""
from __future__ import annotations

SCHEMA: dict[str, tuple] = {
    "theme": ("choice", ("dark", "light")),
    "language": ("choice", ("auto", "bn", "en")),   # legacy key: the UI is English-only now; kept so old configs still validate
    "character_visible": ("bool",),
    "reduced_motion": ("choice", ("auto", "on", "off")),
    "compact": ("bool",),                      # compact floating-assistant layout (hides the sidebar)
    "always_on_top": ("bool",),                # read by the desktop shell
    "notifications": ("bool",),
    "character_color": ("choice", ("violet", "sky", "mint", "rose", "amber")),
    "character_personality": ("choice", ("friendly", "calm", "playful")),
    "character_name": ("text", 24),
    "stt_provider": ("choice", ("browser", "cloud")),   # browser = Web Speech (the browser vendor's service); cloud = your server's voice endpoint (uses credits)
    "tts_provider": ("choice", ("browser", "cloud")),
    "model_id": ("text", 40),                  # chosen AI model (from the server's list)
    "performance": ("choice", ("balanced", "low")),   # "low": slower polling, no continuous animation
    # voice (Phase C)
    "voice_lang": ("choice", ("auto", "bn-BD", "en-US")),
    "voice_reply": ("bool",),                  # speak assistant replies aloud
    "voice_rate": ("num", 0.5, 2.0),
    "voice_name": ("text", 120),
    "mic_device": ("text", 200),
    "speaker_device": ("text", 200),
    "wake_word": ("bool",),                    # OFF until the person turns it on; the microphone never opens by itself
    "push_to_talk_key": ("choice", ("Space", "F9", "F10", "Control")),
}

DEFAULTS: dict[str, object] = {
    "theme": "dark", "language": "en", "character_visible": True, "reduced_motion": "auto", "compact": False,
    "always_on_top": False, "notifications": True, "model_id": "", "character_color": "violet", "character_personality": "friendly", "character_name": "CAN", "stt_provider": "browser", "tts_provider": "browser", "performance": "balanced",
    "voice_lang": "auto", "voice_reply": False, "voice_rate": 1.0, "voice_name": "", "mic_device": "", "speaker_device": "",
    "wake_word": False, "push_to_talk_key": "F9",
}


def clean(key: object, value: object) -> object:
    """Return the value to store, or raise ValueError with a message safe to show."""
    if not isinstance(key, str) or key not in SCHEMA:
        raise ValueError("Unknown setting")
    spec = SCHEMA[key]
    kind = spec[0]
    if kind == "bool":
        if not isinstance(value, bool):
            raise ValueError("A yes/no value is required")
        return value
    if kind == "choice":
        if not isinstance(value, str) or value not in spec[1]:
            raise ValueError("The value is not in the allowed list")
        return value
    if kind == "num":
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not (spec[1] <= float(value) <= spec[2]):
            raise ValueError(f"The number must be between {spec[1]} and {spec[2]}")
        return round(float(value), 2)
    if kind == "text":
        if not isinstance(value, str) or len(value) > spec[1] or any(ord(c) < 32 for c in value):
            raise ValueError("Invalid text")
        return value.strip()
    raise ValueError("Unknown setting")


def merged(stored: object) -> dict[str, object]:
    """Defaults overlaid with whatever valid values are stored (a hand-edited bad value falls back to the default)."""
    out = dict(DEFAULTS)
    if isinstance(stored, dict):
        for k, v in stored.items():
            try:
                out[k] = clean(k, v)
            except ValueError:
                pass
    return out
