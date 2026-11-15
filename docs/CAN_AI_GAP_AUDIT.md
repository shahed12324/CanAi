# CAN AI — Gap Audit (Milestone A)

Date: 2026-11-14. Scope: handover backlog F01–F30 and acceptance tests T01–T16.
Status words: **Implemented+tested** (automated test run by me, output seen) · **Implemented+untested** (code exists, nothing I could run proves it) · **Partial** · **Missing** · **Blocked** (needs real Windows / real services / external assets).

Honest limits of this audit: the Linux sandbox has no Windows, no GUI session, no real AI keys, no Telegram/WhatsApp, no Flutter/Tauri toolchain run. "Tested" means unit/integration tests passed *here*. Nothing below is production-ready; no Windows end-to-end run has been done.

Test evidence run on this date: `apps/pc-agent` → **118 passed** (`python -m pytest tests`). Of those, 28 are new this milestone (`tests/test_fileops_docs.py` 8, `tests/test_taskengine.py` 20). The PHP pure-unit suite still has 146 pre-existing stale failures (not touched; listed under risks).

## Feature matrix

| # | Feature | Status | Code path | Test evidence / gap |
|---|---|---|---|---|
| F01 | Voice input (STT) | Partial | `apps/web-overlay/app/Voice/VoiceCommand.php`, web voice page (browser Web Speech) | `tests/Unit/VoiceTest.php`, `GatewayVoiceTest.php` (PHP, not re-run: stale suite). No desktop/mobile native mic, no offline STT. |
| F02 | Voice output (TTS) | Partial | web voice (browser speech synthesis) | Browser only. No native TTS on desktop/mobile. |
| F03 | Wake word | Missing | — (listed "বাকি" in DEVELOPMENT_STATUS phase 20) | Needs real device mic. |
| F04 | Animated avatar | Partial | `apps/web-overlay/public/js/avatar-player.js`, `app/Character/{Avatar,Emotion,Visemes}.php` | `tests/Unit/AvatarTest.php`, `CharacterTest.php` (logic only; rendering never viewed in a browser here). Not in Tauri/mobile. |
| F05 | Companion mode | Partial | chat + character + memory exist (`app/Chat`, `app/Character`) | No dedicated Companion screen/mode state. |
| F06 | Mode toggle (Companion ↔ Agent) | Missing | — | No shared mode model. |
| F07 | Screen awareness | Partial (code+fake-device tests) | `jarvis_agent/screen.py`, `devices.py` | `tests/test_devices_phase6.py`. Real capture and vision reading untested/not built. |
| F08 | Mouse/keyboard control | Partial (code+fake-device tests) | `jarvis_agent/inputctl.py`, `phase26f.up.sql` | Guards tested with a fake backend; never run on a real desktop. |
| F09 | App/browser automation | Partial | agent kinds `open_app`, `browser_navigate` in `apps/pc-agent/jarvis_agent/actions.py` | `tests/test_actions.py`. `browser_extract` (`browserx.py`) reads plain-HTML pages as text; no DOM clicking/JS pages. |
| F10 | File/document workflows | **Implemented+tested (local)** | `jarvis_agent/{fileops,docs,verify}.py` — copy/move/mkdir/trash (reversible), create xlsx/docx, read-back verify | `tests/test_fileops_docs.py` (8 pass). New kinds are **local-only**: server/Laravel do not queue them yet. |
| F11 | Task engine | **Implemented+tested (local)** | `jarvis_agent/taskengine.py` (plan→approve→act→verify→report, retries ≤2, checkpoints, resume) | `tests/test_taskengine.py` (T08, T09). No natural-language planner feeds it; plans are JSON. Not wired to server queue/UI. |
| F12 | Evidence verification | **Implemented+tested** | `jarvis_agent/verify.py`, engine `verify` per step; report built only from step states | `test_a_step_that_says_done_but_verifies_false_is_failed`, xlsx/docx verify tests. Screenshot evidence: Missing. |
| F13 | Emergency stop | **Implemented+tested (local)** | `jarvis_agent/stop.py` (event + `STOP` file + child kill), `actions.execute` refusal, `runner.py` pause, CLI `stop`/`resume`; server side exists (phase 11 `emergency_events`) | `test_stop_*` (before run, mid-task, kills child process, cross-process STOP file + runner pause). Desktop big-red-button UI and mobile trigger → agent link: untested. Windows process-kill: untested. |
| F14 | Memory | Partial | `app/Memory/*` | `MemoryPureTest`, `MemoryChatTest`, `MemorySuggestTest` (PHP, not re-run). No agent↔server memory sync. |
| F15 | Skills | Partial | `app/Skills/*`, `docs/SKILLS.md` | `SkillTextTest.php`. |
| F16 | Model routing | Implemented+untested | `app/Models`, `ai-engine` | `ModelRouterTest.php` (PHP). No real provider call made. |
| F17 | Task hub UI | Partial | `/app/computer` page (server queue) | No view of local engine tasks. |
| F18 | Persona | Partial | `app/Character` | Unit-level only. |
| F19 | Pairing | Implemented+tested (agent) / untested (server) | `/api/pc/v1/pair`, `jarvis_agent/client.py` | `tests/test_protocol.py`, `tests/e2e`, `PcControlTest` (PHP). |
| F20 | Step-up approvals | **Implemented+tested (local)** + Partial (mobile) | Local: `taskengine.Approvals` (HMAC, bound to task/step/kind/params digest, expiry, single-use, device-bound). Mobile step-up: `app/Mobile/*` | Replay / wrong-params / wrong-task / expired / wrong-device / forged / typed-"yes" all rejected (tests). Mobile→agent delivery of these signed approvals: **not built**; today a human approves at the agent terminal (`cli task`, refuses non-TTY). |
| F21 | Permissions dashboard | Partial | `/app/computer` policy UI; agent `gui.py` | `tests/test_gui.py`. |
| F22 | Audit / privacy | **Implemented+tested (local)** | `taskengine.Audit` hash-chained JSONL with secret redaction; `untrusted.redact` | `test_audit_chain_detects_tampering_and_redacts`. Server audit exists separately; no merge of the two. |
| F23 | Windows packaging | Blocked | `apps/desktop` (Tauri), `apps/pc-agent` | No Windows build run. |
| F24 | Mobile UI parity | Partial / Blocked | `apps/mobile` (Expo, has vitest), `apps/mobile_flutter` | Flutter never compiled here. |
| F25 | Desktop UI parity | Partial / Blocked | `apps/desktop/ui` | Never launched. Hybrid Premium design zip **not supplied**; only `docs/ui/*.md` + two mockups. |
| F26 | Prompt-injection defence | **Implemented+tested** | `jarvis_agent/untrusted.py`, engine `_substitute` (block + taint + forced step approval) | T14 tests. Pattern scan is heuristic, not a guarantee; the real barrier is that step params can't be authored by data without human approval. |
| F27–F30 | Optional P2 items | Missing | — | Not started. |

## Acceptance tests T01–T16 (status)

T08 report-to-Excel with verification: pass (engine-level, local files). T09 failure honesty + resume: pass. T10 high-risk approval binding/replay/expiry: pass (local). T11 emergency stop: pass on Linux (processes, STOP file). T14 injection from file content: pass. T12/T13/T15/T16 related to audit/policy are covered by the tests above where they overlap. **T01–T07 (voice, wake word, avatar, mode toggle, screen, mouse/keyboard, browser) and any Windows/e2e item: not done.**

## Threat model (summary)
- Assets: user files, credentials, the PC. Attackers: malicious content in files/web (injection), stolen/replayed approvals, a compromised server queue, runaway plans.
- Controls added: policy second-check at the agent (existing `guard.py`), per-step kind allowlist, signed single-use approvals bound to exact params, plan digest approval (edit after approval invalidates), taint from outside data forces approval, no silent overwrite (backup), reversible delete (`.canai-trash`), formula-injection-safe XLSX text cells, bounded plan size/retries/timeouts, emergency stop everywhere, tamper-evident audit.
- Known residual risks: approval key sits in the agent's home dir (anyone with that user's file access can mint approvals — approvals protect against the *plan/data/server*, not against a hostile local user); injection scan is pattern-based; Windows path/ACL behaviour unverified; no screen/input automation yet means no UI-level risk model.

## Risk register
1. No Windows verification of anything (process kill, paths, packaging) — high.
2. New agent kinds not known to the Laravel risk map; enabling them server-side needs migration `phase26a` extension — medium.
3. 146 stale PHP pure-unit tests hide regressions — medium.
4. Blade views never browser-rendered in this sandbox — medium.
5. Hybrid Premium design assets missing — design parity unverifiable.

## Plan from here
B (Companion MVP): mode model + toggle, Companion screen reusing chat/character/memory, native TTS/STT hooks — needs real device for acceptance. C remainder: server support for new kinds (migration + risk map), planner (LLM → JSON plan → plan approval UI), screenshot evidence, then input/browser automation on Windows. D: integrate task hub + memory/skills. E: Windows packaging, hardening, real e2e.
Rollback: all new agent code is additive; new kinds are off unless the user enables them in the agent config; delete `stop.py/untrusted.py/fileops.py/docs.py/verify.py/taskengine.py` and the six HANDLERS entries to revert.

## Update 2026-11-14 — Phase 1 done
Server support for the six new kinds exists (`database/sql/phase26c.up.sql`, migration `2026_12_31_100000_pc_file_kinds.php`), verified on a real PostgreSQL 16 (`tests/db/test_pc_file_kinds.py`, 108 pc DB tests pass). Still unverified: the Laravel migration run itself, Pest tests, the web form in a browser, a real agent pulling these kinds end-to-end.

## Update — Phase 2 (planner)
ai-engine planner endpoint + validation exist and are tested with a mocked provider (`apps/ai-engine/tests/test_planner.py`, 29 tests; suite 456 pass). Not verified: real LLM output quality (a real model may fail validation often — the endpoint then returns `invalid`, never a plan). Not built: Laravel service/credit charge for planning, web plan-review page, task hub (Phase 3).

## Update — Phase 3 (plans + signed approvals)
Done and tested here: DB layer (`phase26d.up.sql`, `tests/db/test_pc_plans.py`, 22 tests on PostgreSQL 16), agent verification and run (`apps/pc-agent/jarvis_agent/serverplan.py`, `tests/test_serverplan.py`). Written but NOT executed here: Laravel controllers/routes/Blade section (`PcController`, `AgentController`, `PcActionController`, `resources/views/pc/index.blade.php`) — only `php -l` and two pure static-method checks ran; no Pest, no Blade compile. Missing: Expo/Flutter screens for approving plan steps (the phone API exists), Laravel call to the planner endpoint (plans are pasted/uploaded for now), server emergency stop does not interrupt a plan that is already running on the PC (local `stop` does).
Trust note: approvals from the server are only as trustworthy as the database + device token (the signing key is sha256(token)); the local engine, local policy and local emergency stop remain independent defences.

## Update — Phase 4 (Companion / Agent mode)
F05/F06 moved from Missing to **Implemented+tested at the database level, Partial in apps**: `database/sql/phase26e.up.sql` (`tests/db/test_user_modes.py`, 12 tests), prompt rules `app/Companion/ModeRules.php` + `ChatService` hook (`tests/Unit/CompanionModeTest.php`, 5 tests run with a stand-in runner, Pest itself not run), web switch on /app/computer (Blade not rendered here). Not built: switch in mobile/desktop apps, a dedicated Companion screen, wake word/voice on devices (F01–F04 remain Partial/Missing).
Semantics worth knowing: Companion mode blocks *requests*; it cannot recall a plan already running on the PC (local `stop` does).

## Update — Phase 5 (voice)
F03 wake word: Missing -> **Implemented+tested (logic only), untested on hardware** (web, Chrome/Edge; cloud recognition). F01/F02 stay Partial (web only). F04 avatar: state map added and tested, still not shown on the voice page or in desktop/mobile. See docs/VOICE.md for privacy rules and limits. Nothing here was run with a real microphone.


**Update (0.123.0):** F24 mobile parity — Expo Computer screen now has the mode switch and plan-step approval (code + unit tests only, never run on a device). Avatar is wired to the hands-free voice page (never viewed). Flutter/Tauri/Windows packaging remain Blocked.

**Update (0.124.0):** Laravel -> planner call and credit charge are built and unit-tested with fakes (`PcPlanner`); never run against a real model. The 146 stale PHP tests remain unexamined (Pest cannot be installed in this sandbox).


## Audit 2026-11-14 — status per priority, with evidence (nothing is "complete" without a real-machine run)
| Priority | Status | Evidence | Missing |
|---|---|---|---|
| Real Windows desktop verification | **Not done** | `selfcheck`, `packaging/build.ps1`, `docs/WINDOWS_VERIFICATION.md` written; frozen binary built+run on Linux only | Everything on Windows: build, install, selfcheck output, every row of the checklist |
| Native voice + wake word | **Not done** (browser only) | `public/js/voice-session.js` logic tests (node, pass); browser Web Speech adapters never run on a mic | Native mic/TTS in desktop/mobile; offline wake-word engine |
| Animated AI avatar | Partial | 2-D SVG avatar + lip-sync cues (PHP tests), wired to the voice page state | Never viewed in a browser; no 3-D/video avatar; not in Tauri/mobile screens |
| Screen understanding | Partial | `screen.py` + `devices.py` (fake-device tests pass) | Real capture untested; no vision/OCR reading of the screenshot |
| Mouse/keyboard + browser automation | Partial | `inputctl.py`, `browserx.py` guards tested with fakes (pc-agent 172 pass) | Never run on a real desktop; no JavaScript-page or DOM automation |
| End-to-end natural-language planning | Partial | ai-engine planner (456 pass), Laravel `PcPlanner` (8 checks, fakes), signed hand-over tests | Never run with a real model + real agent |
| Server/mobile emergency stop for running plans | **Implemented + tested at component level** | watchdog kills a real child process in < 5 s (pytest); DB function tests | Real website/phone button -> real Windows agent not exercised; stop reaches the agent only while it can poll the server |
| Desktop Companion/Agent mode | Partial | local mode switch enforced in `execute` + runner (tests); window buttons | No Companion chat/avatar screen inside the Tauri window (Tauri shows the agent page only); not synced with the server per-user mode |
| Mobile approvals | Partial | vitest 32 pass + `tsc` clean; server step-approval DB tests | Expo app never run on a device |
| Memory synchronization | Partial (by design: server-side per workspace) | DB memory tests; web+mobile chat both use the same ChatService | No cross-device test; no memory UI on mobile/desktop |
| Installer packaging | Partial | Linux one-file binary built+run; Inno script + PS build written | Windows exe/installer never built; no code signing |
| Hybrid Premium UI parity | **Blocked** | only the Dark Premium docs exist | The Hybrid Premium design assets were never supplied |
