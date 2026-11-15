# Voice — hands-free ("Hey CanAI")

**Status (2026-11-14):** logic tested in Node; browser adapters and the page were NOT run in a real browser with a microphone. Treat as untested on real hardware.

## Files
- `apps/web-overlay/public/js/voice-session.js` — all decisions: wake-phrase matching (Bengali + English, one slip allowed in words ≥5 letters, phrase must be at the start), end-pointing from loudness, the state machine (off → armed → listening → thinking → speaking), barge-in, cooldown, local stop words, avatar state map. Tests: `apps/web-overlay/tests/js/voice-session.test.js` (`node tests/js/voice-session.test.js`).
- `apps/web-overlay/public/js/voice-browser.js` — Chrome/Edge adapters (SpeechRecognition for the wake phrase, MediaRecorder + AnalyserNode for one utterance, speechSynthesis for the answer). Not covered by tests.
- `resources/views/voice/index.blade.php` — the "Hands-free" panel on `/app/voice`.

## Privacy and safety rules (enforced in `voice-session.js`, tested)
1. Nothing listens until the person ticks consent and presses the button, **every visit** (never remembered).
2. While waiting for the phrase, every transcript that is not the phrase is dropped on the spot — not stored, not sent, not logged.
3. A visible "Microphone: ON" indicator follows any listening. "Stop everything" (and leaving the page) silences everything.
4. Speech is untrusted input: it arrives as `{source:'voice'}` and **can never approve anything** (`VOICE_CAN_APPROVE = false`; high-risk steps need the phone PIN, see COMPUTER_CONTROL.md). The existing voice endpoint only converses / starts the person's own no-input workflows by exact name.
5. Local stop words ("stop", "থামো", …) cut speech without a server call; the wake phrase while speaking starts a new question (barge-in).

## Honest limits
- In Chrome/Edge, `SpeechRecognition` is processed by the browser maker's service: audio leaves the computer **while listening for the phrase**. This is told to the person next to the checkbox. A truly local wake-word engine (e.g. an on-device model in the desktop app) is NOT built.
- Not built: wake word / voice in the Tauri desktop shell and the mobile apps; the avatar is not shown on the voice page (the state→expression map `avatarFor()` exists and is tested); native TTS.
- Browser TTS is used for hands-free answers (free, local). The button flow still uses the provider TTS and credits as before.
- Recognition quality for Bengali wake phrases depends on the browser; the fuzzy match only forgives one letter in longer words. Tune `DEFAULT_WAKE` with real speakers.
