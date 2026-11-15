"""Speech helpers: which providers can do it and which audio types are accepted."""

from __future__ import annotations

import re

# Only the OpenAI "audio" wire format is spoken here (OpenAI, Groq, and compatible servers).
SPEECH_ADAPTERS = {"openai", "groq", "openai_compatible"}

# mime -> file extension the provider expects in the upload's file name
AUDIO_TYPES = {
    "audio/webm": "webm",
    "audio/ogg": "ogg",
    "audio/mpeg": "mp3",
    "audio/mp3": "mp3",
    "audio/wav": "wav",
    "audio/x-wav": "wav",
    "audio/mp4": "m4a",
    "audio/m4a": "m4a",
    "audio/x-m4a": "m4a",
    "video/webm": "webm",
    "video/mp4": "mp4",
}

MAX_AUDIO_BYTES = 6_000_000
MAX_TTS_CHARS = 4000
MAX_TTS_AUDIO_BYTES = 8_000_000
VOICE_RE = re.compile(r"\A[A-Za-z0-9_\-]{1,40}\Z")
LANG_RE = re.compile(r"\A[a-z]{2,3}(-[A-Za-z]{2,4})?\Z")


def base_mime(mime: str) -> str:
    """'audio/webm;codecs=opus' -> 'audio/webm'."""
    return mime.split(";", 1)[0].strip().lower()


def clean_text(text: str) -> str:
    out = re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", "", text or "")
    return re.sub(r"[ \t]+\n", "\n", out).strip()
