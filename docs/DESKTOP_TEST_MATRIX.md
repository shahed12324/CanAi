# CAN AI Desktop — the 26 required tests, honestly

Date 2026-11-14, version 0.133.0. Re-run everything automatable with `scripts/desktop-verify.sh`.
Legend — **Auto** = passes in CI-style runs here (Linux, fake desktop, fake server, headless Chrome with a fake microphone). **Real** = needs a real Windows PC / phone / microphone; **none of those were available**, so every "Real" column is **NOT TESTED**.

| # | Test | Automated evidence (file :: test) | Real device still needed | Verdict |
|---|---|---|---|---|
| 1 | Desktop startup | `apps/pc-agent/tests/test_gui.py::test_page_served_with_strict_headers`, `::test_serve_prints_url_with_token_in_fragment`, `::test_exit_with_parent_stops_when_stdin_closes`; `apps/desktop` `cargo test` (sidecar path, always-on-top); e2e `tests/e2e/gui.e2e.cjs` (page boots, 14 menus) | Tauri window opening on Windows (WebView2) | Partly tested |
| 2 | Login / device pairing | `tests/test_e2e_cli.py::test_pair_stores_a_private_config`, `tests/test_gui.py::test_pair_then_run_executes_job_and_records_history`, e2e pairing form (wrong code error, success); DB `tests/db/test_pc_control.py` (pair/redeem, one-time code, 5-device cap) | Pairing against the live VPS | Tested (fake server + real DB functions) |
| 3 | Companion Mode | `tests/test_mode.py` (5 tests: refuses every action, pulls nothing, CLI + window switch); e2e mode switch; server `plan` refuses in Companion (code review only) | – | Tested |
| 4 | Agent Mode | `tests/test_actions.py`, `tests/test_taskengine.py` (plan→approve→act→verify→report), `tests/test_phase_d.py` | Real Windows apps | Tested on fakes / Linux |
| 5 | Voice input / output | `tests/js/desktop-voice-character.test.js` (one node test file, 26 internal checks), `tests/e2e/voice.e2e.cjs` (20: tap-to-talk → chat → spoken reply, mute, cloud fallback) with **stubbed** speech engines | Real microphone, Chrome/WebView2 speech services, Bengali recognition quality | Wiring tested; **audio quality NOT tested** |
| 6 | Wake word | fuzzy-match unit tests (node), e2e: off by default, consent required, hands-free toggles | Hearing "Hey CAN AI" on a real mic | Logic tested; **NOT tested live** |
| 7 | Character state sync | node tests (state machine, visemes), `voice.e2e.cjs` state order listening→thinking→speaking→idle | Visual smoothness on a real screen | Tested |
| 8 | Mouse control | `tests/test_devices_phase6.py::test_input_type_click_key_ok`, `::test_input_foreground_guards`, `::test_input_refusals`, rate limit | pyautogui on Windows | Fake backend only |
| 9 | Keyboard control | same file (type / key allow-list, blocked combos, typed text never logged: `test_phase_d.py::…scenario`) | pyautogui on Windows | Fake backend only |
| 10 | Application launching | `tests/test_actions.py::test_open_app_needs_the_local_list`; `window_foreground` verify in `test_phase_d.py` | Starting Notepad on Windows | Fake backend only |
| 11a | Screen understanding (Phase 1) | `tests/test_vision.py` (25, real Tesseract on drawn screens, recording mouse), `tests/db/test_pc_screen_input.py::*34a*` | Real windows, UI Automation, DPI, several monitors, Tesseract on Windows | Simulated desktop only |
| 11b | Browser automation (Phase 2) | `tests/test_browserauto.py` (15, real headless Chrome + local site), DB `*browser_run*` | Real sites, Edge, Windows | Local site only |
| 11c | Natural-language planner (Phase 3) | `ai-engine/tests/test_planner*.py`, `pc-agent/tests/test_phase3.py`, DB `*make_report*` | A REAL model; GUI plans on Windows | Mock model only |
| 11 | Screen capture | `test_devices_phase6.py::test_screenshot_saves_png_in_root`, `::test_screenshot_refusals` (sensitive windows refused) | `mss` capture on Windows | Fake backend only |
| 12 | Screen understanding (OCR/vision) | – | – | **NOT IMPLEMENTED** (only window title/process via `window_info`) |
| 13 | Browser navigation | `test_actions.py::test_browser_checks_host_before_opening`, `test_devices_phase6.py::test_browser_extract` | Real browser | Fake backend only |
| 14 | The 20 areas, simulated vs real (Phase 14) | `scripts/test-report.py`, `docs/DESKTOP_TEST_REPORT.md`, `test_recovery.py`, `test_performance.py` | Everything under `tests/real`, `tests/live`, microphone, cross-device | Real device column is NOT RUN until you run it |
| 14 | Form automation | – | – | **NOT IMPLEMENTED** (no in-page clicking/typing; only mouse/keyboard into an allowed foreground app) |
| 15 | File operations | `tests/test_actions.py`, `tests/test_fileops_docs.py` (atomic write, symlink escape, trash, xlsx/docx verified) | Windows paths / drives | Tested on Linux |
| 16 | Task planning | `tests/test_taskengine.py::test_plan_validation`, `::test_cli_dry_run…`; server `PcPlanner` (`tests/Unit/PcPlannerTest.php`, `apps/ai-engine/tests/test_planner.py`) — planner drafts **file-type** plans only | Real model quality | Tested (no real model) |
| 17 | Task execution | `test_taskengine.py::test_multi_step_report_completes_with_evidence` | – | Tested |
| 18 | Result verification | `::test_a_step_that_says_done_but_verifies_false_is_failed`, `test_fileops_docs.py::test_verify_detects_missing_corrupt_and_hash`, `test_phase_d.py` (window_foreground) | – | Tested |
| 19 | Pause / resume | `tests/test_phase_d.py` (6 pause tests incl. emergency-stop precedence, 30-min give-up, CLI), `::test_failure_is_reported_honestly_and_resume_does_not_repeat`, e2e live card | – | Tested |
| 20 | Emergency Stop | `test_taskengine.py::test_stop_*` (3), `tests/test_watchdog.py` (server-side stop kills a running command), e2e stop/resume; DB emergency tests (`tests/db`) | Killing real Windows child processes | Tested on Linux |
| 21 | Approval expiry & replay protection | `test_taskengine.py::test_expired_wrong_device_and_forged_approvals`, `::test_high_risk_needs_exact_one_time_approval`; `tests/test_serverplan.py::test_a_plan_cannot_be_replayed`, `::test_wrong_device_wrong_key_expired_and_far_future_are_rejected`, `::test_tampered_plan_text_is_rejected` | – | Tested |
| 22 | Mobile approval | Server side (PIN step-up, signed plan hand-off): `tests/db` mobile/pc files, `PcControlTest` (phone hand-off); agent side: `tests/test_serverplan.py` | A physical phone running the app against the VPS | Server+agent halves tested separately; **end-to-end NOT tested** |
| 23 | Laravel synchronization | `tests/test_desktop_ui.py` cloud-proxy tests (client ↔ fake server speaking `docs/DESKTOP_API.md`), `tests/Unit/DesktopApiRoutesTest.php` (routes = contract), DB rotation tests | The new `DesktopController` has **never been booted** in Laravel | **Partly tested** |
| 24 | Unauthorized action prevention | `tests/test_guard.py` (12), `tests/test_gui.py` (token/host/origin), `test_e2e_cli.py::test_agent_never_runs_what_its_own_rules_forbid_even_if_the_server_says_yes`, `test_taskengine.py::test_injection_*`, `test_phase_d.py::test_gui_step_without_approval_is_refused`, DB policy tests | A hostile-network pen-test | Tested |
| 25 | Windows installation | `.github/workflows/desktop-release.yml`, `apps/pc-agent/packaging/*`, `CANAI-Desktop-Windows.zip` (.bat installers) — **never run** | Everything | **NOT TESTED** |
| 26 | Restart & recovery | `test_taskengine.py::test_failure_is_reported_honestly_and_resume_does_not_repeat` (checkpoint + re-verify), `test_e2e_cli.py::test_server_errors_back_off_and_recover`, `tests/test_rotation.py` (lost reply, crash between steps), `test_stop_file_works_from_another_process…` | Reboot / power-loss / Windows service on a real PC | Tested at engine level |

Summary: 26 items → fully or mostly automated: 14 (2,3,7,15,17,18,19,20,21,24,26,4,16,1-partly); wiring-only: 5, 6; fake-backend only: 8–11, 13; partly: 22, 23; **not implemented: 12, 14**; **not tested at all: 25**.
| 11d | Streaming chat (Phase 4) | `pc-agent/tests/test_streaming.py`, gui e2e | Real Laravel SSE, proxy buffering, credits once | Fake server |
| 11e | Voice robustness (Phase 5) | `tests/js/desktop-voice-character.test.js`, `tests/e2e/voice.e2e.cjs` | Real mic, WebView2, speech quality | Fake mic + stubbed engines |
| 11f | Lip sync / floating window (Phase 6) | node character tests, `voice.e2e.cjs`, `cargo test` | Real speaker, Windows window behaviour | Injected level events |
| 11g | Windows control validation harness (Phase 7) | `tests/test_validate.py` | THE REAL RUN on Windows (`3-VALIDATE.bat`) | Simulated desk only |
| 11h | Laravel integration (Phase 8) | `ReplyStyleTest.php`, `tests/db/test_desktop_migrations_files.py`, DB rollback tests | Booted Laravel, real model obeying tone | Mini PHP runner + PostgreSQL |
| 11i | Phone approval / push (Phase 9) | `ApprovalAlertTest.php`, `mobile/tests/refresh.test.ts`, `tsc` | Real phone, real Expo push, latency | Mini PHP runner + vitest |
| 11j | Audit sync (Phase 10) | `test_auditsync.py`, `tests/db/test_pc_audit_chain.py`, `PcAuditTest.php` | Booted Laravel, real network, big logs | Fake server + PostgreSQL |
| 11k | English-only UI, Bengali voice kept (Phase 11) | `test_english_ui.py`, gui/voice e2e, node voice tests | Real Bengali speech, real Windows window | Fake server + headless Chrome |
| 12a | Release consistency (Phase 12) | `test_release.py`, `scripts/release-check.py` | The workflow run, real installer, signing | Static checks of repo files |
| 12b | Crash reports (Phase 12) | `test_crashreport.py`, cargo `crash_*`, gui e2e crash card | A real crash on Windows | Synthetic exceptions |
| 13 | Security hardening (Phase 13) | `test_security_hardening.py` (133) | DPAPI on real Windows, NTFS path behaviour, real attackers, independent pen-test | Linux; fake DPAPI backend |
