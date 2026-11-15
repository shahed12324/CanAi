# CAN AI Desktop — architecture and install path (Phase A)

Date: 2026-11-14. Honest scope: this is the audit + plan. No premium UI, voice or new automation is in this phase.

## What exists (read from source, not assumed)
```
Tauri shell (apps/desktop, 183+ lines Rust)      tray icon + one window; owns no logic
   └─ spawns:  jarvis-agent gui --no-browser --exit-with-parent
        └─ Python agent (apps/pc-agent)           local web page on 127.0.0.1 (gui.py, 294 lines: Home / Rules / History / Pairing)
             ├─ policy.py, guard.py                local rules re-checked on the PC
             ├─ taskengine.py, stop.py, verify.py  plan→approve→act→verify→report, emergency stop
             ├─ fileops.py, docs.py, inputctl.py, screen.py, browserx.py
             └─ client.py ──https──►  Laravel /api/pc/v1/*  (pairing, action queue, approvals)
```
Decision kept: **the UI is served by the local agent on 127.0.0.1**, the Tauri shell only displays it. So the premium UI (Phase B) is built as pages served by `gui.py` (or a static UI folder it serves), talking to local JSON endpoints, and to Laravel through the agent. Reasons: the agent already owns the mic/screen/mouse access and the policy; the page gets no Tauri API (capabilities empty), which is a security property worth keeping.

## Can it be installed on a desktop today?
- **Linux/dev:** yes (`pip install ./apps/pc-agent`, `cargo run`) — verified before.
- **Windows, from Python:** yes in principle (`pip install`), never run by the authors on Windows.
- **Windows installer (.exe/.msi, no Python needed):** *not built yet.* This phase adds the missing pieces:
  1. the shell now prefers an `jarvis-agent(.exe)` placed next to it (Tauri sidecar) — tested (`cargo test`, 4 pass);
  2. `apps/desktop/tauri.release.conf.json` (NSIS + MSI, sidecar `binaries/jarvis-agent`);
  3. `.github/workflows/desktop-release.yml`: on a GitHub Windows runner it builds the agent with PyInstaller, runs `--help`, bundles it as the sidecar, generates icons, builds installers and uploads them as the `windows-installer` artifact.
  The workflow has **never run**. It needs the project in a GitHub repository (Actions → desktop-release → Run workflow). Expect to read its log and fix 1–2 things on the first run. The installer is unsigned: Windows SmartScreen will warn.
- Building on this Linux sandbox is impossible: no Windows toolchain, and Tauri cannot cross-build a Windows installer from here.

## Gaps against the CAN AI Desktop spec (status words as in CAN_AI_GAP_AUDIT.md)
| Spec area | Status |
|---|---|
| 14-item sidebar, top bar, home dashboard | Missing (current UI has 3 tabs; shell page is 4 lines) |
| Mode switch Companion/Agent | Missing |
| Character in desktop | Missing here; web has `public/js/avatar-player.js` (browser only) |
| Voice, wake word | Missing on desktop; web has browser speech only |
| Mouse/keyboard/screen/browser | Code + fake-backend tests; never run on real Windows |
| Agents/Workflows from desktop | Missing (Laravel side exists) |
| Mobile approval reaching the agent | Missing (approval is at the agent terminal today) |
| Signed installer, auto-update | Missing |

## Next phases (one at a time)
B: Premium UI shell + mode model + chat/settings pages (served by gui.py, tested in headless Chrome).
C: Voice. D: Real planner + input/screen/browser verification. E: Laravel/mobile integration, approvals to the agent, revocation. F: Windows verification run + release docs.


## Phase B status (0.129.0) — premium window, honest table
Built in `apps/pc-agent/jarvis_agent/ui/` (static files served by `gui.py` on 127.0.0.1, CSP `script-src 'self'`, no inline script/style, no `innerHTML`).

| Item | Status | Evidence |
|---|---|---|
| 14-item sidebar, top bar, home dashboard | Implemented + tested | real Chrome run `tests/e2e/gui.e2e.cjs` (59 checks, 3 consecutive runs) |
| Companion/Agent switch (top bar + persisted) | Implemented + tested | e2e + `test_gui.py` |
| Local screens: Home, Computer, Browser, Files, Tools, Pairing, History, Monitor, Settings | Implemented + tested | e2e; `tests/test_desktop_ui.py` (42 tests) |
| Folder browser (allowed folders only, symlink/`..` refused) | Implemented + tested | `test_files_*` |
| Emergency stop from the window (flag file + kills child processes) | Implemented + tested on Linux | `test_emergency_stop_*`; Windows process kill untested |
| Settings: theme, language bn/en, compact layout, reduced motion, performance, notifications, character toggle | Implemented + tested | e2e; `test_prefs_*` |
| Always-on-top | Implemented (Tauri shell reads `prefs.always_on_top`), unit-tested only | `cargo test` 6 pass; never run in a real window |
| Chat, Agents, Workflows, Memory, Tools list, Approvals list, model selector, plan draft | **Client done; server side missing** (Phase E) — works against the fake server per `docs/DESKTOP_API.md`; against a real current server it shows "update the server" | e2e (fake server + `E2E_OLD_SERVER=1` run, 45 checks) |
| Approve from the window | Deliberately absent | e2e asserts no approve button |
| Character, voice, mic status | Placeholders only → Phase C | – |
| Streaming replies | Not implemented (replies arrive whole) | – |
| Windows / real desktop window / installer | Not run | needs the Windows checklist |


## Phase C status (0.130.0) — character and voice, honest table
| Item | Status | Evidence |
|---|---|---|
| Animated character with 5 states, blink, mic-level and mouth sync | Implemented + tested in Chrome | `tests/js/desktop-voice-character.test.js` (26), `voice.e2e.cjs` |
| Tap-to-talk → chat → spoken reply, state order | Implemented; **tested with a fake microphone and stubbed speech engines** | `voice.e2e.cjs` (20 checks) |
| Push-to-talk, barge-in, stop words, mute | Implemented; logic unit-tested, mute e2e-tested; key-hold with a real keyboard not run | node tests |
| Wake word "Hey CAN AI" | Implemented, off by default, consent required each visit; **never heard a real voice** | e2e gating; fuzzy match unit-tested |
| Browser STT / TTS | Depends on the engine: Chrome/Edge send audio to the vendor service; **WebView2 support unverified** | – |
| Cloud STT / TTS fallback | Client + fake-server tested; server endpoints are Phase E | e2e cloud path |
| Bengali recognition / voice quality | **Unknown** until tried on a real device | – |
| Streaming replies, true cloud-TTS lip-sync | Not implemented (wave only) | – |


## Phase D status (0.131.0) — computer automation, honest table
What already existed and is unchanged: plan → per-step signed approval → local policy second check → act → verify → report, hash-chained audit, checkpoints/resume, retries ≤ 2, emergency stop, foreground-app allowlist, sensitive-window refusal, click bounds, key allowlist, rate limit, typed text never logged.

| Item | Status | Evidence |
|---|---|---|
| Pause / continue (window, CLI, file flag), honoured between steps and retries; emergency stop wins; 30-min give-up | Implemented + tested | `tests/test_phase_d.py` (threads: nothing runs while paused, continue finishes) |
| Live progress of the running task in the window | Implemented + tested | `/api/live` tests, Chrome e2e |
| `window_info`, `wait_window`, verify `window_foreground` (GUI steps verified against the real front window) | Implemented + tested **with a fake desktop backend** | `test_phase_d.py` (17) |
| Server rules for the two kinds | Implemented + tested on PostgreSQL 16 | `tests/db/test_pc_screen_input.py` |
| "Open Notepad → type → screenshot proof" scenario | Passes on the fake backend; **never run on real Windows** | `test_open_app_then_verify_then_type_then_screenshot_scenario` |
| LLM planner drives mouse/keyboard | **Not done, on purpose** (planner v1 = file kinds only) — needs your decision | – |
| Reading text from the screen (OCR/vision), element finding | Not implemented | – |
| Browser automation beyond open-URL and read-as-text (clicking inside pages) | Not implemented | – |
| Real Windows backends (pyautogui, mss, foreground window) | Code exists, untested on Windows | Windows checklist (Phase F) |


## Phase E status (0.132.0) — integration, honest table
| Item | Status | Evidence |
|---|---|---|
| Laravel `desktop/*` endpoints (18 routes) | Written; **never booted against Laravel** | `php -l`, `tests/Unit/DesktopApiRoutesTest.php` (routes = docs table, behind device token, no approve route, always the device owner) |
| Calls into existing services (chat, agents, workflows, memory, voice, planner) | Written against their real signatures; **not exercised** | code review only |
| Token rotation (SQL + route + agent + CLI + window) | Implemented + tested (DB on PostgreSQL 16, agent vs fake server, e2e) | `tests/db/test_pc_rotation.py`, `tests/test_rotation.py` |
| Revoke (website/phone) → agent stops | Existing (phase 26/28); agent ends its loop on 401 | `test_a_really_revoked_device_still_ends_the_loop` |
| Mobile approval → agent runs it | Existing (phone PIN approval, signed plan pull) | phase 26–28 tests; window lists them read-only |
| Audit merge (local hash-chain + server) | Not done: they stay separate records | – |
| Push to the desktop when something is approved | Not done: agent polls every 3 s | – |


## Phase 1 of the v0.133.0 prompt — AI screen understanding (0.134.0), honest table
| Item | Status | Evidence |
|---|---|---|
| Front-window capture only (never whole desktop), allowed app + non-sensitive window required | Implemented | `test_vision.py::test_only_the_front_window...`, `::test_sensitive_or_unlisted_windows_are_never_captured` |
| Text recognition (Tesseract, outlines stripped so bordered buttons are read) | Implemented; real Tesseract 5.3 on drawn screens | `test_vision.py` (OCR tests) — **not tried on real Windows apps, other fonts/themes/languages** |
| Accessibility tree (UI Automation) preferred over OCR | Code written, merge/disabled logic tested with a fake tree | `test_accessibility_elements_are_preferred...` — **`_uia_elements` never run on Windows** |
| Element matching with confidence; ambiguous / unsure / missing → nothing clicked | Implemented | `test_two_identical_buttons...`, `::test_unsure_recognition...`, `::test_missing_text...` |
| Coordinate mapping (screenshot size ≠ screen area, negative monitor origin) | Implemented | `test_negative_monitor_origin_and_screen_zoom...`, `coords` unit test; `inputctl` click bounds use the virtual screen |
| Windows DPI awareness, monitor scale | Code written from documentation | **never run on Windows** |
| Window re-checked right before the click | Implemented | `test_window_that_changes_before_the_click...` |
| Post-action verification (`screen_text`, `element_present`) | Implemented | `test_post_action_verification...` |
| Privacy: secret-looking lines not returned, blacked out of any image sent to a model; upload off by default | Implemented (pattern list = guard rail) | `test_secret_looking_lines...`, `::test_describe_never_calls_a_model...` |
| Vision model description | **Not connected**: no server endpoint; `vision.model_describer` unset, local summary only | – |
| Server queue for the four kinds | Implemented, DB-tested (PostgreSQL 16) incl. rollback/roll-forward | `database/sql/phase34a.*`, `tests/db/test_pc_screen_input.py` |


## Phase 2 of the v0.133.0 prompt — advanced browser automation (0.135.0), honest table
| Item | Status | Evidence |
|---|---|---|
| Playwright automation of a whole short script as one approved step | Implemented | `tests/test_browserauto.py` (real headless Chrome, local site) |
| Host allowlist for every request and every redirect hop | Implemented | `test_requests_to_hosts_that_are_not_allowed_are_blocked`, `::test_redirect_to_another_host_is_refused` |
| Session isolation (fresh context, no cookies/downloads/permissions/pop-ups) | Implemented | `test_each_run_starts_with_an_empty_browser...`, `::test_popups_are_closed...` |
| Form fill / select / check / click / extract / wait / verify | Implemented | `test_fills_and_submits_a_form...`, `::test_verification_failure_is_reported...` |
| Never fills passwords/cards, never automates sign-in/payment pages, never clicks pay/buy/transfer | Implemented (word lists = guard rails) | `test_sign_in_pages...`, `::test_paying_buttons...` |
| Page text = outside data, flagged when instruction-like | Implemented | `test_instruction_like_page_text...` |
| Server queue + validation | Implemented, DB-tested | `phase35a.*`, `tests/db/test_pc_screen_input.py` |
| Real websites, Edge on Windows, bot-blocking sites | **Not tested** | – |


## Phase 3 of the v0.133.0 prompt — natural-language computer planner (0.136.0), honest table
| Item | Status | Evidence |
|---|---|---|
| Planner covers files, reports, apps, screen, keyboard, browser | Implemented; strict schemas, allowed apps/hosts/folders | `ai-engine/tests/test_planner_computer.py` (52) |
| Never offered to a model: run_command, power, system_setting, delete_file; money/sign-in/secret steps refused | Implemented | same + `test_planner.py` |
| Hidden instruction in data cannot widen the plan | Validation refuses it before anything runs | `test_planner_acceptance.py::test_a_model_that_obeys_text_inside_the_data...` |
| Dependencies / conditions / continue-on-error | Implemented in planner, device engine, digest | `test_phase3.py`, acceptance |
| Excel report from a local sample dataset | Implemented (`make_report`), numbers computed locally and checked | `test_goal_to_excel_report_with_exact_numbers` |
| Policies never bypassed | Device + SQL re-check every step; high-risk steps still need website/phone approval | DB tests, `test_phase_d.py` |
| Real model producing good plans | **Not tested** (mock replies) | – |
| Laravel passes apps/hosts | Written; unit-tested with a fake engine; **not run on a booted Laravel** | `PcPlannerTest.php` |

## Phase 4 of the v0.133.0 prompt — streaming chat (0.137.0), honest table

| Part | State | Evidence |
|---|---|---|
| SSE endpoint `chat/stream` (reuses ChatService, credits once) | written, `php -l` + route test only | NOT run on booted Laravel |
| Agent relay `/api/cloud/chat/stream` | done | `tests/test_streaming.py` (6) |
| UI streaming, Stop, fallback | done | gui e2e 68 (Chrome, fake server) |
| Progressive TTS | not done | speaks after full reply |

## Phase 5 of the v0.133.0 prompt — voice robustness (0.138.0), honest table

| Part | State | Evidence |
|---|---|---|
| Broken speech engine remembered, cloud fallback | done | node 27, voice e2e 25 |
| Credits notice on automatic cloud fallback | done | voice e2e |
| Voice diagnostics report | done | node + e2e |
| Real mic / WebView2 permission behaviour | NOT verified | needs Windows (checklist E6) |

## Phase 6 of the v0.133.0 prompt — lip sync and floating window (0.139.0), honest table

| Part | State | Evidence |
|---|---|---|
| Mouth from real cloud-audio level | done | node 28, voice e2e 27 (injected levels) |
| Compact = small window (Tauri) | done in code | cargo 7 (pure functions only); real resize NOT verified |
| Always on top | existed (v0.1xx) | cargo; real behaviour NOT verified |

## Phase 7 of the v0.133.0 prompt — Windows validation harness (0.140.0), honest table

| Part | State | Evidence |
|---|---|---|
| Harness logic (what is checked, failure detection, honest labelling) | done | `test_validate.py` (7) |
| TkProbe test window | written, never run | no tkinter/display in the sandbox |
| Real Windows result | NOT available | needs the user to run `3-VALIDATE.bat` |

## Phase 8 of the v0.133.0 prompt — Laravel integration (0.141.0), honest table

| Part | State | Evidence |
|---|---|---|
| persona/source used server-side (both chat endpoints) | done in code | `ReplyStyleTest` (mini runner), `php -l` |
| Migration up/down consistency | done | `test_desktop_migrations_files.py` + 154 DB tests |
| Run on a booted Laravel / real model | NOT verified | Composer blocked; needs deploy (E5, E9) |

## Phase 9 of the v0.133.0 prompt — phone approval and push (0.142.0), honest table

| Part | State | Evidence |
|---|---|---|
| Immediate generic push for a waiting plan/action | done in code | `ApprovalAlertTest` (mini runner) |
| Phone refresh: push-triggered, foreground, polling fallback, back-off | done | vitest 38, tsc clean |
| Tap push -> My Computer | done | `routeForPush` tested; navigation itself NOT run |
| Real delivery on a real phone | NOT verified | needs EAS build + Expo (checklist E10) |
| Flutter app | unchanged | no push there |

## Phase 10 of the v0.133.0 prompt — audit sync (0.143.0), honest table

| Part | State | Evidence |
|---|---|---|
| Append-only server copy with hash recomputation | done | PostgreSQL 11 new tests, SQL hash == Python hash |
| Agent sync + divergence detection | done | `test_auditsync.py` (14) |
| Window button / run-loop warning | done | pytest + gui e2e |
| Owner can view server chain on the website | NOT built | agent-side report only |
| Real server | NOT verified | checklist E11 |

## Phase 11 of the v0.133.0 prompt — English-only UI (0.144.0), honest table

| Part | State | Evidence |
|---|---|---|
| Desktop window text in English, no language switch | done | gui e2e 71 (asserts no Bengali text in the page) |
| Agent / CLI / refusal messages in English | done | pytest 328, `test_english_ui.py` guard |
| Bengali voice (recognition, TTS choice, wake/stop words, lip sync) | kept | node 28, voice e2e 27 |
| Bengali on a real microphone | NOT verified | checklist E12 |
| Website / mobile / ai-engine text | NOT changed | out of this phase |

## Phase 12 of the v0.133.0 prompt — installer / release / crash reports (0.145.0), honest table

| Part | State | Evidence |
|---|---|---|
| One version everywhere + release check | done | `test_release.py` (11), part of verify script |
| Shell text English, no Tauri powers, CSP kept | done (checked by script) | `release-check.py` |
| Release workflow: tests, signing hook, checksums, smoke test, draft release | written | YAML parses + safety properties tested; **never run** |
| Code signing | NOT done | needs your certificate + secrets; unsigned builds are labelled UNSIGNED |
| Real installer build (PyInstaller/NSIS/MSI) | NOT verified | no Windows here |
| Crash reports (agent + shell), local only | done | pytest 12, cargo 2, gui e2e 3 |
| Crash upload / auto-update | NOT built | by design: nothing leaves the PC |

## Phase 13 of the v0.133.0 prompt — security hardening (0.146.0), honest table

| Mandatory protection | State | Evidence / gap |
|---|---|---|
| Consent, per-device authorization, signed expiring single-use approvals, replay protection | unchanged, still tested | earlier phases |
| App / website / folder allowlists | stronger: dangerous programs blocked, safer lookup, Windows path rules | 133 new tests |
| Prompt injection defense | stronger (normalisation, more phrases) | simulated texts only; no real model tried |
| Secret redaction | stronger | 19 patterns tested |
| Secure credential storage | DPAPI on Windows | **fake backend only; never run on Windows** |
| Tool input validation | fuzzed | `test_security_hardening.py` |
| Execution limits | output cap added (timeout, step, size limits existed) | tested |
| Task cancellation / Emergency Stop | unchanged | earlier phases |
| Cross-workspace isolation | DB level tested; Laravel desktop controller **never booted** | `tests/db` |
| Secure communication | https enforced, no redirects with token | tested; TLS pinning not done |
| No autonomous money movement | no such tool kind, payment windows refused | static test |
| Secure update mechanism | NOT built | manual installers + SHA-256 only |

## Phase 14 of the v0.133.0 prompt — testing (0.147.0), honest table

| Required validation | Simulated (here) | Real device |
|---|---|---|
| 1-13 agent, UI, voice, browser, vision, planner, Laravel, PostgreSQL, Rust, pairing, mobile, emergency stop, security | passes - see `docs/DESKTOP_TEST_REPORT.md` for counts | NOT RUN |
| 14 Windows installation | static release checks only | `tests/real/test_real_install.py` written, NOT RUN |
| 15 Windows mouse/keyboard | fake input backend | `tests/real/test_real_windows.py` + `validate-windows`, NOT RUN |
| 16 Real microphone | – | MANUAL (cannot be automated) |
| 17 Live model API | mock model only | `apps/ai-engine/tests/live`, NOT RUN |
| 18 Cross-device end-to-end | fake server | MANUAL |
| 19 Crash and recovery | SIGKILL tests on Linux | Windows crash NOT RUN |
| 20 Performance and memory | sandbox, generous limits | NOT MEASURED on a real PC |
