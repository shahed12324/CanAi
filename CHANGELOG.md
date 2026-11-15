# Changelog

এই প্রজেক্টের সব উল্লেখযোগ্য পরিবর্তন এখানে থাকবে। ফরম্যাট [Keep a Changelog](https://keepachangelog.com/) অনুসারে, ভার্সন ফেজভিত্তিক (`0.<phase>.<patch>`)।

## 0.147.0 — Prompt v0.133.0, Phase 14: automated and end-to-end testing, simulated vs real-device kept apart

- **Real bug found and fixed by the new performance test**: every window poll of `/api/state` blocked for 200 ms (psutil CPU measurement) - 0.2 s -> ~1 ms now (metrics cached 3 s, non-blocking; the server heartbeat still measures over 0.2 s).
- **Two clearly separate test worlds.** Simulated (everything that runs by default) and real-device (`apps/pc-agent/tests/real/`, `apps/ai-engine/tests/live/`), which are SKIPPED unless you run them on the real machine / with your own model key (`JARVIS_REAL_DEVICE=1`, `JARVIS_LIVE_MODEL=1`). Real results are appended to `~/.jarvis-agent/real-results/*.json`; a skipped test is never recorded as a pass.
- New real-device tests (never run by the authors): Windows mouse/keyboard (`validate-windows`), real DPAPI round trip, Windows path tricks on NTFS incl. 8.3 short names, `window_info`/screenshot, emergency stop on a real child process, installed agent (`JARVIS_INSTALLED_AGENT`) start/version/crash-report/English help/Authenticode status, live-model planner (3 honest + 5 hostile goals against the real strict validator).
- New simulated tests: `test_recovery.py` (4: SIGKILL a writer 6x - config never half-written; SIGKILL while appending the audit chain - still verifies; SIGKILL an agent mid-task - restart resumes without repeating finished steps; failed config save keeps the old file), `test_performance.py` (19: regex-DoS on 12 hostile 100-200 KB inputs, audit chain 1500 records, 300 window polls < 20 ms each, 8 parallel clients, no memory growth over 300 idle loops / 150 jobs, crash-report folder bounded, 300 MB file read is capped), `test_report_script.py` (5).
- `scripts/test-report.py [--run] [--real DIR]` writes `docs/DESKTOP_TEST_REPORT.md`: the 20 validation areas of the prompt with a Simulated column and a Real device column (NOT RUN / MANUAL / PASS / FAIL from result files).
- pytest markers `real_device`, `live_model`, `perf`, `area(n)` registered.
- NOT verified: every real-device test above (no Windows PC, microphone, phone or model key here); areas 16 (real microphone) and 18 (cross-device end-to-end) cannot be automated at all and stay MANUAL; no load test beyond the sandbox; no soak test of days.

## 0.146.0 — Prompt v0.133.0, Phase 13: security hardening

Found by attacking the existing controls (new `tests/test_security_hardening.py`, 133 cases) and fixed:
- **Programs**: shells, interpreters, script hosts and system tools (cmd, powershell, bash, python*, node, perl, curl, wget, ssh, reg, schtasks, rundll32, mshta, certutil, msiexec, sudo, rm, shutdown, taskkill …) can no longer be put on the `commands` or `apps` list, and are refused again at run time even if the config file was edited by hand. A program is looked up only in absolute PATH folders, never in the current folder.
- **Paths** (Windows rules enforced on every platform): device/extended paths (`\\?\`, `\\.\`), alternate streams (`file:stream`), wildcard/illegal characters, reserved device names (CON, NUL, COM1 …), names ending in dot/space are refused.
- **Output limit**: `run_command` reads at most 1 MB of output; a flooding command is stopped (before: output was buffered without limit for up to 30 s).
- **Redaction** now also covers JSON `"password":"…"`, "password is …", Authorization Basic/Bearer/Digest, JWT, AWS keys, GitHub/Slack/Google/Stripe keys, passwords inside URLs and connection strings, Bengali `পাসওয়ার্ড`/`পিন`.
- **Prompt-injection scan** reads what a person would SEE: zero-width/bidi characters removed, full-width forms folded (NFKC) - disguised "ignore previous instructions" is found; more patterns (reveal system prompt, forget everything, disable safety, run the following command, role tags, act as admin, pay/transfer, approve for me).
- **Sensitive windows** also cover PayPal, Stripe, checkout, card details, OTP/2FA/authenticator, recovery codes, seed phrases, bKash/Nagad/Upay, Binance/MetaMask/Coinbase and Bengali payment words.
- **Client**: redirects are never followed (an `Authorization` header would have been forwarded by urllib to the redirect target).
- **Token at rest**: new `secretstore.py` - on Windows the device token (and the rotation token) is stored encrypted with DPAPI (`dpapi1:` in `config.json`); a config copied to another Windows user/PC is treated as "not paired" (`jarvis-agent status` explains) instead of failing. Elsewhere unchanged (0600 file). `JARVIS_NO_DPAPI=1` switches it off. Old plain configs are read and upgraded on the next save.
- Fuzzing: every tool kind gets random junk parameters (40 per kind plus non-object params): never raises, nothing outside the allowed folder is touched.
- Tests: pc-agent 351 -> 484. Existing tests that used python/sh as the child program now use `sleep`.
- NOT changed: the "never move money" rule is enforced by design (no such tool kind; a static test keeps it that way); autonomy is unchanged.
- NOT verified: the Windows DPAPI code path (only a fake backend was tested), Windows path behaviour on a real NTFS volume, SmartScreen/AV interaction, anything against a booted Laravel, an independent penetration test. Name lists (dangerous programs, sensitive windows, injection phrases) are guard rails, not guarantees.

## 0.145.0 — Prompt v0.133.0, Phase 12: installer, release, signing readiness, crash reporting

- Versions: agent, pyproject, Tauri conf, Cargo.toml and the Inno script are all `1.0.0` now (they were 0.1.0 in the shell). New `scripts/release-check.py [--tag vX.Y.Z]` fails if versions differ, the shell got any Tauri permission, the CSP is loosened, shell text is not English, or the release workflow loses a safety property (read-only default token, write only in the release job, signing only through secrets, checksums, smoke test, DRAFT releases). Part of `desktop-verify.sh`.
- Shell leftovers from Phase 11: tray menu, tooltip, window title, product name, start page, Inno names are English (`CAN AI Agent`).
- `desktop-release.yml`: runs the check and the agent tests, builds the agent, **optional** signing when `WINDOWS_CERT_PFX_BASE64` / `WINDOWS_CERT_PASSWORD` secrets exist (agent exe + Tauri exe + installers, SHA-256, timestamp, `signtool verify`), silent-install smoke test, `SHA256SUMS.txt`, `SIGNING-STATUS.txt`, signing material removed from the runner, tag push -> DRAFT release.
- Crash reporting (local only, no upload): new `crashreport.py` - uncaught exceptions in the main thread, other threads and any CLI command write a private (0600) JSON report to `<agent home>/crashes/` (version, OS, file/line/function frames only, redacted + home-path-stripped message; no config/tokens/task data/source/locals), newest 10 kept, never raises. CLI exits 70 with a notice instead of a raw traceback. New `jarvis-agent crash-report [--show|--bundle|--clear]`; window API `GET /api/crashes`, `POST /api/crashes/clear` (token required); Settings -> Crash reports card. The Tauri shell writes `crash-shell-*.json` on a panic (version, OS, code location, short message, local URL masked).
- Tests: pc-agent 328 -> 351 (`test_crashreport.py` 12, `test_release.py` 11); cargo 7 -> 9; gui e2e 71 -> 74 (crash card: listed, redacted details, delete); old-server e2e 53 -> 56.
- NOT verified: the workflow itself (never run), a real build of the PyInstaller exe / NSIS / MSI, signing with a real certificate, SmartScreen behaviour, the silent-install smoke test paths, upgrade-over-old-version and uninstall behaviour, a crash on real Windows. No auto-update and no crash upload server exist.

## 0.144.0 — Prompt v0.133.0, Phase 11: English-only UI (Bengali voice kept)

- Desktop window (`ui/app.js`, `ui/voice.js`): every label, message and hint is English; the language switch was removed from Settings and the two-language dictionary is gone (`LANG` is a constant `'en'`). The Bengali/English **voice** choice (`bn-BD` / `en-US` / Auto), Bengali speech recognition, Bengali wake and stop words, and Bengali lip-sync letters are unchanged.
- Agent messages (actions, guard, fileops, docs, observe, inputctl, screen, devices, verify, serverplan, taskengine, policy, prefs, rotation, runner, watchdog, cli `--help` and prompts, gui errors, step labels) are English. Bengali is kept only for functional detection patterns (prompt-injection phrases in Bengali, sensitive window titles in Bengali).
- `prefs`: the `language` key stays valid (old config files keep loading) but defaults to `en` and the UI ignores it.
- Chat content is untouched: the person can still write and hear Bengali; the server and the AI replies are not changed here.
- Tests: new `tests/test_english_ui.py` (4: no Bengali left in UI/messages except an allow-list, Bengali voice still offered, no language switch, old `language` pref still loads); about 60 message assertions across existing pytest/e2e/node tests were switched to the English text (Bengali test DATA such as memory text and file contents is kept on purpose). pc-agent 324 -> 328; gui e2e 69 -> 71 (language switch checks replaced by "no selector / English / no Bengali text in the page").
- NOT changed: the Laravel website pages, mobile apps (Expo/Flutter), server-side `ai-engine` prompts and API error texts.
- NOT verified: Bengali speech on a real microphone/WebView2 after this change (only the unit/e2e level), and the real Windows window.

## 0.143.0 — Prompt v0.133.0, Phase 10: audit log sync (local hash chain <-> server)

- Server (SQL `phase37a`, migration `2027_01_07_...`): new append-only table `pc_audit_chain` (RLS, no update/delete/truncate even for the owner) and functions `pc_audit_head` / `pc_audit_ingest`. Ingest is all-or-nothing, takes an advisory lock per device, requires the first new row to link to the stored head and every row to link to the previous one, and RECOMPUTES every hash itself (`pc_audit_hash`, byte-identical to the agent's Python). Routes `GET audit/head`, `POST audit/sync` (device token, 30/min, 300 KB cap); PHP checks shape, the database has the last word.
- Agent: `auditsync.py` - verifies the local chain first (a broken file is NEVER uploaded: `local_tampered`), asks the server where its copy ends, checks that the local file has the very same row there (else `diverged`: rewritten, truncated or replaced), uploads the rest in batches of 200. The run loop syncs at most every 5 minutes and only when the file changed and logs a warning on divergence; the window has a "Sync with server" button and shows the result in the task list. Statuses are plain: synced / local_tampered / diverged / offline / server_too_old / error.
- Client errors now carry the server's JSON body (`ServerError.body`).
- Tests: pc-agent 310 -> 324 (`test_auditsync.py`: first/incremental/batched sync, rewritten and truncated local file, tampered or garbled local file never uploaded, forged batch refused, old/offline/revoked server, awkward text round trip, periodic throttling, run-loop warning, window route + guards); PostgreSQL pc_/rotation/migration files 158 -> 169 (`test_pc_audit_chain.py`: SQL hash == Python hash for Bengali/emoji/quotes/control chars, batches, wrong start, changed/removed/forged rows refused with nothing stored, malformed input, other workspace and revoked device, append-only even for the owner, RLS, rollback refuses with rows and rolls back/forward when empty; the 34a/35a rollback tests now undo/redo 37a); PHP `PcAuditTest` 4; gui e2e 68 -> 69.
- What it proves / does not: detects a local file changed after upload. It cannot prove what an agent that was compromised BEFORE uploading wrote, and the server's copy is only as complete as the agent's uploads (a PC that is off or offline is behind until it syncs). The website does not show the server chain yet - only the agent reports the comparison.
- NOT verified: the PHP controller/repo against a booted Laravel, the real network path, behaviour with very large logs on a real server (batches are tested with 450 rows).

## 0.142.0 — Prompt v0.133.0, Phase 9: phone approval with real-time push + polling fallback

- Server: new `App\Mobile\ApprovalAlert`. When a computer plan is created (`PcPlanner`) or a web-requested action is waiting for approval (`PcService::request`, status `pending`), a notification is written and pushed AT ONCE through the existing Expo push path (before: only the once-a-minute digest). Requests made on the phone itself do not alert. The text is fixed and generic ("Your computer is waiting for approval") - push goes through Expo's servers, so no goal, path or command is ever included. One unread alert per person at a time (no flood); best effort: a failure never breaks the plan or request.
- Phone app (Expo): `useAutoRefresh` keeps the Computer and Approvals screens fresh - instantly when an approval push arrives, when the app returns to the foreground, and by polling: every 12 s when push is NOT available (the fallback: no permission / simulator / no EAS project / Expo down), a slow 60 s safety net when it is, nothing while in the background or the screen is closed, exponential back-off (to 5 min) while offline. Tapping an approval push opens My Computer (`/computer`); only our own screens are reachable. `useLoad.reload` now reports success/failure. Session exposes `pushOn`.
- Approval rules are unchanged: high-risk steps can still only be approved on the phone with the PIN; the push is a nudge, never an approval.
- Tests: PHP `ApprovalAlertTest` (6) via the mini runner (immediate push, generic text, no flood, never throws, web vs phone vs refused, wiring), MobilePush/PcControl/PcWeb still pass; phone vitest 28 -> 38 (`refresh.test.ts`: intervals, background, back-off, payload trust, routes), `tsc --noEmit` clean.
- NOT verified: **a real phone, a real Expo push (needs an EAS project id, a built app and `MOBILE_PUSH=expo` + token on the server), the PHP on a booted Laravel, delivery latency, iOS/Android background behaviour**. The Flutter app has no push at all and was not changed. Known pre-existing: `WorkflowExtrasTest` has 11 failures in the mini runner with or without this change (verified by reverting) - language strings / runner limits, not investigated here.

## 0.141.0 — Prompt v0.133.0, Phase 8: Laravel integration (persona / source used by the server)

- `persona` and `source` sent by the desktop window are now used: new `App\Chat\ReplyStyle` adds a fixed tone line (friendly / calm / playful) and, for voice, a "read aloud: short plain sentences, no markdown/tables/code" rule to the system prompt, in both `chat` and `chat/stream` (via new optional `ChatRequest` fields). Unknown or hostile values are ignored (nothing from the window is copied into the prompt); a character conversation keeps its own persona; the mode honesty rules still come last.
- Tests: PHP `ReplyStyleTest` (4: exact prompts for the three personas, voice rule, unknown/hostile values ignored, both endpoints wired) via the mini runner; existing chat/memory/knowledge/code-mode/route/PcWeb tests still pass. DB-free `tests/db/test_desktop_migrations_files.py` (4): every desktop migration names matching existing up+down SQL, the test schema loads every up file in migration order, downs are not empty / never drop data unguarded, kind layers chain in phase order. Existing PostgreSQL rollback tests (36a -> 35a -> 34a -> forward) unchanged and passing.
- NOT verified: **the PHP against a booted Laravel** (Composer is blocked here) - ReplyStyle/ChatService/controller changes were run only through the mini Pest runner and `php -l`; whether real models obey the tone/voice lines (prompt effect is unmeasured); two old PHP files (`ChatServiceTest` 1 case, `ChatPureTest`) cannot run in the mini runner (its limits with array `toContain` / datasets), they are not related to this change.

## 0.140.0 — Prompt v0.133.0, Phase 7: real Windows control validation harness

- New command `jarvis-agent validate-windows --yes` (and `3-VALIDATE.bat` in the Windows package): opens its own small test window and drives it through the real job code path (policy -> real mouse/keyboard): window rectangle vs what the agent sees (DPI/scaling), typing, a key press, a click at a computed coordinate, click outside refused, alt+f4 refused, app-not-on-list refused, "Sign in" window refused, screenshot, screen reading + clicking by text (needs Tesseract), emergency stop. Writes `validation-*.json` with `real_device` true/false; it refuses to run (and never writes a "real" report) on a fake backend. It touches nothing outside its own window.
- Window not in front -> remaining checks are SKIPPED, not passed. Missing Tesseract -> OCR checks SKIPPED with the reason.
- Windows package: README-FIRST updated to the truth (OCR/browser/planner now exist but never ran on Windows); build script includes tkinter.
- Tests: pc-agent 303 -> 310 (`test_validate.py`, against a simulated desk: a correct desk passes; a DPI mapping bug, a wrong window rectangle, a window not in front and a removed key guard are all detected; the report labels itself SIMULATED).
- NOT verified: **everything the harness measures on a real PC** - that is the point of the tool; the TkProbe window class itself has never run (no tkinter / display here). Not covered by it: pyautogui corner fail-safe, other applications, UAC/secure desktop, real websites, microphone, phone approval.

## 0.139.0 — Prompt v0.133.0, Phase 6: lip sync from real audio, floating compact window

- Cloud voice: the reply's real audio is measured (Web Audio analyser) and the character's mouth follows that level (`canai-speech-level`); silence closes the mouth. Browser voices keep the word-boundary mouth shapes; a voice with no signal falls back to the syllable wave. Level events are ignored outside the speaking state.
- Desktop shell: the "Compact" preference now also resizes the Tauri window to a narrow floating panel (360x560) and back (1100x760); minimum window width 380 -> 320. "Always on top" (already present) stays independent, so compact + always-on-top = a small floating assistant.
- Tests: node character/voice 27 -> 28; voice e2e 25 -> 27 (loud level opens the mouth, silence closes it); cargo 6 -> 7 (`compact_pref`, `window_size`).
- NOT verified: real cloud audio on a real speaker (e2e injects level events; the analyser path itself ran only where Chrome allows it), real Windows always-on-top/resize behaviour, multi-monitor placement, lip sync quality for Bengali (word-shape approximation, not phoneme-accurate).

## 0.138.0 — Prompt v0.133.0, Phase 5: voice in WebView2 / real microphone robustness

- A speech-recognition object that exists but cannot work (typical in WebView2: errors `network`, `service-not-allowed`, `not-allowed`, `language-not-supported`) is remembered for the session; later presses go straight to the cloud recording path instead of failing first. Without a recorder the person gets a clear "no speech input" message.
- Cloud voice uses credits, so when the window falls back on its own the person is told once per session (Bengali/English).
- Settings -> Voice: **Voice diagnostics** button prints a copyable report (secure context, speech object, recorder, synthesis, mic permission, audio inputs count, chosen plan, last error, user agent). No audio, tokens or personal data.
- Tests: node voice tests 26 -> 27; voice e2e 20 -> 25 (fake mic + stubbed engines: engine failing with `network` -> cloud STT -> chat, credits notice, engine not retried, diagnostics text).
- NOT verified: **a real microphone, real WebView2 on Windows, real speech quality (English/Bengali), real device switching**, wake word accuracy. Only Chrome's fake microphone and stubbed speech objects were used. The Tauri window's own microphone permission prompt is untested.

## 0.137.0 — Prompt v0.133.0, Phase 4: streaming chat

- Server: `POST /api/pc/v1/desktop/chat/stream` (SSE: `meta`, `delta`..., `done` | `error`) reuses the existing `ChatService` - credits are reserved once and settled/released exactly once; a client hang-up (`connection_aborted`) keeps and charges only the partial answer.
- Agent: `Client.desktop_stream` + local relay `/api/cloud/chat/stream` (same token/Host/Origin guards): relays only complete events, drops comments/ids/retry and over-long events (64 KB line, 2 MB, 180 s caps), maps 401/409/501/offline to clear errors, closes the upstream request when the window hangs up.
- UI: the answer appears as it is written; **■ Stop** cancels (partial answer kept, marked ■, no error); fallback to the old one-piece `chat` only when the server has no streaming (404/405/501) and nothing was sent yet, so a message is never sent twice. Voice replies are spoken after the full answer (not progressive).
- Tests: pc-agent 297 -> 303 (`test_streaming.py`: relay, noise, errors before/mid stream, hang-up, guards, oversized lines); gui e2e 64 -> 68 (stream, Stop, fallback); voice e2e 20 pass.
- NOT verified: the new PHP controller against a booted Laravel, credits-once end to end (relies on existing ChatService, not re-tested here), real network/proxy buffering, persona/source still unused server-side.

## 0.136.0 — Prompt v0.133.0, Phase 3: natural-language computer planner

- AI engine planner (`app/planner/plan.py`) now plans the whole computer: files, `make_report`, `open_app`, `window_info`, `wait_window`, screen kinds, `input_control` (type / key / scroll only - clicking goes through `ui_click`), `browser_navigate/extract/run`. Strict per-kind schemas that mirror the device and SQL rules; apps and websites must be on the owner's allowed lists (new request fields `apps`, `hosts`); pay/buy/transfer/delete-account words, blocked key combinations, secrets and sign-in are refused; `run_command`, `power`, `system_setting`, `delete_file` are never offered. GUI/browser kinds are offered only when the caller lists them (default stays files-only). Max 16 steps.
- Plans can carry per-step `needs` (earlier steps that must have completed), `when` (run only if an earlier step's output contains / does not contain text) and `continue_on_error` (run even after a failure). Device engine: validated, new step state `not_needed` (○), included in the plan digest so they cannot be swapped after approval.
- New kind `make_report`: CSV in an allowed folder -> Excel with Data, Summary (count/sum/average/min/max per number column) and optional totals per `group_by`; all arithmetic done locally (the model never sees or computes the data); backup before overwrite; formula-neutralising; verify type `xlsx_report`.
- Server: SQL `phase36a` (+migration, `PcService`); Laravel now sends the allowed apps and websites to the planner (`PlanEngine::draft(..., $apps, $hosts)`).
- Tests: ai-engine 508 pass (new `test_planner_computer.py` 52, `test_planner_acceptance.py` 6: mock-model plan -> validation -> the real device engine -> Excel with exact numbers; an instruction hidden in the CSV cannot widen what runs), pc-agent 290 -> 297, PostgreSQL pc_/rotation 152 -> 154 (make_report scope, plans with needs/when, rollback chain 36a->35a->34a and forward), PHP planner/web tests.
- NOT verified: **any real model** (the model reply is a fixture, so plan QUALITY on real goals is unmeasured), real GUI plans on Windows, the PHP against a booted Laravel. `continue_on_error` keeps the engine's old meaning: "run this step even if an earlier one failed".

## 0.135.0 — Prompt v0.133.0, Phase 2: advanced browser automation

- New kind `browser_run` (high risk, off by default): ONE approved step carries a short script (max 20 operations: `goto, fill, select, check, click, wait_text, expect_text, extract`), so the person sees every field and click before approving. `docs/DESKTOP_API.md` has the schema.
- `browserauto.py` (Playwright, uses installed Chrome/Edge): fresh empty browser per run (no cookies, downloads, permissions, pop-ups), every request checked against the allowed-host list **including each redirect hop** (found by test: the browser used to contact a redirect target before we could look; now each request is fetched without following redirects and the target is checked first), password/card/one-time-code fields never filled, sign-in/payment/bank pages stop the run, pay/buy/transfer/delete-account buttons never clicked (checked in the script and again on the real button text), typed values never echoed, page text redacted + flagged when it looks like instructions, emergency stop between operations, 60 s / 20 operation limits, ambiguous targets refused.
- Server: SQL `phase35a` (validates the whole script, host-checks every URL, refuses money words), migration, `PcService`. Found and fixed: a `CASE ... THEN` inside a plpgsql `IF` breaks the parser.
- Tests: pc-agent 275 → 290 (real headless Chrome through Playwright against a local test site: form fill + submit + verification, cookie isolation, off-list sub-resources blocked, redirect refused, sign-in/password/card/pay refusals, ambiguity, injection text flagged, pop-ups, stop, bad scripts), PostgreSQL 150 → 152 (`browser_run` params, rollback/roll-forward of 35a; the 34a rollback test now undoes 35a first).
- Not verified: real websites, Edge/Windows, CAPTCHAs, file uploads/downloads (deliberately unsupported), heavy single-page apps, the new PHP against a booted Laravel. Sites that block automated browsers will fail.

## 0.134.0 — Prompt v0.133.0, Phase 1: AI screen understanding

- New local kinds (all **high** risk, off by default, need an allowed app in front, never run on sensitive windows, repeat every check on the device): `screen_read` (text of the front window), `screen_find` (matches with coordinates), `ui_click` (click ONE clear match), `screen_describe` (local summary; a model only if the owner turned `vision_upload` on, with secret-looking lines blacked out).
- Verification types for any step: `screen_text` (text is visible), `element_present`.
- `vision.py` (capture → accessibility tree first, OCR second → match → refuse if none / ambiguous / below confidence / window moved), `ocr.py` (Tesseract TSV, outline stripping so bordered buttons are read), `coords.py` (pixel mapping from the real image size; negative monitor origins), `privacy.py`.
- `devices.py`: `virtual_screen()`, `monitors()`, `ui_tree()`; Windows DPI awareness; `inputctl` click bounds now use the whole virtual screen (monitors left of / above the main one were wrongly refused).
- Server: SQL `phase34a` (+migration, `PcService` labels/params); `phase33a/34a.down.sql` now see rows through row-level security (the "refuse rollback when rows exist" check was silently ineffective for the owner role).
- Tests: pc-agent 250 → 275 (real Tesseract on drawn screens), PostgreSQL pc_/rotation 146 → 150 (incl. rollback and roll-forward of 34a), PHP static tests updated, Chrome e2e unchanged (64/20).
- Not verified: anything on real Windows (UI Automation code, DPI calls, real multi-monitor), Tesseract on Windows, a vision model (no server endpoint exists yet: `vision.model_describer` is unset), the new routes against a booted Laravel.

## 0.133.0 — Desktop Phase F: verification pack and release documents

- `scripts/desktop-verify.sh`: one command re-runs every automated check (pytest, node, Chrome e2e ×3, PostgreSQL rules, cargo) and prints PASS/FAIL/SKIP.
- `docs/DESKTOP_TEST_MATRIX.md` (the spec's 26 tests mapped to real test names and honest verdicts), `DESKTOP_WINDOWS_CHECKLIST.md` (what a person must try on Windows/phone), `DESKTOP_INSTALL.md`, `DESKTOP_SECURITY_AUDIT.md`, `DESKTOP_FEATURE_REPORT.md`.
- Windows package README corrected (voice/character/rotation now included; unverified items listed).
- No feature code changed in this step.

## 0.132.0 — Desktop Phase E: server side of the desktop window, token rotation

- Laravel: `DesktopController` with all `docs/DESKTOP_API.md` routes (`bootstrap, tools, approvals, chat, agents, workflows, memory, plan, voice/listen, voice/speak`) behind the device token, running as the device owner through the normal services (credits, limits). No route approves anything. `PcAgentAuth` now also passes `pc_user`.
- Token rotation: SQL `phase33b` (`pc_agent_rotate`, `token_rotated_at`), `POST /api/pc/v1/rotate`, agent `rotation.py` (candidate saved before sending, lost-reply recovery), `jarvis-agent rotate-token`, window button; a running loop adopts a token rotated elsewhere, a revoked device still ends it.
- Tests: pc-agent +10 (rotation); DB tests on PostgreSQL 16: 95 pass in the pc_* files incl. 4 new rotation tests (found and fixed a real `FOUND` bug); PHP: 4 static contract tests + rotation test; 5 stale PC test expectations (messages changed to English earlier) corrected; Chrome e2e 64 checks, 4/4 stable runs.
- Mobile approvals reaching the agent: already built in phases 26–28 (phone approves plans/steps with PIN, agent pulls signed plans); this phase only shows them read-only in the window.
- Not verified: the Laravel controller against a booted app (no vendor/ here), real chat/agent/workflow/voice calls, `phase33b.down.sql`, anything on real Windows.

## 0.131.0 — Desktop Phase D: pause/continue, live progress, screen-state evidence

- Agent: `pause.py` (pause/continue; same three channels as the emergency stop; the emergency stop always wins; a task paused 30 min is given up honestly), `progress.py` (live step states for the window), `observe.py` (new kinds `window_info` medium/read-only/tainted, `wait_window` low, verify type `window_foreground`).
- Engine: holds before each step and retry while paused, audits `paused`/`continued`, writes live progress; a GUI step can now be verified against the real foreground window.
- Window: "Running now" card (steps, evidence, Pause/Continue); `GET /api/live`, `POST /api/pause|continue`; CLI `jarvis-agent pause|continue`.
- Server: migration `phase33a` (risk + parameter rules for the two kinds, wraps `pc_target_ok`), `PcService::KINDS/paramsFor`. DB tests run on a real PostgreSQL 16: 142 pc_* tests pass.
- Tests: pc-agent 240 pass; Chrome e2e 62 (+old server) and voice 20.
- Deliberately unchanged: the AI planner (`ai-engine/app/planner/plan.py`) still drafts file-type plans only — letting a model drive mouse and keyboard is a separate safety decision. Approvals still happen on the website/phone only.
- Not verified: anything on real Windows (pyautogui/mss/foreground-window backends), `phase33a.down.sql`.

## 0.130.0 — Desktop Phase C: living character and real-time voice

- `ui/character.js`: SVG character (idle / listening / thinking / speaking / error), blinking per personality, mic-level reaction, word-boundary mouth (viseme) sync for browser TTS, reduced-motion and low-performance modes. States come only from real events.
- `ui/voice.js`: tap-to-talk, push-to-talk (F9/F10/Space/Ctrl), silence endpointing, barge-in (starting to speak cancels the reply), stop words, mute, mic/speaker device choice, voice speed/name, mic test, permission-state display, wake word "Hey CAN AI" (off by default, explicit consent each visit).
- STT: browser SpeechRecognition, or cloud (`voice/listen`) when the window has none; TTS: browser speechSynthesis or cloud (`voice/speak`). Spoken words go to chat exactly like typed text and can never approve an action.
- `ui/voice-session.js` is byte-identical to the website copy (test enforces it).
- Local server: `/ui/character.js|voice.js|voice-session.js`, 2.5 MB body limit only for voice calls (other cloud calls keep 64 KB). New prefs: character_*, voice_*, wake_word, stt/tts provider.
- Tests: pc-agent 221 pass; node 26 pass; Chrome e2e voice 20 checks (fake microphone + stubbed speech engines), main e2e 59 + 45 (old server).
- Not verified: real microphone, real speech recognition quality (Bengali especially), WebView2 speech support, cloud-TTS lip-sync (plain wave only), real Windows. Laravel `voice/*` desktop endpoints are Phase E.

## 0.129.0 — Desktop Phase B: premium window (14 screens), settings, emergency stop, folder browser

- `apps/pc-agent/jarvis_agent/ui/` (index/app.css/app.js/logo): Hybrid dark-glass UI, bn/en, dark/light, compact, reduced motion; strict CSP, no `innerHTML`.
- Local API additions: `/api/prefs`, `/api/stop`, `/api/resume`, `/api/unpair`, `/api/files`, `/api/tasks`, `/api/cloud/*` (token-hiding proxy to `/api/pc/v1/desktop/*`, contract in `docs/DESKTOP_API.md`).
- New modules `prefs.py`, `localview.py`; `Client.desktop()`.
- Tauri shell: bundled agent sidecar + `prefs.always_on_top` watcher (6 Rust tests).
- Tests: pc-agent 216 pass; Chrome e2e 59 checks (+45 against an old server).
- Not done in this step: voice, character, Laravel `desktop/*` endpoints (Phases C and E).

## 0.128.0 — Desktop: audit, architecture, installer path (Phase A)

- `docs/DESKTOP_ARCHITECTURE.md`: what the desktop stack really contains, which spec items are missing, honest install status.
- Tauri shell prefers a bundled `jarvis-agent(.exe)` sidecar (tested); `tauri.release.conf.json`; `.github/workflows/desktop-release.yml` builds an unsigned Windows installer on a GitHub runner (never run yet).

## 0.127.0 — Workflows: manage, organise, build visually, test safely

- Hotfix: WorkflowServiceProvider now also registers AgentsServiceProvider (idempotent), so a stale providers list cannot break /app/agent-builder.
- Hotfix: Agent Builder index rendered a non-existent view (`agentbuilder.index`); now `agents.index`. Added a test that every view the controller renders exists.
- Fixes found while auditing: the Agents SQL from 0.126.0 had been saved as `phase32a`, overwriting the Skills SQL of the same name (restored from an older copy; Agents is now `phase32b`); the Agent Builder route names (`agents.*`) collided with the workflow routes (now `agentbuilder.*`). `tests/db/conftest.py` now also loads `phase32a` (skills), which no DB test loaded before.
- DB `phase32c` (+ migration `2027_01_02_100000_wf_manage.php`): `wf_workflows.template_key` (origin, set once), `paused`, `model_id`; new categories next to the old ones (old rows untouched); run trigger `test`; functions `wf_rename`, `wf_set_meta`, `wf_set_definition`, `wf_set_state`, `wf_duplicate`, `wf_mark_template`, `wf_test_run_create`; `wf_due()` skips paused workflows. A rename changes only name+description: id, version, steps, schedule, `last_scheduled_on`, template origin and history are untouched. Tests: `tests/db/test_wf_manage.py` (21).
- PHP: `WorkflowService` (rename, settings, saveSteps, pause/resume/enable/disable, duplicate, createBlank, startTest, cards), `WorkflowCatalog` (groups, status, search/filter), `Templates` (6 groups; the 13 original templates unchanged + 2 new: `dev_code_review`, `desktop_folder_summary`), `WorkflowRunner` test mode and per-workflow model (`AiStep` gets an optional model id), `WorkflowController` + routes `agents.show|rename|settings|steps|state|duplicate|test|create`.
- UI: list with search / group / status filters and status dots, template tabs + search, "start from scratch", detail page (rename, run, test mode, settings, Desktop Agent panel, history with step logs), visual builder `public/js/workflow-builder.js` (drag from palette, drag nodes, connectors, inspector, validation hints). The old JSON editor stays as "Advanced (JSON)".
- Tests: `tests/Unit/WorkflowManageTest.php` (23), `tests/js/workflow-builder.test.js` (10), `tests/browser/test_workflow_builder.py` (12 + 1 against the real rendered page, headless Chrome). All 204 Blade views compile with Laravel's real BladeCompiler (`tests/tools/blade_compile.php`); the new pages were rendered with real data (`tests/tools/blade_render.php`).

## 0.126.0 — Agents & Workflows, phase 1: Agent Builder (database, service, UI)
- DB: `phase32b.up/down.sql` + migration `2027_01_01_100000_wf_agents.php` — `wf_agents`, `wf_agent_versions`, `wf_agent_runs` (RLS, SELECT-only web role), functions `wf_agent_save/restore/delete/run_record/limit` (each calls `credit_authorize`), feature `agents.max` (default 10 when unlisted, NULL = unlimited). Tests: `tests/db/test_wf_agents.py` (22).
- PHP: `App\Agents\{AgentSpec,AgentRepo,PgAgentRepo,AgentService}`, `AgentsServiceProvider` (added to `scripts/bootstrap-web.sh`), `AgentStudioController`, routes `/app/agent-builder*`, views `agents/{index,form}.blade.php`, sidebar link. Money tools are never allowed; outside-the-app and desktop tools need explicit permissions.
- Test run: real model call with credit reserve -> settle (released on provider failure, per-agent credit limit checked first). Tools are listed to the model but NOT executed in a test.
- Tests: `tests/Unit/AgentServiceTest.php` (19, via mini runner). Blade views were NOT rendered (no Laravel vendor here).

## 0.125.0 — Audit pass: emergency stop reaches running plans, desktop Companion/Agent mode, packaging, selfcheck
- Emergency stop for RUNNING work: `phase26g.up/down.sql` (`pc_agent_emergency`, tenant-authorised, device-checked) + migration `2026_12_31_500000_pc_agent_emergency.php`, `GET /api/pc/v1/emergency`, agent `watchdog.py` (polls every 2 s while a plan/job runs; on stop: kills child processes, sets the local STOP marker; an unreachable server does not stop a job by itself). Tests: `test_watchdog.py` (real `sleep 30` process killed in < 5 s), `tests/db/test_pc_agent_emergency.py`.
- Desktop Companion/Agent mode: `Config.mode` (unknown value -> companion), `jarvis-agent mode`, window buttons, runner pulls nothing and `execute` refuses in companion mode. Tests: `test_mode.py`.
- Packaging: `apps/pc-agent/packaging/{entry.py,build.sh,build.ps1,installer.iss}`. A naive PyInstaller build of `__main__.py` crashed (relative import) — fixed with `entry.py`; the Linux one-file binary was built and run (`--help`, `mode`). Windows `.exe` and Inno installer: NOT built.
- `jarvis-agent selfcheck` (screen size, screenshot, foreground window, libraries, stop marker) for the person verifying on Windows; `docs/WINDOWS_VERIFICATION.md` checklist.
- `docs/PHP_STALE_TESTS.md` + `tests/tools/mini_pest.php`: the 146 "stale" PHP tests diagnosed (mostly Bengali-vs-English message drift); one fixed (`PcWebTest`).

## 0.124.0 — Laravel -> planner: "tell the computer what you want"
- `App\Pc\PcPlanner` (+ `PlanEngine`, `GatewayPlanEngine`, `ProviderGateway::planDraft` -> ai-engine `/internal/v1/plan`): the model drafts a plan from a goal; the draft goes through the same `pc_plan_submit` as a pasted plan, so database rules, the person's approval and phone-PIN for risky steps all still apply. The model only ever produces a draft.
- Credits: reserve -> settle with real token cost; released (no charge) if the provider fails; charged if the model answered but the draft was refused/invalid/rejected (the provider was paid). Companion mode is checked first so nothing is charged there. Kinds the device rules deny are never offered to the model.
- Web: "Draft a plan" form on /app/computer (`pc.plan.draft`, throttled 6/min). Tests: `tests/Unit/PcPlannerTest.php` (8 checks, run with a stand-in runner: Pest itself cannot be installed here because Packagist is blocked).
- NOT verified: a real model producing a plan; the Blade form rendering; the 146 older stale PHP tests (still untouched: they need Pest/vendor to even run).

## 0.123.0 — Phase 7 (the part possible without Windows): phone mode switch + plan-step approval, avatar on voice page
- Mobile (Expo): "Computer" screen gets a Companion/Agent switch and a Plans card listing the steps that need YOUR approval (high-risk ones ask the PIN via the existing step-up). New API: `GET/POST /mobile/mode`, uses existing `GET /mobile/pc/plans`, `POST /mobile/pc/plans/{id}/step`. `src/lib/pc.ts` helpers + `tests/pc.test.ts`; vitest 32 pass, `tsc --noEmit` clean.
- Web: /app/voice shows the companion's 2-D avatar next to the hands-free status and changes its expression with the voice state (`VoiceSession.avatarFor`); page never fails if there is no avatar.
- NOT verified: the Expo app was never run on a device/emulator; Blade/Pest never executed here; avatar never viewed in a browser. Still needs a real machine: Windows agent build (PyInstaller/installer), Tauri build, Flutter parity, local wake-word engine, the Hybrid Premium design assets.

## 0.122.0 — Phase 6: screen, mouse/keyboard, web reading (safety layer tested with fake devices; real hardware untested)
- pc-agent: `devices.py` (the only code touching screen/input/web; `RealBackends` uses mss, pyautogui, ctypes, urllib — **never run on a real display or Windows here**), `screen.py` (`screenshot`: .png inside an allowed folder, no silent overwrite, refuses sensitive windows), `inputctl.py` (`input_control`: type/click/key/move/scroll; foreground window must be an allowed app and not sensitive, clicks must land inside it, text <= 200, key allowlist with alt+f4 / ctrl+alt+del / win-combos refused, 30 per minute, emergency stop checked, typed text never echoed), `browserx.py` (`browser_extract`: https + allowed host, redirects off the allowlist refused, output tainted and injection-scanned).
- Kinds are off until the owner enables them in the local policy. Risk: screenshot high, input_control high (phone PIN every time, never "allow"), browser_extract medium.
- Server: `phase26f.up/down.sql` + migration `2026_12_31_400000_pc_screen_input.php` (stricter `input_control` parameter checks: click now needs integer x,y; `pc_target_ok` wraps the old one as `pc_target_ok_base`). Planner may emit `screenshot` as evidence; it never plans input/browser/commands.
- Tests: pc-agent 161, DB 1841, ai-engine 456 pass. NOT verified: any real screenshot/mouse/keyboard/browser run, Windows, JavaScript-rendered pages (plain HTML only), reading the screenshot with a vision model, UI for these kinds in mobile/desktop.

## 0.121.0 — Phase 5: hands-free voice (wake phrase), logic tested, hardware untested
- `public/js/voice-session.js`: wake-phrase matcher (BN+EN), end-pointer, voice state machine (consent every visit, drop non-wake speech, mic indicator, barge-in, stop words, cooldown, stopAll), `avatarFor()`, `VOICE_CAN_APPROVE=false`. Node test with fake adapters (`tests/js/voice-session.test.js`) passes.
- `public/js/voice-browser.js` + "Hands-free" panel on /app/voice (consent text states that Chrome's recognition leaves the computer). docs/VOICE.md.
- NOT done / NOT verified: no real browser or microphone run; no local wake-word engine; nothing in the desktop (Tauri) or mobile apps; avatar not wired to the voice page.

## 0.120.0 — Phase 4: Companion mode / Agent mode
- `phase26e.up/down.sql` + migration `2026_12_31_300000_user_modes.php`: per-person mode. **Companion** = chat/voice only: new jobs and plans are blocked (`companion_mode`), approved plans are not handed to the agent, switching in cancels everything of theirs that was waiting. **Agent** = Computer Control rules as before. No row = Agent, so nothing changes until the person chooses. `user_mode_set` validates mode, membership and that the companion's character exists in the workspace.
- Chat: `ModeRules` honesty text added to the system prompt per mode (companion never claims computer actions; agent claims nothing the app did not report); a new companion chat with no character picked starts with the companion's own character. A failing mode lookup never stops chat.
- UI: Mode switch at the top of /app/computer; `POST /app/mode`.
- Tests: 12 DB tests (`tests/db/test_user_modes.py`, PostgreSQL 16; DB group 155 pass), `tests/Unit/CompanionModeTest.php` (5, executed with a small stand-in runner, not Pest). Also fixed the stale kinds/risk test in `PcWebTest`.
- NOT done: mode switch in the Expo/Flutter/Tauri apps; wake word, voice input on desktop/mobile, avatar in the desktop shell (need real devices).

## 0.119.0 — Phase 3: approved plans reach the agent with signatures
- `phase26d.up/down.sql` + migration `2026_12_31_200000_pc_plans.php`: `pc_plans`, `pc_plan_step_approvals`, `pc_plan_submit/decide/step_approve/next/report`, `mobile_pc_plan_step_approve`, `pc_hmac_sha256` (built-in sha256, no extension). Every step checked against the device policy; high/critical steps can ONLY be approved on the phone with the PIN step-up (website gets `mobile_required`); plans immutable, undeletable; emergency stop blocks/stops plans.
- Plans are signed on pull (HMAC-SHA256, key = sha256(device token), bound to device+plan+exact text, 5-minute expiry, handed out once). Agent `serverplan.py` verifies the exact text, refuses wrong device/expired/replayed/tampered/mismatching step text, then mints local approvals and runs the normal engine (local policy still applies). Runner pulls plans after actions; older servers (404) are ignored.
- Laravel: `PcPlanRepo`/`PgPcPlanRepo`, agent API `GET plans/next`, `POST plans/{id}/report`, web submit/decide/step routes + "Plans" section on /app/computer, phone API `GET pc/plans`, `POST pc/plans/{id}/step`.
- Tests: 22 DB tests (`tests/db/test_pc_plans.py`, real PostgreSQL 16), 13 agent tests (`tests/test_serverplan.py`). NOT run: Pest, Blade render, mobile app screens (no UI for plan step approval in Expo/Flutter yet), real agent against real server.

## 0.118.0 — Phase 2: planner (goal -> validated draft plan)
- ai-engine `POST /internal/v1/plan` (`app/planner/plan.py`, `app/routes_planner.py`): model output is untrusted; strict validation (kinds whitelist without run_command/browser/input/power, params schema, absolute paths inside folders, no `..`, refs only backward, <=12 steps, retries 0-2, our own plan id), one retry with the reason, refusal when no safe folder, goal/file text marked as data. 29 new tests; ai-engine suite 456 pass (provider mocked).
- Agent: `task plan.json --dry-run` (shows approval points, runs nothing; accepts the planner reply as saved).
- NOT done: Laravel does not call the planner yet and there is no web review page (needs the web<->agent channel; moved to Phase 3). No real LLM was called.

## 0.117.0 — Phase 1: server rules for the six file/document kinds
- `phase26c.up/down.sql` + migration `2026_12_31_100000_pc_file_kinds.php`: risk (copy/mkdir/xlsx/docx medium; move/trash high, never allow), both ends of copy/move scoped to allowed folders.
- `PcService` KINDS + one-line `a -> b` form for copy/move. DB tests `tests/db/test_pc_file_kinds.py` (5) + existing pc tests: 108 pass on real PostgreSQL 16. Agent↔SQL risk consistency test. Pest not run (no vendor here).
- Agent: move_file is now high risk (matches server).

## 0.116.0 — CAN AI handover: Milestone A audit + Milestone C local slice
- docs/CAN_AI_GAP_AUDIT.md (F01–F30 matrix, threat model, risks).
- pc-agent: task engine (plan→approve→act→verify→report, resume, checkpoints), signed single-use step approvals, hash-chained audit, emergency stop (`stop`/`resume`/STOP file, child kill, runner pause), prompt-injection block + taint, file ops (copy/move/mkdir/reversible trash), XLSX/DOCX create+verify, CLI `task`.
- 28 new tests (pc-agent suite: 118 pass). Local-only; not Windows-verified; server does not queue the new kinds yet.

## 0.115.0
- Menu: new "All pages" section in the sidebar — every page of Accounting, Marketing, Leads, Shop & orders and Billing, grouped and collapsible (opens while you search).
- UI: dark premium theme (shared layouts, responsive menu drawer, menu search Ctrl/Cmd+K) and a header on every page, from the supplied UI refinement package; auto-generated titles ("Show.Blade") replaced; the external Alpine CDN script removed (not used). See docs/ui/. Fix: the supplied package had put the page header inside the opening `<x-layouts…>` tag of 35 pages (all admin pages → 500 "unexpected endif"); moved after the tag and the tags restored byte-for-byte.

## 0.114.1
- Fix: `Unresolvable dependency … CreditPricing::$rateIn` (500) — CreditPricing is now bound in the container with its configured rates.
- Fix: rebuilding the image published the Spatie/Sanctum migrations again under a new timestamp; they tried to create existing tables and stopped every later migration (skills, jobs, shop tables stayed missing → 500). They now skip when the table exists.
- Tools: new upload box (drag and drop, previews, front/back slots, order and remove) on every tool and the Photoshop AI agent.
- Deploy: `public/storage` symlink created at image build (fixes `symlink(): Permission denied`); `selfhost/diag.sh` prints the real error behind a 500.
- Admin settings: CAD added to the default-currency list.

## [0.114.0] — 2026-11-13 — 🧭 Journey: সহকারী কী জানে ও কী শিখেছে (Hermes `/journey`-এর মতো)
- সাইডবার → **Journey** (`/app/journey`): মেমোরি, স্কিল (প্রতিটি ভার্সন, অনুমোদনের অপেক্ষায় থাকা প্রস্তাবসহ) ও শিডিউল জব এক টাইমলাইনে, নতুন আগে; ধরন অনুযায়ী ফিল্টার ও সার্চ। প্রতিটি আইটেম থেকে সংশ্লিষ্ট পেজে যাওয়া যায়।
- স্বচ্ছতা: "প্রতিটি চ্যাটে AI-কে যা বলা হয়" তালিকা দেখায় (মেমোরি বন্ধ থাকলে কাটা দাগসহ)।
- আপনার ডেটা আপনার: মেমোরি + স্কিল **Markdown/JSON** হিসেবে ডাউনলোড।
- পেজটি শুধু পড়ার; বদলানো হয় Memory/Skills/Scheduled jobs পেজে। নতুন ডাটাবেস মাইগ্রেশন নেই।
- মেমোরি সেন্টারে (আগে থেকেই): এডিট, পিন, বন্ধ/চালু, মোছা, সংবেদনশীল তথ্য আটকানো, AI-প্রস্তাব (আপনি মানলে তবেই সেভ) — এগুলো বদলানো হয়নি।
- **যাচাই:** টাইমলাইন ও এক্সপোর্টের ২টি টেস্ট পাস; Laravel/Blade চালানো যায়নি।

## [0.113.0] — 2026-11-13 — 🧠 Skills (Hermes-এর "নিজে-শেখা স্কিল"-এর মতো, অনুমোদনসহ)
- সাইডবার → **Skills** (`/app/skills`): স্কিল = সেভ করা পদ্ধতি। চ্যাট, Telegram, WhatsApp ও শিডিউল জবে `/স্কিল-নাম আপনার অনুরোধ` লিখলে AI সেই ধাপগুলো অনুসরণ করে।
- **AI শেখে:** আপনার কোনো চ্যাট বা বর্ণনা থেকে AI স্কিলের **খসড়া** বানায় (নাম বদলে প্লেসহোল্ডার, ব্যক্তিগত তথ্য ছাড়া)। আপনি পড়ে/বদলে **Save** চাপলে তবেই সেভ হয়।
- **উন্নত হয়:** স্কিল চালিয়ে 👍/👎 + মন্তব্য দিন → "Suggest an improvement" → AI নতুন ভার্সনের **প্রস্তাব** দেয়; আপনি Approve না করলে ব্যবহার হয় না। প্রতিটি পরিবর্তন নতুন ভার্সন, পুরনোটায় Restore করা যায়।
- নিরাপত্তা: স্কিল শুধু আপনার নিজের (অন্য ইউজার/শপ দেখে না); স্কিল আপনার চ্যাটের বাইরের কোনো টুল চালাতে পারে না; AI-র কাজ ক্রেডিট বুকিং/সেটেলের স্বাভাবিক নিয়মে (`PaidCompletion`)।
- নতুন: `phase32a` মাইগ্রেশন (আগে ব্যাকআপ), `App\Skills\*`, `SkillController`।
- **সীমা:** চ্যাটে `/নাম` ব্যবহার করলে সংরক্ষিত বার্তায় পুরো নির্দেশসহ লম্বা টেক্সট থাকে (ইতিহাসে লম্বা দেখাবে)। মোবাইল অ্যাপ API-তে `/নাম` এখনো নেই। AI-খসড়ার মান মডেলের উপর নির্ভর করে — তাই অনুমোদন-ধাপ। Laravel/Blade/ডাটাবেস স্যান্ডবক্সে চালানো যায়নি; শুধু টেক্সট-নিয়মের টেস্ট।

## [0.112.0] — 2026-11-13 — ⏰ শিডিউল করা AI কাজ (Hermes-এর cron-এর মতো)
- সাইডবার → **Scheduled jobs** (`/app/jobs`): কাজ + সময় লিখুন ("প্রতিদিন সকাল ৯টায়", "every Monday 8am", "প্রতি মাসের ৫ তারিখ", "every 2 hours", "কাল বিকাল ৪টায়")। AI সময়মতো চালিয়ে উত্তর অ্যাপ-ইনবক্স ও লিংক করা Telegram/WhatsApp-এ পাঠায়।
- প্রতিটি রান সাধারণ চ্যাট-টার্ন (একই মেমোরি, একই ক্রেডিট, একই সীমা)। "Run now", Pause/Resume, রানের ইতিহাস। কমপক্ষে ১৫ মিনিট ব্যবধান; ইউজারপ্রতি ২০টি।
- নিরাপত্তা: কাজ চালানোর **আগে** পরের সময় এগিয়ে নেওয়া হয় (ক্র্যাশ হলেও দুবার চলে না); ৫ বার টানা ব্যর্থ (যেমন ক্রেডিট শেষ) হলে নিজে থেমে যায়; ব্যর্থতার খবর ইনবক্সে আসে।
- নতুন: `phase31a` মাইগ্রেশন (আগে `selfhost/backup.sh`), `AutomationServiceProvider` (প্রতি মিনিট `jobs:run-due`), `App\Automation\{ScheduleParser,NextRun,JobRunner}`।
- **সীমা:** সময়-পার্সার নিয়মভিত্তিক — না বুঝলে অনুমান না করে জিজ্ঞেস করে। কাজ কতটা "বুদ্ধিমান" তা আপনার AI চ্যাটের ক্ষমতার সমান (চ্যাট যে ডেটা/টুল দেখতে পায় সেটুকুই)। পেজ/Blade/ডাটাবেস স্যান্ডবক্সে চালানো যায়নি; শুধু পার্সার ও সময়-হিসাবের টেস্ট।

## [0.111.0] — 2026-11-12 — OCR, কনভার্ট, জেনারেটর টুলস + ✨ Photoshop AI agent
- **Photoshop AI agent** (`/app/photoshop`): বাংলা/ইংরেজিতে লিখুন + ফাইল দিন → এজেন্ট ঠিক টুল ও সেটিং বেছে চালায় (নিয়মভিত্তিক, টুল বানিয়ে বলে না)। "Just show the plan" বাটনে চালানোর আগে দেখা যায়। ID কার্ড/ক্রপ ইত্যাদি ফর্ম-নির্ভর টুলে পূরণ-করা ফর্ম খোলে।
- নতুন টুল (মোট ৪৩): OCR (ছবি/স্ক্যান PDF → টেক্সট; বাংলা, ইংরেজি), Handwriting to Text (AI ভিশন, ক্রেডিট লাগে), Image→Word, PDF→Word, PDF→Excel, Document Translation (AI, ক্রেডিট; Word ফাইল দেয়), Photo Enhancement, QR Code, Barcode (Code128/39, EAN-13/8, UPC-A), Signature Maker (ছবি বা টাইপ করা নাম → স্বচ্ছ PNG), Watermark (ছবি/PDF), ID Card Maker (85.6×54 মিমি, ছবিসহ), Business Card Maker।
- ইঞ্জিন: `app/doctools/extra.py`; Docker ইমেজে tesseract (eng+ben)। টেস্ট: ইঞ্জিন ২৪টি, PHP: ক্যাটালগ/এজেন্ট প্ল্যানার/অনুবাদ চাঙ্ক টেস্ট।
- **সীমা:** "AI" নামের Enhancement/Background/Cleanup আসলে স্বয়ংক্রিয় ইমেজ প্রসেসিং (জেনারেটিভ AI নয়); PDF→Word/Excel শুধু টেক্সট/সরল টেবিল, লেআউট থাকে না; অনুবাদ ~১২,০০০ অক্ষর পর্যন্ত। বাংলা OCR স্যান্ডবক্সে যাচাই হয়নি (এখানে ben ডাটা নেই), Laravel/Blade চালানো হয়নি।

## [0.110.0] — 2026-11-12 — ডকুমেন্ট ও ফটো টুলস (`/app/tools`)
- সাইডবার → **Doc & photo tools**, ফ্রি (AI ক্রেডিট লাগে না), ফাইল সার্ভারে রাখা হয় না: ২৯টি টুল।
- **স্ক্যান:** NID সামনে-পেছনে এক A4-এ, ড্রাইভিং লাইসেন্স, পাসপোর্ট, জন্মনিবন্ধন (ঐচ্ছিক ব্যাকগ্রাউন্ড ক্লিনআপ)।
- **PDF/ডকুমেন্ট:** Document→PDF, Image→PDF, PDF→Image, Merge, Split, Compress, Rotate, Crop, A4 page maker, Document resize (KB লিমিটসহ), Background cleanup, এক পাতায় একাধিক ডকুমেন্ট।
- **ফটো:** পাসপোর্ট সাইজ মেকার (35×45 মিমি, 2×2 ইঞ্চি, কাস্টম), ক্যামেরা থেকে তোলা, ফেস পজিশন (জুম/সরানো), এক শিটে অনেক কপি (4R/5R/6R/A4), ব্যাকগ্রাউন্ড রিমুভ ও রঙ বদল, ক্রপ/রিসাইজ/রোটেট।
- ইঞ্জিন: `app/doctools/ops.py`, রুট `/internal/v1/doctools/run`; Laravel: `DocToolsController`, `DocTools\Catalog`। ইঞ্জিন টেস্ট ১৩টি পাস।
- **সীমা (সৎভাবে):** ব্যাকগ্রাউন্ড রিমুভ/রঙ বদল শুধু সমতল ব্যাকগ্রাউন্ডে ভালো কাজ করে (ব্যক্তি-কাটআউট AI মডেল নেই); ফেস পজিশন নিজে হাতে ঠিক করতে হয় (অটো ফেস-ডিটেক্ট নেই); Word→PDF-এর জন্য ইঞ্জিন ইমেজে LibreOffice লাগে (`WITH_OFFICE=0` দিলে বাদ)। Laravel পেজ/ব্লেড স্যান্ডবক্সে চালানো যায়নি।

## [0.109.0] — 2026-11-12 — শপ প্ল্যাটফর্ম + অ্যাডমিন AI সহকারী
- **শপ (ইউজার):** Shop profile (নাম, ঠিকানা, ফোন, ইমেইল, লোগো আপলোড ≤1MB), নতুন শপ খুললে প্রোফাইল পেজে নেওয়া, Support (টিকিট খোলা/উত্তর/বন্ধ)।
- **অ্যাডমিন:** ড্যাশবোর্ডে মোট/অ্যাক্টিভ/ইনঅ্যাক্টিভ শপ, স্টাফ, চলমান ট্রায়াল, খোলা টিকিট, মাসিক আয়; Support tickets, Free trials (দেওয়া/বাড়ানো/শেষ), Usage monitoring, Announcements।
- **✨ AI সহকারী** (অ্যাডমিন সাইডবার): বাক্যে প্রশ্ন (শপ সংখ্যা, আয়, টিকিট, ট্রায়াল, ব্যবহার, শপ খোঁজা) ও কমান্ড (suspend/activate shop, start/extend trial, announce)। পরিবর্তনমূলক কমান্ড আগে সারাংশ দেখায়, **Confirm** চাপলেই চলে; অ্যাডমিনের নিজের পারমিশন ও অডিট লগ প্রযোজ্য।
- নতুন মাইগ্রেশন `phase30a` (আগে `selfhost/backup.sh` চালান)। সহকারী নিয়মভিত্তিক (LLM নয়) — পরে LLM যুক্ত করা যাবে।
- **যাচাই হয়নি:** Laravel/Postgres/Blade স্যান্ডবক্সে চালানো যায়নি; শুধু `php -l` ও পার্সার টেস্ট।

## [0.108.0] — 2026-11-11 — মোবাইল অ্যাপ Flutter-এ (`apps/mobile_flutter`)
- নতুন Flutter অ্যাপ, একই সার্ভার API (`/api/v1`): লগইন + **QR স্ক্যান**, Home (ক্রেডিট, ইমার্জেন্সি স্টপ), Chat, Approvals (PIN step-up), Monitor, My Computer, Security/PIN, Notifications, Usage, Health, Workspaces। ডার্ট-টেস্ট: QR পার্সিং, PIN নিয়ম, ফরম্যাটিং।
- পুরনো Expo অ্যাপ (`apps/mobile`) আপাতত রাখা আছে; Flutter অ্যাপ যাচাই হলে মুছে ফেলা যাবে।
- Flutter-এ এখনো নেই: পুশ নোটিফিকেশন (Firebase লাগবে), ফিঙ্গারপ্রিন্টে PIN। ধাপে ধাপে সেটআপ: `apps/mobile_flutter/README.md`।
- **যাচাই হয়নি:** স্যান্ডবক্সে Flutter/Dart SDK নামানো যায় না, তাই কোড কম্পাইল বা টেস্ট চালানো হয়নি — প্রথম `flutter pub get && flutter analyze && flutter test` চালিয়ে যা আসে জানালে ঠিক করা হবে।

## [0.107.1] — 2026-11-11 — মোবাইল অ্যাপ QR কোডে কানেক্ট
- ওয়েব: সাইডবার → **Mobile app** (`/app/settings/mobile`): পাসওয়ার্ড কনফার্ম করে QR দেখানো (৫ মিনিট, একবারই চলে, `canai://pair?server=…&code=…`), কানেক্টেড ডিভাইসের তালিকা ও Disconnect। QR ব্রাউজারে আঁকা হয় (`public/js/qrcode.js`, MIT), তাই নতুন Composer প্যাকেজ লাগে না।
- API: `POST /api/v1/auth/pair` (কোড দিয়ে ৩০ দিনের টোকেন; কোড Cache থেকে `pull` হয়, তাই একবারই কাজ করে; `api-auth` থ্রটল)। লগইনের টোকেন-তৈরি অংশ `issue()`-তে ভাগ করা হয়েছে (আচরণ অপরিবর্তিত)।
- মোবাইল: লগইনে "Scan QR code" বাটন, `app/scan.tsx` (expo-camera), `parsePairing` (শুধু https ও ৪০-হেক্স কোড মানে)। নতুন নেটিভ ডিপেন্ডেন্সি `expo-camera` → অ্যাপ নতুন করে বিল্ড (`eas build`) করতে হবে; পুরনো APK-তে স্ক্যান আসবে না। `scheme` এখন `canai` ও `jarvisone`।
- যাচাই: মোবাইল tsc পরিষ্কার, vitest ৩৬টি পাস; PHP/Blade অংশ `php -l` ও QR-লাইব্রেরি Node-এ চালিয়ে দেখা — আসল Laravel/ফোনে চালানো হয়নি।

## [0.107.0] — 2026-11-11 — WhatsApp চ্যানেল (টেক্সট + ভয়েস কমান্ড)
- নতুন: WhatsApp Cloud API চ্যানেল — ইউজার WhatsApp-এ টেক্সট/ভয়েস নোট পাঠিয়ে CANAI-কে প্রশ্ন বা workflow কমান্ড দিতে পারে ("run <workflow>")। ভয়েস `VoiceService::listen` দিয়ে ট্রান্সক্রাইব (ক্রেডিট কাটে), ট্রান্সক্রিপ্ট ফেরত দেখানো হয়।
- Admin → Chat channels: WhatsApp নম্বর যোগের ফর্ম, Callback URL + Verify token দেখানো, Activate/Deactivate। সিক্রেট এনক্রিপ্টেড বান্ডেলে (`WhatsAppCredentials`)।
- ইউজার পেজ `/app/settings/whatsapp` + সাইডবার লিংক; একজন ইউজার Telegram ও WhatsApp দুটোই লিংক করতে পারে।
- ওয়েবহুক `webhooks/whatsapp/{account}` (GET হ্যান্ডশেক, POST সিগনেচার `X-Hub-Signature-256` যাচাই), `ChannelRouter` চ্যানেল অনুযায়ী পাঠায়; Telegram ভয়েসও এখন একই পথে।
- মাইগ্রেশন `phase29a` (দুটি CHECK প্রসারিত + একটি রিড ফাংশন)। মাইগ্রেট করার আগে `selfhost/backup.sh`। বিস্তারিত: `docs/WHATSAPP.md`।
- যাচাই: normalizer/signature/ভয়েস টেস্ট পাস (Laravel ছাড়া রানার)। আসল Postgres/Meta-র সাথে চালানো হয়নি।

## [0.106.0] — 2026-11-10 — ওয়েব UI সম্পূর্ণ ইংরেজি + বাম পাশের মেনু
- ওয়েব ওভারলে (views, controllers, services, JS, config) এর সব দৃশ্যমান লেখা ইংরেজি; সংখ্যা ASCII (0-9)।
- `components/layouts/app.blade.php` এখন বাম পাশের সাইডবার মেনু (ছোট স্ক্রিনে ভাঁজ হয়)।
- ইচ্ছা করে বাংলা রাখা: Proposals/Reports-এর `bn` ডকুমেন্ট ভাষা, ট্রিগার/ইমোশন/হ্যাঁ-না/মেমরি-গার্ড/ভয়েস কীওয়ার্ড, CSV হেডার অ্যালিয়াস, ঢাকা-এরিয়া শনাক্তকরণ (এগুলো লজিক)।
- সাইটের নাম এখন **CANAI** (`config/brand.php`; Admin → Settings → Branding থেকেও বদলানো যায়)।
- নতুন Admin → Settings → "Site, SEO, menu and footer": SEO title/description/keywords/share image, indexing on/off, বাম মেনুতে এক্সট্রা লিংক, ফুটার টেক্সট ও লিংক (`App\Support\SiteMeta`; শুধু http(s)/relative URL গ্রহণ হয়)। app/guest/admin লেআউটে ফুটার আছে।
- নতুন পাবলিক ফ্রন্ট পেজ `/` (হিরো, ফিচার, প্ল্যান — Admin → Plans থেকে, লগইন/সাইন-আপ, ফুটার); লগইন পেজ ও সাইডবারের ডিজাইন উন্নত।
- মেরামত: অনুবাদের পর ১৬টি ভিউতে `শব্দ@if`/`@case` লেখায় Blade নির্দেশনা চিনছিল না (developer, plugins, pc ইত্যাদি পেজে 500) — নির্দেশনার আগে স্পেস বসানো হয়েছে। সুপার অ্যাডমিনের জন্য সাইডবারে Admin লিংক।
- নতুন Admin → AI Models পেজ: সব মডেলের তালিকা + যেকোনো প্রোভাইডারে নতুন মডেল যোগের ফর্ম।
- মোবাইল অ্যাপ: নাম CANAI, সব লেখা ইংরেজি, সংখ্যা 0-9 (vitest 34টি পাস, tsc পরিষ্কার)। বান্ডেল ID (`com.jarvisone.app`) ইচ্ছা করে অপরিবর্তিত।
- বাকি (এই সংস্করণে হয়নি): মোবাইল অ্যাপ, ডেস্কটপ, ai-engine প্রম্পট, SQL সিড ডেটার বাংলা; ~148টি ইউনিট-টেস্ট অ্যাসার্শন এখনো পুরনো বাংলা স্ট্রিং আশা করে (শুধু টেস্ট, রানটাইম নয়)।

## [Unreleased]
- ডিপ্লয় মেরামত: `ai-engine.Dockerfile`-এ `ffmpeg` ও `libfribidi0` যোগ (আগে ভিডিও রেন্ডার ও বাংলা ডিজাইন Docker ইমেজে চলত না)।
- মেরামত: মোবাইলে `react-dom` 19.3.0 পিন (প্লেইন `npm ci` আর ERESOLVE-তে ফেল করে না); ইঞ্জিনের dev ডিপেন্ডেন্সিতে `reportlab`, `hypothesis`, `psycopg`, `types-openpyxl` যোগ; `mypy --strict` এখন পরিষ্কার (২টা আসল টাইপ-সমস্যা সারানো: `bills/extract.py`, `studio/compose.py`, আচরণ অপরিবর্তিত); `scripts/runpure.php` ও `scripts/runpkg.php` (Laravel ছাড়া PHP টেস্ট রানার) যোগ; `DEVELOPMENT_STATUS.md`-র ফেজ টেবিল হালনাগাদ।

## [0.105.2] — 2026-11-10 — প্রথম আসল বিল্ডে পাওয়া ত্রুটি
- আসল সার্ভারে `docker compose build` ফেল: `laravel/reverb` (guzzlehttp/psr7 ^2.6) বনাম Laravel 13 স্কেলিটনের psr7 3.2.0। Reverb আমাদের কোডে কোথাও ব্যবহৃত নয় → `bootstrap-web.sh`-এ Reverb এখন ঐচ্ছিক (`-W` দিয়ে চেষ্টা, না পারলে বাদ); `install:broadcasting` শুধু Reverb থাকলে। এটাই আসল Packagist-এ প্রথম রান — এরপরের ধাপেও নতুন ত্রুটি আসতে পারে।

## [0.105.3] — 2026-11-10
- আসল বিল্ডে দ্বিতীয় ত্রুটি: dev-টুল (`pestphp/pest` …) একই ধরনের সংস্করণ-সংঘাত। প্রোডাকশন ইমেজ `--no-dev` তাই এগুলো ঐচ্ছিক (`-W` দিয়ে চেষ্টা, না পারলে বাদ); `pest --init` শুধু থাকলে।

## [0.105.4] — 2026-11-10
- আসল বিল্ডে তৃতীয় ত্রুটি (এবার আমাদের কোড): `Sanctum::ignoreMigrations()` Sanctum 4+-এ নেই (সেটি আর নিজের মাইগ্রেশন লোড করে না) → `TenancyServiceProvider`-এ `method_exists` পাহারা। dev-টুল ঐচ্ছিক ধাপ ঠিকঠাক পার হয়েছে (pest 4.7, larastan 3.14)।

## [0.105.5] — 2026-11-10
- আসল বিল্ডে চতুর্থ ত্রুটি: `-W` দিয়ে Reverb বসে গেলে `install:broadcasting --reverb` `routes/channels.php` বানায় আর REVERB_* কী ছাড়া প্রতিটি `artisan` ব্যর্থ হয়। আমাদের কোডে ব্রডকাস্ট নেই → Reverb পুরোপুরি বাদ, `BROADCAST_CONNECTION=log`।

## [0.105.6] — 2026-11-10
- আসল VPS (Intel Ivy Bridge, avx2/bmi নেই): `vite build` → "Illegal instruction". কারণ Vite 8-এর Rolldown নেটিভ বাইনারি (এখানে qemu `-cpu IvyBridge`-এ হুবহু পুনরুৎপাদিত; tailwind-oxide ও lightningcss ঠিক ছিল)। সমাধান: `bootstrap-web.sh` Vite ^7 + laravel-vite-plugin ^2 পিন করে — একই emulated CPU-তে tailwind সহ বিল্ড সফল (নমুনা প্রজেক্টে; আসল Laravel 13 স্কেলিটনে নয়)।

## [0.105.7] — 2026-11-10
- Vite 7 পিনের পর: স্কেলিটনের `vite.config.js` `laravel-vite-plugin/fonts` আমদানি করে (শুধু plugin 3.x-এ, যার peer `vite ^8`) → `bootstrap-web.sh` এখন সরল `vite.config.js` লেখে। একই কনফিগ emulated Ivy Bridge CPU-তে বিল্ড পাস।

## [0.105.8] — 2026-11-10
- আসল সার্ভারে বিল্ড সফল (প্রথমবার!), কিন্তু `migrate` ধাপে `pull access denied for jarvis-web`: কোনো সার্ভিসই `jarvis-web:<ver>` ইমেজ বানাত না (শুধু `nginx` `build:` ছিল)। `web` সার্ভিসে `build: target app` যোগ; horizon/scheduler/migrate একই ইমেজ ব্যবহার করে। `docker compose config` যাচাই (দ্রুত, ক্যাশ থেকে)।

## [0.105.1] — 2026-11-10 — cPanel/WHM VPS-এ চালানো
- `install.sh --behind-proxy PORT` (--domain সহ): Caddy ছাড়া, অ্যাপ শুধু `127.0.0.1:PORT`-এ; ভুল মান/পোর্ট<1024/domain ছাড়া প্রত্যাখ্যান। নকল docker দিয়ে ৬ ধরনের কল পরীক্ষিত।
- `scripts/bootstrap-web.sh`: `bootstrap/app.php`-এ `trustProxies(at: "*")` প্যাচ (আগে কোনো প্রক্সির পেছনে HTTPS URL/কুকি ভুল হতে পারত — Caddy পথেও); দুই নমুনা ফাইলে প্যাচ ও ভিন্ন আকারে ব্যর্থতা পরীক্ষিত, আসল Laravel 13 স্কেলিটনে নয়।
- `selfhost/CPANEL.md` — ধাপে ধাপে গাইড; Apache প্রক্সি/AutoSSL/আসল cPanel যাচাই হয়নি (গাইডে বলা আছে)।

## [0.105.0] — 2026-11-10 — ডেস্কটপ অ্যাপ (Tauri) + আসল-ব্রাউজার যাচাই
- নতুন `apps/desktop` (Tauri 2): ট্রে আইকন, নেটিভ জানালা, এজেন্ট চালানো/বন্ধ; URL কঠোর যাচাই (Rust টেস্ট ৩); পাতা কোনো Tauri API পায় না।
- এজেন্ট: `gui --exit-with-parent` (অভিভাবক অ্যাপ মরলে নিজে বন্ধ; টেস্ট ২ + মিউটেশন ২ মারা)।
- GUI-র আসল Chrome e2e (`tests/e2e/gui.e2e.cjs`, ১৫ ধাপ) — পেয়ারিং/নিয়ম/চালু/ইতিহাস/বন্ধ, নিয়ন্ত্রণের নাম, ৪৪px বোতাম, ৩৮০px-এ অনুভূমিক ওভারফ্লো নেই। এতে ২টি চেহারার ত্রুটি ধরা পড়ে সারানো হয়েছে (সেকশনের ফাঁক, নিষ্ক্রিয় বোতামের চেহারা) + সফল যোগের পর ইনপুট খালি।
- `.github/workflows/desktop-agent.yml`: এজেন্ট টেস্ট ও Tauri বিল্ড Linux/Windows/macOS-এ, আর GUI e2e।
- যাচাই হয়নি: Windows/macOS (CI চালাতে হবে), আসল Laravel (এখানে বসানো যায়নি), ট্রে মেনুর ক্লিক, ইনস্টলার/সাইনিং।

## [0.104.0] — 2026-11-10 — ডেস্কটপ এজেন্টের স্থানীয় জানালা (`jarvis-agent gui`)
- নতুন: `jarvis_agent/gui.py` — শুধু `127.0.0.1`-এ ওয়েব-জানালা: হোম (পেয়ারিং, চালু/বন্ধ, প্রসেসর/মেমরি/ডিস্ক), নিয়ম (ফোল্ডার/সাইট/অ্যাপ/কমান্ড, কাজের ধরন), ইতিহাস। অনুমোদন দিতে পারে না। সুরক্ষা: এলোমেলো টোকেন হেডারে, Host/Origin যাচাই, CSP, বডি-সীমা।
- নতুন: `policy.py` (নিয়ম বদলানোর একটি জায়গা, CLI ও জানালা দুটোই ব্যবহার করে), `history.py` (স্থানীয় ইতিহাস, ২০০টি, ফাইল 0600); `runner.run`-এ `on_job` ও `should_stop`; CLI `run` এখন ইতিহাসও লেখে।
- টেস্ট: ৫২ → ৮৮ (গার্ড, নীতি-প্রত্যাখ্যান, চলাকালীন নিয়ম বদল, প্রত্যাহৃত ডিভাইস, ইতিহাস সীমা)। মিউটেশন: টোকেন/Host/Origin/বডি-সীমা/'/' নিষেধ/অজানা ধরন/CSP/দ্বিতীয় লুপ/0600/loopback-বাইন্ড সব মারা গেছে (শেষটা প্রথমে বেঁচে গিয়েছিল → `bind()` বের করে টেস্ট যোগ); `KEEP` মিউটেশন টেস্ট ঝুলিয়ে টাইমআউটে ধরা পড়ে।
- যাচাই হয়নি: আসল ব্রাউজারে পাতার চেহারা/বাটন (JS টেস্ট করা হয়নি, শুধু সার্ভার-দিক), Windows/macOS, আসল Laravel সার্ভার, Tauri/ট্রে-আইকন।

## [0.103.1] — 2026-11-09 — `selfhost/quickstart.sh` (নতুন Ubuntu সার্ভারে এক কমান্ড)
- Docker, ফায়ারওয়াল, `install.sh`, স্বাস্থ্য-পরীক্ষা, প্রতিদিনের ব্যাকআপ-cron একসাথে। `bash -n` পাস; **আসল সার্ভারে চালানো হয়নি**।

## [0.103.0] — 2026-11-09 — কম্পিউটার এজেন্ট (`apps/pc-agent`) — পিসি/ল্যাপটপ/VPS নিয়ন্ত্রণ
- নতুন প্যাকেজ `jarvis-agent` (Python ৩.৯+, কোনো বাইরের নির্ভরতা নেই): `pair`, `policy`, `run`, `status`, `service`। সার্ভারের `/api/pc/v1` প্রোটোকল (pair/next/report/metrics) ধরে কাজ টেনে চালায়; কোনো পোর্ট খোলে না।
- ৭ ধরনের কাজ: read_file, list_dir, write_file (পরমাণবিক), delete_file (শুধু ফাইল; symlink নিজেই মোছে), open_app, browser_navigate, run_command (shell ছাড়া, ৩০ সেকেন্ড)। power/input_control/system_setting সমর্থিত নয় (স্পষ্ট বার্তা)।
- **দ্বিতীয় যাচাই (এজেন্টের নিজের নিয়ম):** লোকাল ফোল্ডার/অ্যাপ/কমান্ড/সাইট তালিকা (ডিফল্ট খালি = কিছুই নয়), symlink/`..` পালানো আটকানো, শুধু read/list/write ডিফল্টে চালু। সার্ভার ভুল উত্তর দিলেও এই মেশিন মানে না।
- সংযোগ: https বাধ্যতামূলক (localhost বাদে), ব্যাকঅফ ২×(সর্বোচ্চ ৬০ সে.), ডিভাইস সরানো হলে নিজে থামে (exit 2), টোকেন ফাইল ০৬০০।
- টেস্ট: ৫২ (আসল HTTP ফেক-সার্ভারে প্রোটোকল, আলাদা প্রক্রিয়ায় CLI end-to-end, symlink-পালানো, shell-ইনজেকশন)। মিউটেশন ১৯টি, ১৬টি ধরা, ৩টি সমতুল্য।
- **যাচাই হয়নি:** Windows/macOS-এ আসল চালানো; আসল Laravel সার্ভারের সাথে সংযোগ (শুধু প্রোটোকল অনুযায়ী ফেক সার্ভার); GUI/Tauri ট্রে অ্যাপ (আলাদা কাজ, এই এজেন্টকে মোড়ক দেওয়া যাবে)।

## [0.102.0] — 2026-11-09 — Phase 25.2: চরিত্রের আওয়াজ (অডিও-সিঙ্কসহ)
- চরিত্র-পাতার "বলে দেখুন"-এ ঐচ্ছিক "আওয়াজসহ" টিক: ওয়ার্কস্পেসের বিদ্যমান `/app/voice/speak` (ক্রেডিট খরচ হয়, টিক না দিলে খরচ নেই) থেকে আওয়াজ আনে; আসল অডিওর দৈর্ঘ্য দিয়ে মুখের টাইমলাইন বানায়; `AvatarPlayer.playSynced` অডিওর নিজের ঘড়ি (`currentTime`) ধরে মুখ নাড়ায় — থামালে/শেষ হলে/ব্রাউজার প্লে আটকালে শান্ত মুখ।
- `CharacterVoice::pick`: চরিত্রের `voice` শব্দ জানা হলে সেটি, নইলে ধরন অনুযায়ী ডিফল্ট (পুরুষ onyx, নারী nova, কাস্টম alloy)।
- টেস্ট: `AvatarTest` ১১, node প্লেয়ার টেস্ট (সিঙ্ক ও ব্যর্থতার পথসহ; ৫টি মিউটেশন ধরা)।
- **সীমা:** মুখ নড়া এখনো অক্ষর-অনুমানের টাইমলাইন, অডিও-তরঙ্গ বিশ্লেষণ নয় (অডিও-ঘড়িতে সিঙ্ক, তাই দৈর্ঘ্য মেলে, প্রতিটি ধ্বনি নয়); আসল ব্রাউজারে অডিও চালানো ও Blade এখানে চালানো হয়নি; চরিত্রের কাস্টম ভয়েসের নাম (যেমন ক্লোন করা কণ্ঠ) এখনো সমর্থিত নয়; মার্কেটপ্লেস ও ৩D বাকি।

## [0.101.0] — 2026-11-09 — Phase 25.2: 2D অবতার, অভিব্যক্তি ও লিপ-সিঙ্ক (প্রিভিউ)
- মৌলিক ২D অবতার (SVG, পুরুষ/নারী/কাস্টম; কোনো বিদ্যমান চরিত্রের অনুকরণ নয়): ৫ অভিব্যক্তি (neutral/happy/sad/surprised/thinking) × ৫ মুখের আকৃতি। আঁকায় শুধু নির্দিষ্ট শব্দ ঢোকে — ব্যবহারকারীর লেখা SVG-তে যায় না।
- `Emotion::detect` (বাংলা/ইংরেজি সূত্রশব্দ থেকে আবেগ), `Visemes::timeline` (লেখা + সময় থেকে মুখের টাইমলাইন; গতি/দৈর্ঘ্যের সীমা), `Visemes::estimateMs`।
- রুট: `GET /app/characters/{id}/avatar.svg?expression=&mouth=`, `POST /app/characters/{id}/cues`। চরিত্র-পাতায় ২D অবতারে "বলে দেখুন" প্রিভিউ (`public/js/avatar-player.js`)।
- ছবিগুলো `rsvg-convert` দিয়ে রেন্ডার করে চোখে দেখা হয়েছে (একটি "টাক মাথা" ত্রুটি পেয়ে চুল ঠিক করা হয়েছে)।
- টেস্ট: `AvatarTest` ১০, node `avatar-player.test.cjs`; মিউটেশন ধরা (২টি সমতুল্য মিউট্যান্ট বাকি)।
- **সীমা (সৎভাবে):** লিপ-সিঙ্ক অক্ষর থেকে অনুমান, অডিও থেকে নয়; আওয়াজ/ভয়েস আউটপুট এখনো নেই; ৩D নেই; ব্রাউজারে প্রিভিউ বোতাম (Blade+JS) ও রুট এখানে চালানো হয়নি; মোবাইল/ডেস্কটপ ক্লায়েন্টে অবতার নেই।

## [0.100.0] — 2026-11-09 — মোবাইল→কম্পিউটার হ্যান্ডঅফ (ফোন থেকে কম্পিউটারকে কাজ দেওয়া)
- মোবাইল API: `GET /api/v1/mobile/pc/devices`, `POST /api/v1/mobile/pc/request` (`device_id`, `kind`, `target`)। ফোন থেকে শুধু ৪ ধরনের কাজ: ফাইল পড়া, ফোল্ডার দেখা, অ্যাপ খোলা, সাইট খোলা (`PcService::MOBILE_KINDS`)। কম্পিউটারের নিয়ম (অনুমোদিত ফোল্ডার/অ্যাপ/সাইট, জরুরি স্টপ) আগের মতোই সিদ্ধান্ত নেয়; অনুরোধ `pending` হয়ে ফোনের তালিকায় আসে, সেখানেই অনুমোদন।
- মোবাইল স্ক্রিন "আমার কম্পিউটার"-এ "কম্পিউটারে পাঠান" কার্ড (কম্পিউটার বাছা, কাজের ধরন, লেখা)।
- টেস্ট: `PcControlTest` +২ (মোট ১৪), vitest +২ (মোট ৩৪); মিউটেশন ধরা। **যাচাই হয়নি:** Laravel কন্ট্রোলার/রুট, আসল ফোন; পাঠানো কাজ আসলে চালাবে যে ডেস্কটপ এজেন্ট, সেটি এখনো লেখা হয়নি।

## [0.99.1] — 2026-11-09 — মোবাইল অ্যাপে "আমার কম্পিউটার" স্ক্রিন
- নতুন স্ক্রিন `app/computer.tsx` (আরও → আমার কম্পিউটার): অপেক্ষমাণ কাজ, ঝুঁকির ব্যাজ, বাংলা সারাংশ, বাকি মিনিট; অনুমোদনে নিশ্চিতকরণ ডায়ালগ + পিন step-up (বিদ্যমান `useSensitive`), বাতিল সরাসরি।
- `src/lib/pc.ts` (বিশুদ্ধ): কাজের নাম, ঝুঁকি, ত্রুটির বার্তা, `summary`, `minutesLeft`; `endpoints.pcActions/decidePc`।
- টেস্ট: vitest +৬ (মোট ৩২), `tsc` পরিষ্কার। **এখানে যাচাই হয়নি:** আসল ফোন/এমুলেটরে স্ক্রিন চালানো, সার্ভারের সাথে আসল সংযোগ।

## [0.99.0] — 2026-11-09 — Phase 26 বাকি: উচ্চ-ঝুঁকির কাজে মোবাইল step-up অনুমোদন
- `high`/`critical` পিসি-অ্যাকশন (ফাইল মোছা, কমান্ড চালানো, ইনপুট নিয়ন্ত্রণ, পাওয়ার) ওয়েব থেকে আর অনুমোদন হয় না (`mobile_required`); ওয়েবে শুধু বাতিল করা যায়। অনুমোদন হয় মোবাইল অ্যাপে, পিন-step-up চালু থাকলে (পুরনো ৫ মিনিটের নিয়ম)।
- নতুন মোবাইল API: `GET /api/v1/mobile/pc/actions` (নিজের অপেক্ষমাণ কাজ), `POST /api/v1/mobile/pc/actions/{id}` (`decision`: approve|reject)। নিজের ডিভাইসের কাজ ছাড়া অন্য কিছু দেখা/সিদ্ধান্ত যায় না (সহকর্মী ও অন্য ওয়ার্কস্পেস আটকানো)।
- দ্বিতীয় স্তর: `pc_actions.decided_via` + টেবিল CHECK + `pc_action_next` — মোবাইল-প্রমাণ ছাড়া উচ্চ-ঝুঁকির কাজ এজেন্টকে দেওয়াই হয় না; `decided_via` একবার বসলে বদলায় না।
- `phase28g.up/down.sql`। টেস্ট: DB ২০ (মিউটেশন ধরা), `PcControlTest` +২।
- **সীমা:** ডাটাবেস জানে মোবাইল পথ (`mobile_pc_decide` — নিবন্ধিত ফোন + পিন লাগে); কোন HTTP রুট `pc_action_decide` ডেকেছে তা জানে না, তাই "ওয়েব উচ্চ-ঝুঁকি অনুমোদন করে না" নিয়ম ডাটাবেসেই বসানো। মোবাইল অ্যাপের স্ক্রিন (React Native) এখনো লেখা হয়নি — শুধু API তৈরি। Laravel কন্ট্রোলার/Blade এখানে চালানো হয়নি।

## [0.98.0] — 2026-11-09 — Phase 21 বাকি কাজ (সমান্তরাল ধাপ, webhook.send, কম্পিউটারের ফাইল-টুল)
- নতুন ধাপ `parallel`: ২–৪টি স্বাধীন শাখা (প্রতিটি সর্বোচ্চ ৫টি ai/tool ধাপ), শেষে `join`। শাখায় শুধু READ/WRITE_OWN টুল (each, অনুমোদন, delay, condition, বাইরে-যাওয়া ও টাকার টুল নয়); শাখাগুলো একে অন্যের ফল পড়তে পারে না; `save_as` আলাদা। **সততার কথা:** শাখাগুলো একটির পর একটি চলে (একই ওয়ার্কারে), সত্যিকারের সমান্তরাল নয় — সুবিধা হলো স্বাধীন ও আলাদা-করে-ব্যর্থ-যাচাইযোগ্য ধাপ। রিস্টার্টে শেষ হওয়া ধাপ আবার চলে না (`__par:` অবস্থা-ভ্যারিয়েবল)।
- `webhook.send` (EXTERNAL — আগে অনুমোদন ধাপ লাগে): নিজের Webhook ঠিকানায় বার্তা; নতুন ইভেন্ট `workflow.message` (`phase28f`), ঘণ্টায় ২০/ঠিকানা, ১০০০ অক্ষর।
- কম্পিউটারের টুল: `pc.read_file`, `pc.list_dir` (WRITE_OWN), `pc.write_file` (EXTERNAL), `pc.result` (READ)। এগুলো শুধু *অনুরোধ* জমা দেয়; পিসি-নিয়ম ও আপনার অনুমোদন (/app/computer) ছাড়া কিছু হয় না — ওয়ার্কফ্লো নিজের অনুরোধ অনুমোদন করতে পারে না।
- নতুন টেমপ্লেট: `personal_parallel_brief`, `developer_file_review`।
- টেস্ট: `WorkflowExtrasTest` ৮০ (মিউটেশন ধরা; একটি অপ্রয়োজনীয় মৃত-কোড সরানো, একটি সমতুল্য মিউট্যান্ট বাকি), DB `test_webhook_message` ১৬।

## [0.97.0] — 2026-11-09 — Phase 9 বাকি কাজ (WhatsApp বাটন, প্রস্তাব PDF সংযুক্তি, IMAP) + ১৩-র ডক সংশোধন
- WhatsApp click-to-chat: ক্যাম্পেইনে চালু করলে মেইলে `wa.me` লিংক (ব্র্যান্ড কিটের ফোন থেকে; বাংলাদেশি/আন্তর্জাতিক নম্বর স্বাভাবিকীকরণ; অচল নম্বরে লিংক বাদ, মেইল আটকায় না; unsubscribe লিংক সবসময় থাকে)।
- প্রস্তাব PDF সংযুক্তি: কাতারে ঢোকার সময় লিডের নিজের সর্বশেষ প্রস্তাব নির্দিষ্ট সংস্করণে (`proposal_stamp`) আটকানো; লিডের প্রস্তাব না থাকলে মেইল বাদ; পাঠানোর মুহূর্তে প্রস্তাব বদলালে/মুছলে মেইল যায় না (`proposal_changed`); PDF বানানো না গেলে পরে আবার চেষ্টা। DB-তে নিয়ম: অন্য লিডের/অন্য ওয়ার্কস্পেসের প্রস্তাব সংযুক্ত করা যায় না।
- MIME multipart (Gmail/Graph/SMTP সব পথে), ১০ MB ও ৩ ফাইলের সীমা, ফাইলের নাম/টাইপ স্যানিটাইজ।
- IMAP: SMTP অ্যাকাউন্টে ঐচ্ছিক IMAP হোস্ট; শুধু-পড়া (EXAMINE + BODY.PEEK), শুধু হেডার ও প্রথম ২০০০ বাইট, কিছু মোছে/সরায়/পড়া চিহ্নিত করে না; TLS/STARTTLS (পাসওয়ার্ডের আগে আপগ্রেড), সার্ভার প্রকাশ্য হোস্ট কি না যাচাই, লগইনে CR/LF/NUL/৮-বিট অক্ষর প্রত্যাখ্যান; রিপ্লাই `In-Reply-To` দিয়ে মেলে।
- `phase28e.up/down.sql`; ফেজ ১৩-র কমেন্ট-রিপ্লাই আসলে ২৩.২সি-তেই হয়ে গিয়েছিল — ডকের ভুল "বাকি" সংশোধন।

## [0.96.0] — 2026-11-09 — Webhook-এ আরও ইভেন্ট (27.2 বাকি)
- নতুন ইভেন্ট: `run.waiting_approval` (নিজের রান অনুমোদনের অপেক্ষায়) ও `pc.action.pending` (নিজের অনুরোধ করা কম্পিউটার-অ্যাকশন অনুমোদনের অপেক্ষায়)। বার্তায় শুধু আইডি/অবস্থা/ধরন/ঝুঁকি — প্যারামিটার, ফাইলের পথ, আউটপুট নয়।
- প্রতিটি ঠিকানায় `events` বাছাই (ডিফল্ট শুধু `run.finished`, তাই পুরনো ঠিকানা অপরিবর্তিত); `webhook_set_events()`; নতুন বাছা ইভেন্ট শুধু বাছাইয়ের পরের ঘটনায় যায় (`events_at`); ডেডুপ কী এখন (ঠিকানা, ইভেন্ট, বিষয়-আইডি); `phase28d.up/down.sql`।
- ডেভেলপার পেজে ইভেন্ট চেকবক্স (`POST /app/developer/hooks/{id}/events`); বার্তা-তালিকায় ইভেন্টের নাম।

## [0.95.0] — 2026-11-09 — Phase 26খ (Computer Control: API + ওয়েব পাতা)
- এজেন্টের API `/api/pc/v1`: `POST pair` (এককালীন কোড → টোকেন, ১০/মিনিট), টোকেনসহ `GET actions/next`, `POST actions/{id}/report`, `POST metrics`; `PcAgentAuth` middleware (কারণ না বলে ৪০১, শেষে টেন্যান্ট-কনটেক্সট পরিষ্কার)।
- ওয়েব `/app/computer`: ডিভাইস জোড়া (কোড একবারই দেখায়), সরানো, নিয়ম (ফোল্ডার/অ্যাপ/কমান্ড/সাইট + কাজভিত্তিক deny/ask/allow; ঝুঁকিপূর্ণ কাজে "স্বয়ংক্রিয়" অপশনই নেই), কাজের অনুরোধ, অনুমোদন/বাতিল, কাজের ইতিহাস, মনিটর (সর্বশেষ নমুনা + সতর্কতা)।
- `PcService::parsePolicy/paramsFor/metricsFrom` (বিশুদ্ধ, টেস্টেড); `PgPcRepo` পড়ার কোয়েরি আসল PG-তে ওয়েব-রোলে চালিয়ে যাচাই।
- টেস্ট: `PcWebTest.php` ১১ (১৭টি mutation ধরা, ১টি ফাঁক পেয়ে শক্ত), DB +১ (মোট ১৭১১)।
- বাকি: আসল ডেস্কটপ এজেন্ট (Tauri) ও ব্রাউজার এজেন্ট, উচ্চ-ঝুঁকিতে মোবাইল step-up, মোবাইল→ডেস্কটপ হ্যান্ডঅফ UI, এজেন্টের নিজস্ব দ্বিতীয় যাচাই।

## [0.94.0] — 2026-11-09 — Phase 26ক (Computer Control, সার্ভারের নিয়ম)
- `phase26a`: `pc_devices`, `pc_pairings`, `pc_policies`, `pc_actions`, `pc_metrics` (RLS+FORCE; ওয়েব রোল শুধু পড়ে, সিক্রেট কলাম পড়তে পারে না)। ফাংশন: পেয়ারিং (এককালীন কোড, টোকেন শুধু হ্যাশ), allowlist/স্কোপ যাচাই (ফোল্ডার-সীমা, `..`/নিয়ন্ত্রণ-অক্ষর নিষিদ্ধ, অ্যাপ/কমান্ড/https-হোস্ট), per-action অনুমোদন (high/critical কখনো স্বয়ংক্রিয় নয়), ১০ মিনিটে মেয়াদ, ইমার্জেন্সি স্টপ (ফেজ ১১-র সাথে যুক্ত), অডিট-ট্রেইল ট্রিগার, system monitor নমুনা (+`pc:purge-metrics`)।
- PHP: `App\Pc\{PcRepo,PgPcRepo,PcService,MonitorAdvisor}`, `PcServiceProvider`। `docs/COMPUTER_CONTROL.md` (প্রোটোকল ও এজেন্টের দায়িত্ব)।
- টেস্ট: `tests/db/test_pc_control.py` ৮২, `PcControlTest.php` ১০; SQL ~৩০টি ও PHP ১৬টি mutation ধরা (৪টি ফাঁক পেয়ে টেস্ট শক্ত; মাঝে আসল বাগও ধরা পড়ে সারানো: `FOUND` রিসেট ও ভেরিয়েবল-নাম সংঘর্ষ)।
- বাকি (২৬খ+): HTTP API ও ওয়েব UI, আসল ডেস্কটপ/ব্রাউজার এজেন্ট, মোবাইল step-up, মোবাইল→ডেস্কটপ হ্যান্ডঅফ।

## [0.93.0] — 2026-11-09 — Phase 25.2 (AI Character, সার্ভার-অংশ)
- `/app/characters`: মৌলিক চরিত্র বানানো/সম্পাদনা/মুছা (নাম, ধরন পুরুষ/নারী/কাস্টম, পার্সোনালিটি, কথা বলার ধরন, ভাষা, ভয়েসের নাম, অবতার 2D/3D/নেই, শুভেচ্ছা)। সর্বোচ্চ ২০টি; শুধু নির্মাতা সম্পাদনা/মোছে; ২টি মৌলিক টেমপ্লেট।
- চ্যাটে "চরিত্র" বাছাই: আলাপ চরিত্র ধরে রাখে (follow-up/regenerate); `PersonaPrompt` — স্থির নিয়ম আগে (নিজেকে AI বলা, বিদ্যমান সিনেমা/কমিক/গেম/বইয়ের চরিত্র বা বাস্তব ব্যক্তি সাজা নিষেধ — ARCHITECTURE R14), বর্ণনা `<character>` বেড়ায় (`<`/`>`, কন্ট্রোল/bidi অক্ষর ছাঁটা)। মুছে ফেলা চরিত্রের পুরনো আলাপ সাধারণ সহকারীতে ফেরে।
- `phase28c`: `characters` (RLS+FORCE, ২০-সীমা ট্রিগার), `conversations.character_id` (একই ওয়ার্কস্পেস, অপরিবর্তনীয়)।
- টেস্ট: `CharacterTest.php` ১০ (১৫টি mutation ধরা; নেস্টেড `</char</character>acter>` বেড়া-ভাঙা ফাঁক পেয়ে সারানো), DB `test_characters.py` ১২ (৭টি mutation ধরা)।
- বাদ (ক্লায়েন্ট/ডেস্কটপ কাজ): অবতার রেন্ডারিং, লিপ-সিংক, অভিব্যক্তি, চরিত্রের আসল ভয়েস আউটপুট, চরিত্র মার্কেটপ্লেস। "মৌলিক চরিত্র" নিয়ম প্রম্পটে বাধ্য করা হয়েছে, কিন্তু কোনো নাম আসলে বিদ্যমান চরিত্র কি না তা স্বয়ংক্রিয়ভাবে ধরা যায় না।

## [0.92.0] — 2026-11-09 — Phase 21 (Coding Assistant ওয়েব চ্যাট মোড)
- চ্যাটে "কোডিং সহকারী" মোড: নতুন আলাপ শুরুর সময় মোড বাছা যায়; আলাপ তার মোড ধরে রাখে (regenerate/পরের বার্তাতেও)। কোডিং সিস্টেম প্রম্পট: ফেনসড কোড, ন্যূনতম ফিক্স, না-চালিয়ে "চালিয়েছি" না বলা, গোপন কী সতর্কতা, বাংলায় ব্যাখ্যা/ইংরেজিতে কোড।
- `phase28b`: `conversations.mode` (CHECK general/code) + ট্রিগার যা মোড বদলাতে দেয় না। সাইডবারে কোডিং আলাপে `</>` চিহ্ন।
- ফাইলে কাজ করা Coding Agent ডেস্কটপে (ফেজ ২৫–২৬); ভিজ্যুয়াল ক্যানভাস ব্রাউজারে যাচাই ছাড়া এখানে বানানো হয়নি।
- টেস্ট: `ChatCodeModeTest.php` ৬ (৭টি mutation ধরা), DB ২ নতুন (২টি mutation ধরা); সব DB ১৬১৬, PHP ৯৩ ফাইল সবুজ।

## [0.91.0] — 2026-11-09 — Phase 28.5 (self-host প্যাকেজ)
- নতুন `selfhost/`: পুরো স্ট্যাকের `docker-compose.yml` (postgres, redis, ai-engine, web/php-fpm, horizon, scheduler, nginx, ঐচ্ছিক Caddy HTTPS; শুধু nginx/Caddy পাবলিক), `docker/web.Dockerfile` (www-data, `--no-dev`), `install.sh` (একবারে `.env` + র‍্যান্ডম সিক্রেট, provision → migrate → up), `update.sh` (আগে ব্যাকআপ), `backup.sh`, `restore.sh` (নিশ্চিতকরণসহ), `healthcheck.sh`, `artisan.sh`, `license.sh`, `README.md`; `.dockerignore`।
- অফলাইন লাইসেন্স কী (Ed25519, libsodium): `App\SelfHost\{LicenseKey,SelfHostLicense}`, `selfhost:license` কমান্ড (`--tenant` দিলে Phase 27c সিট প্রয়োগ), বিক্রেতার টুল `scripts/license-issue.php` (keygen/issue/verify), `config/selfhost.php`।
- লোকাল Ollama ওয়ার্কার: `selfhost/local-worker/` (compose, `setup.sh`, README — Tailscale পরামর্শ)। FFmpeg রেন্ডার অফলোড এখনো নেই (README-তে খোলাখুলি লেখা)।
- টেস্ট: `SelfHostLicenseTest.php` ১২ (mutation-এ সব ধরা), `tests/selfhost/test_selfhost_package.py` ২৫ (+১ shellcheck, না থাকলে skip)।

## [0.90.0] — 2026-11-09 — Phase 28 (নিরাপত্তা অডিট)
- `docs/SECURITY_AUDIT.md` (পাওয়া সমস্যা, গৃহীত ঝুঁকি, যা যাচাই হয়নি) ও `docs/SECURITY_CHECKLIST.md` (ডিপ্লয় চেকলিস্ট); `scripts/security-audit.sh` একসাথে সব চেক চালায়।
- সারানো (`phase28a`): ৩টি DEFINER ফাংশন PUBLIC থেকে বন্ধ; ৫টি সাফাই-ফাংশন শুধু অ্যাডমিনের (৫টি artisan কমান্ড অ্যাডমিন কানেকশনে); ২টি ভিউ থেকে লেখার অনুমতি তোলা। ইঞ্জিনে bidi অক্ষর → `\u` এস্কেপ। mobile `vitest` ^5.1 (critical সরলো)।
- স্থায়ী টেস্ট: `tests/db/test_security_audit.py` ১৪, `SecurityAuditTest.php` ১২, `apps/ai-engine/tests/test_security_audit.py` ৬ (ইনজেক্ট-করা ভুলে ঠিকঠাক ফেল করে — যাচাই করা)।

## [0.89.0] — 2026-11-09 — Phase 27.1c (Enterprise লাইসেন্স / সিট)
- `licenses` (সিট, মেয়াদ, নোট; RLS, অ্যাপ শুধু পড়ে) + `license_set`/`license_remove` (শুধু অ্যাডমিন; বর্তমান সক্রিয় সদস্যের চেয়ে কম সিট নয়) + `license_seats_left`।
- `tenant_users`-এ ট্রিগার `license_seat_guard`: লাইসেন্স থাকলে সক্রিয় সদস্য সিটের বেশি হতে পারে না — আমন্ত্রণ, সরাসরি insert বা আবার সক্রিয় করা, সব পথে। মেয়াদ শেষে কেউ নতুন ঢুকতে পারে না (বর্তমান সদস্য থাকেন); নবায়নে আবার খোলে। লাইসেন্স না থাকলে সীমা নেই।
- আমন্ত্রণ পাঠানোর আগে বন্ধুত্বপূর্ণ বার্তা (`LicenseService`), গ্রহণের সময় `seat_limit`/`license_expired` বার্তা; `/app/license` (শুধু দেখা), `/admin/licenses` (পারমিশন `licenses.manage`, অডিট)।
- টেস্ট: DB ১১ (১৮টি মিউটেশন, ৩টি সমতুল্য/প্রতিরক্ষামূলক), `LicenseTest` ৬ (৭টি মিউটেশন, ১টি সীমারেখা-সমতুল্য)।

## [0.88.0] — 2026-11-09 — Phase 27.1b (White-label)
- `tenant_brands` (RLS; অ্যাপ শুধু পড়ে) + `brand_set_enabled` (শুধু অ্যাডমিন; বন্ধ করলে ব্র্যান্ড মোছে) ও `brand_save` (শুধু workspace-মালিক, শুধু চালু থাকলে)। ব্র্যান্ড-নাম (≤৪০, `< >`/কন্ট্রোল ছাড়া), রং `#rrggbb`, সাপোর্ট ইমেইল, "Powered by" লুকানো।
- ব্র্যান্ড শুধু নাম ও রং বদলায় — লিংক/স্ক্রিপ্ট নয়; CSS-এ কেবল যাচাই-করা রং ঢোকে; সাদা লেখার সাথে WCAG কনট্রাস্ট ৪.৫-এর কম হলে প্রত্যাখ্যাত (DB-তে আগে থেকে থাকলেও উপেক্ষা)। ব্র্যান্ড পড়তে ব্যর্থ হলে পেজ ভাঙে না।
- লেআউটে প্রয়োগ (শিরোনাম, হেডার-নাম, বাটনের রং, ফুটার), সেটিংস `/app/brand`, অ্যাডমিন `/admin/brands` (পারমিশন `brands.manage`, অডিট)।
- টেস্ট: DB ২৩ (১৭টি মিউটেশন), `BrandTest` ৯ (১৫টি মিউটেশন, ১টি সমতুল্য)।

## [0.87.0] — 2026-11-09 — Phase 27.1a (Reseller)
- `resellers` / `reseller_payouts` (শুধু ফাংশনে লেখা-পড়া; অ্যাপ সরাসরি পড়তে পারে না) + `tenants.reseller_bp/joined_at`। Workspace-এর **মালিক নিজে** কোড টাইপ করে একবার যুক্ত হন (সম্মতি; বদলানো যায় না; নিজের কোড বা স্থগিত রিসেলার নয়); যুক্ত হওয়ার সময়কার কমিশন-হার জমে থাকে।
- কমিশন = যুক্ত হওয়ার পর পেইড অর্ডারের (VAT ও রিফান্ড বাদে) × হার, মুদ্রা-ভিত্তিক; পেআউট অ্যাডমিন লেখেন, পাওনার বেশি নয়। রিসেলার শুধু workspace-নাম, তারিখ, পেইড অর্ডার সংখ্যা ও টাকার হিসাব দেখেন — কনটেন্ট নয়।
- ব্যবহারকারী পেজ `/app/reseller`; অ্যাডমিন `/admin/resellers` (যোগ, হার/স্থগিত, পেআউট, অডিট), পারমিশন `resellers.manage`।
- বাগ ধরা পড়ে সারানো: `citext = text` তুলনা ছোট/বড় হাতের অক্ষর আলাদা ধরছিল।
- টেস্ট: DB ১৯ (২৫+টি মিউটেশন), `ResellerTest` ৭ (১৩টি মিউটেশন, ১টি সমতুল্য)।

## [0.86.0] — 2026-11-09 — Phase 27.2f (MCP সার্ভার + dry-run)
- `POST /api/public/v1/mcp`: ছোট MCP সার্ভার (JSON-RPC; `initialize/ping/tools/list/tools/call`), টুল: `list_workflows`, `run_workflow`, `get_run`, `get_credits` — প্রতিটির স্কোপ আলাদা, `tools/list` কী-এর স্কোপ অনুযায়ী ছাঁটা; অনুমোদন/মোছা/টাকার টুল নেই; ব্যাচ ও বড় (>৬৪KB) বডি বাতিল। `ApiKeyAuth` `:*` (যেকোনো বৈধ কী) ও `api_scopes` অ্যাট্রিবিউট পেল। ডক `docs/MCP.md`।
- `dry_run`: REST ও MCP-তে ইনপুট ও "চালু আছে কিনা" যাচাই, রান তৈরি/খরচ নেই (`WorkflowService::start(..., $dryRun)`); OpenAPI হালনাগাদ।
- টেস্ট: `McpTest` ১৩ (১৮টি মিউটেশন, ১টি সমতুল্য মিউট্যান্ট গ্রহণ)। DB পরিবর্তন নেই।
- বাকি (২৭.২ থেকে): webhook-এ আরও ইভেন্ট/টেস্ট-পিং — পরে।

## [0.85.0] — 2026-11-09 — Phase 27.2e (প্লাগইন স্টোর)
- প্লাগইন = ১–৫টি ওয়ার্কফ্লোর JSON প্যাক (বাইরের কোড নেই)। `plugin_versions` / `plugin_installs` (RLS+FORCE) + `plugin_submit/withdraw/review/install_record/uninstall`। স্লাগের মালিক প্রথম ওয়ার্কস্পেস; একসাথে ৫টি অপেক্ষমাণ; অনুমোদন শুধু অ্যাডমিন (বাতিলে কারণ বাধ্যতামূলক)।
- ইনস্টল: ইনস্টলের সময় ম্যানিফেস্ট আবার যাচাই; কপি **বন্ধ**, শিডিউল ছাড়া, সর্বোচ্চ ৫.০ ক্রেডিট; আপডেটে শুধু নতুন ভার্সনে (একই ভার্সন আবার ইনস্টলে ব্যক্তির এডিট নষ্ট হয় না); বাইরে পাঠানোর ধাপে আগের মতোই অনুমোদন লাগে।
- ইউজার পেজ `/app/plugins` (ব্রাউজ, ইনস্টল/আপডেট/মুছুন, জমা/প্রত্যাহার); অ্যাডমিন `/admin/plugins` (পুরো ম্যানিফেস্ট ও টুল-ঝুঁকি দেখে প্রকাশ/বাতিল, অডিট লগ), নতুন পারমিশন `plugins.review`।
- টেস্ট: DB ২৩, `PluginTest` ১৩ (২১টি মিউটেশন)।

## [0.84.0] — 2026-11-09 — Phase 27.2d (JS SDK + webhook বার্তার তালিকা)
- `packages/sdk-js/jarvis-one.mjs` (Node 18+, নির্ভরতাহীন; Python SDK-র মতোই নিয়ম; `#`-প্রাইভেট কী, `toString/JSON/inspect`-এ কী নেই) + `node --test` ১৭টি।
- ডেভেলপার পেজে "সাম্প্রতিক বার্তা" (১৫টি; অবস্থা, চেষ্টা, শেষ কোড) — payload/সিক্রেট দেখায় না। DB টেস্ট +১।

## [0.83.0] — 2026-11-09 — Phase 27.2c (ব্যবহার-অ্যানালিটিক্স + OpenAPI + Python SDK)
- `api_key_usage` (প্রতি কী প্রতিদিন কয়বার ডাকা হলো, ৯০ দিন; শুধু সফল লগইন গোনা হয়) — `api_key_resolve` আপডেট; ডেভেলপার পেজে "৭ দিনে / মোট"।
- `resources/openapi/public-v1.json` + `GET /api/public/v1/openapi.json` (কী লাগে না); টেস্ট স্পেক ও `routes/api_public.php` মিলিয়ে দেখে (পথ, মেথড, স্কোপ)।
- `packages/sdk-python/jarvis_one.py`: নির্ভরতাহীন ক্লায়েন্ট (https বা localhost, রিডাইরেক্ট অনুসরণ নয়, কী কখনো মেসেজ/repr-এ নয়, আইডি যাচাই, `wait_for_run`)।
- টেস্ট: DB +৩, `PublicApiSpecTest` ৩, SDK ৩৬।

## [0.82.0] — 2026-11-09 — Phase 27.2b (Webhooks)
- নতুন `webhook_endpoints` / `webhook_deliveries` (RLS+FORCE; অ্যাপ সিক্রেট কলাম পড়তে পারে না) + `webhook_create/delete/set_active` (অ্যাপ) ও `webhook_enqueue/claim/result` (শুধু অ্যাডমিন/ওয়ার্কার)। ওয়ার্কফ্লো রান শেষ হলে (done/failed/cancelled) ব্যক্তির নিজের ঠিকানায় সাইন-করা `run.finished` বার্তা: শুধু আইডি, অবস্থা, খরচ। রিট্রাই ১/৫/১৫/৬০/২৪০ মিনিট, ৬ বারে বাদ; টানা ১০ ব্যর্থতায় ঠিকানা বন্ধ; পুনরায় চালু করলে "এখন থেকে"। একজনের সর্বোচ্চ ৫টি।
- নতুন `WebhookUrl` (শুধু সর্বজনীন https, পোর্ট ৪৪৩/৮৪৪৩, লোকাল/প্রাইভেট/CGNAT/মাল্টিকাস্ট নয়), `WebhookSigner` (HMAC-SHA256 "টাইমস্ট্যাম্প.বডি"), `Webhooks` সার্ভিস (পাঠানোর সময় আবার DNS দেখে — একটিও প্রাইভেট উত্তর থাকলে বাতিল; IP পিন করা, রিডাইরেক্ট নয়), `CurlWebhookSender`, `webhooks:work` (প্রতি মিনিটে), ডেভেলপার পেজে Webhook অংশ।
- টেস্ট: `WebhookTest` ৯, DB `test_webhooks.py` ২৫।

## [0.81.0] — 2026-11-09 — Phase 27.2a (পাবলিক API কী + ডেভেলপার API)
- নতুন `api_keys` টেবিল (RLS+FORCE; শুধু sha256 হ্যাশ ও ছোট prefix থাকে) + `api_key_create/revoke/resolve` (SECURITY DEFINER)। একজনের ১০টি চালু কী; মেয়াদ ১–৩৬৫ দিন বা মেয়াদহীন; সদস্যপদ/workspace বন্ধ হলে কী অচল; "শেষ ব্যবহার" মিনিটে একবার।
- নতুন `app/Developer/*`, `ApiKeyAuth` middleware, `DeveloperApiController`, `routes/api_public.php` (`/api/public/v1`: me, credits, workflows, runs/{id}, workflows/{id}/run), `DeveloperController` + `/app/developer` পেজ ও নেভ লিংক। স্কোপ: me, credits:read, workflows:read, workflows:run। কী দিয়ে অনুমোদন/মুছে ফেলা/টাকা নড়ে না। কী-প্রতি মিনিটে ৬০ অনুরোধ।
- নতুন `DeveloperServiceProvider` (`scripts/bootstrap-web.sh`-এর তালিকায় যোগ)।
- টেস্ট: `DeveloperKeysTest` ৬, DB `test_api_keys.py` ১৬ (মিউটেশন ধরা পড়েছে)।

## [0.80.0] — 2026-11-09 — Phase 22.1e (লোগোর আইডিয়া)
- `StudioService::logo` + `POST /app/studio/logo`: AI দিয়ে একটি লেখাহীন সাধারণ লোগো-চিহ্ন বানিয়ে নামানো; ছবির সাধারণ দামে, একবারই settle/release; সংরক্ষিত হয় না, ব্র্যান্ড কিটে বসে না। টেস্ট +১।

## [0.79.0] — 2026-11-09 — Phase 22.1d (ক্লায়েন্টের উত্তরে ডিজাইনারকে নোটিফিকেশন)
- `review_public_decide` এখন ক্লায়েন্ট উত্তর দিলে ডিজাইনারের `inbox_notifications`-এ একটি বার্তা রাখে (অনুমোদিত / পরিবর্তন চেয়েছেন + ক্লায়েন্টের নাম ও মন্তব্য); একই লিংকে দ্বিতীয়বার হয় না; ভুল উত্তরে কিছু হয় না। মোবাইল অ্যাপের বিদ্যমান পুশ এটি তুলে নেয়।
- নতুন `phase22d.up/down.sql`; DB টেস্ট +২।

## [0.78.0] — 2026-11-09 — Phase 22.1c (ডিজাইন সম্পাদনা + ভার্সন + টেমপ্লেট)
- নতুন: `design_versions` টেবিল (RLS+FORCE), `design_update` / `design_restore` ফাংশন (SECURITY DEFINER; ছবি অপরিবর্তিত, সর্বশেষ ১০টি ভার্সন রাখে, একই কিছু সংরক্ষণ করলে `unchanged`)।
- নতুন: `StudioService::edit/restore`, `StudioTemplates` (৬টি নমুনা), কন্ট্রোলার `edit`/`restore`, `studio/show`-তে সম্পাদনা ফর্ম ও "আগের রূপ" তালিকা, `studio/index`-এ `?t=` নমুনা বাছাই।
- সম্পাদনা/ফেরানোর পর ক্যাশ-করা ছবি মুছে যায়; ক্লায়েন্টের পুরনো লিংকের জমানো ছবি বদলায় না।
- টেস্ট: `StudioEditTest` ৯, DB ১৪৭৮ (মাইগ্রেশন রাউন্ডট্রিপসহ)।

## [0.77.0] — 2026-11-09 — Phase 21b (অপেক্ষা ধাপ + লাইন-ধরে-চালানো)
- নতুন `delay` ধাপ (১–১৪৪০ মিনিট, একটি ওয়ার্কফ্লোতে সর্বোচ্চ ৩টি): রান "সারিতে" গিয়ে সময় হলে ওয়ার্কার (`workflows:work`, প্রতি মিনিটে) পরের ধাপ থেকে চালায়; আগের ভেরিয়েবল থাকে; অপেক্ষার সময় বাতিল করা যায়, বাতিল রান আর জাগে না। অপেক্ষার ধাপ অনুমোদনের শর্ত মুছে দেয় না।
- টুল-ধাপে `each` (+ঐচ্ছিক `max_items` ১–১০): ভেরিয়েবলের প্রতিটি অ-ফাঁকা লাইনে টুল একবার করে চলে (`{{item}}`, `{{index}}`), সর্বোচ্চ ১০ লাইন; বাইরে-পাঠানোর টুলে কখনো নয়; প্রথম ব্যর্থ লাইনে ধাপ থামে ("লাইন N: …")। লুপ নয় — ধাপের গ্রাফ এখনো লুপ-মুক্ত।
- নতুন টেমপ্লেট "২৪ ঘণ্টা পরে মনে করিয়ে দাও" (টাস্ক যোগ → অপেক্ষা → নোটিফিকেশন)।
- মাইগ্রেশন `2026_12_21_100000_workflow_delay` (`phase21b`): `wf_runs.not_before`/`claimed_at`, `wf_run_claim` হালনাগাদ, নতুন `wf_step_delay`। আটকে-থাকা-রান (১৫ মিনিট) এখন `claimed_at` দেখে, তাই এক দিন অপেক্ষা-করা রান ভুলে ব্যর্থ হয় না।

## [0.76.0] — 2026-11-09 — Phase 20b (ভয়েস থেকে ওয়ার্কফ্লো চালানো)
- ভয়েস পেজে (বা টাইপ করে) "সাপ্তাহিক রিপোর্ট চালাও" / "run weekly report" বললে আপনার নিজের সেই নামের ওয়ার্কফ্লো চালু হয়; AI-র উত্তর বা ক্রেডিট লাগে না (পড়ে শোনানো চাইলে শুধু কণ্ঠের খরচ)। উত্তরে `command` ফিল্ড (started/not_found/ambiguous/needs_input/disabled/busy/failed)।
- কঠোর নিয়ম: বাক্য শুধু "ক্রিয়া + নাম" (সর্বোচ্চ ৮ শব্দ); নাম হুবহু মিলতে হবে বা ঠিক একটি ওয়ার্কফ্লোর নামের অংশ (কমপক্ষে ৩ অক্ষর); একাধিক মিললে জিজ্ঞেস করে; লেখা-তথ্য লাগে এমন বা বন্ধ ওয়ার্কফ্লো চালায় না। ভয়েসে অনুমোদন দেওয়া যায় না — অনুমোদন লাগলে পেজে চাইবে (ভুল শোনা থেকে নিরাপত্তা)।
- `VoiceActions` (শুধু: ওয়ার্কফ্লো তালিকা + চালু করা), `WorkflowVoiceActions`, `VoiceCommand`। মাইগ্রেশন নেই।

## [0.75.0] — 2026-11-09 — Phase 19.2e (Google / Outlook ক্যালেন্ডার OAuth)
- `/app/connect`-এ "Google ক্যালেন্ডার / Outlook ক্যালেন্ডার সংযুক্ত করুন": OAuth 2.0 (PKCE + এক-ব্যবহারের state), শুধু ক্যালেন্ডারের অনুমতি (`calendar.events` / `Calendars.ReadWrite`; মেইলের নয়)। একই Google/Microsoft অ্যাপ-key (`outreach.*` কনফিগ) ব্যবহার — নতুন key লাগে না।
- সংযুক্ত ক্যালেন্ডারের ইভেন্ট "আগামী ইভেন্ট" তালিকা ও `calendar.upcoming` টুলে ICS-এর সাথে মিশে দেখায় (একই সময়-নামের ইভেন্ট একবার)।
- নিজে ক্লিক করে একটি করে ইভেন্ট যোগ (ফর্ম) এবং নতুন ওয়ার্কফ্লো-টুল `calendar.add_event` (`Risk::EXTERNAL` — অনুমোদনের পরেই)। কোনো অতিথি আমন্ত্রণ নেই, আগের ইভেন্ট বদল/মোছা নেই, সময় UTC-তে পাঠানো হয়।
- মাইগ্রেশন `2026_12_20_100000_calendar_oauth_links` (`phase192e`): `user_links`-এ নতুন kind `gcal`/`mcal` ও ফাংশন `link_secret_update` (ঘূর্ণিত refresh token বসায়; শুধু ক্যালেন্ডার-লিংক, শুধু মালিক)। রিফ্রেশ টোকেন এনক্রিপ্ট করা; অনুমতি তুলে নিলে ("revoked") পেজে দেখায়, সাময়িক বিভ্রাটে দাগ পড়ে না।

## [0.74.0] — 2026-11-09 — Phase 24.3d (Marketing Manager Agent)
- নতুন ওয়ার্কফ্লো-টুল `marketing.snapshot` (গত ৭/৩০ দিনের বিজ্ঞাপন + সোশ্যাল সংখ্যা, শুধু-পড়া) ও `seo.latest` (প্রতি সাইটের সর্বশেষ SEO অডিট) — দুটোই `Risk::READ`; চালানোর সময় মালিকের অনুমতি আবার যাচাই (`ads.view` / `social.view`), না থাকলে "অনুমতি নেই" লেখা ফেরে ও ডেটা আনাই হয় না।
- `MarketingSnapshot` (বিশুদ্ধ, AI/ক্রেডিট ছাড়া): ভিন্ন মুদ্রা কখনো যোগ হয় না, আগের সময়ের ভিত্তি শূন্য হলে শতাংশ দেখায় না, ২ দিনের বেশি পুরনো/কখনো-সিঙ্ক-না-হওয়া অ্যাকাউন্টে ⚠, খরচ-আছে-ফলাফল-নেই ক্যাম্পেইনে নিয়মভিত্তিক ⚠, ডেটা না থাকলে স্পষ্ট "তথ্য নেই", কোটেশন/কন্ট্রোল-ক্যারেক্টার ছাঁটাই।
- ৫টি রেডিমেড টেমপ্লেট (ক্যাটাগরি মার্কেটিং): সাপ্তাহিক মার্কেটিং ম্যানেজার, মাসিক বিজ্ঞাপন পর্যালোচনা, আজকের জরুরি কাজ (অনুমোদনের পরে শুধু নিজের টাস্ক-তালিকায়), SEO সমাধান-পরিকল্পনা, সোশ্যাল কনটেন্ট আইডিয়া। এজেন্ট শুধু পরামর্শ দেয় — বাজেট বদল/চালু-বন্ধ/প্রকাশ করে না। প্রম্পটে DATA-বেড়া ও সত্যবাদিতার নিয়ম।
- মাইগ্রেশন/ইঞ্জিন বদল নেই।

## [0.73.0] — 2026-11-09 — Phase 24.3c (SEO অডিট)
- নতুন পেজ `/app/seo` (নেভে "SEO"): ওয়েবসাইটের ঠিকানা (ও ঐচ্ছিক টার্গেট শব্দ, ক্লায়েন্ট) দিলে হোমপেজ ও কয়েকটি ভেতরের পেজ (১–৮, sitemap.xml থেকেও) যাচাই: টাইটেল/মেটা ডেসক্রিপশনের দৈর্ঘ্য, H1, ভাষা, মোবাইল viewport, canonical, noindex, ছবির alt, লেখার পরিমাণ, Open Graph, JSON-LD, ডুপ্লিকেট টাইটেল/ডেসক্রিপশন, ভাঙা পেজ, HTTPS, robots.txt, sitemap। প্রতিটি সমস্যার সমাধান বাংলায়, স্কোর ০–১০০ (প্রতি ধরনের সমস্যা একবারই কাটে), আগের অডিটের সাথে তুলনা।
- নিয়মভিত্তিক: AI বা ক্রেডিট লাগে না; কিছু প্রকাশ বা বদল হয় না। বিদ্যমান SSRF-নিরাপদ `SafeFetcher` ও robots.txt মেনে চলা (ব্লক থাকলে অডিট হয় না)। ২৪ ঘণ্টায় ৩০টি সীমা (ক্রলের আগে যাচাই), সর্বশেষ ৩০০টি রাখা হয়।
- ইঞ্জিনে `POST /internal/v1/seo/audit` (`app/seo/*`, `routes_seo.py`); `SafeFetcher.get_any()` যোগ। মাইগ্রেশন `2026_12_19_100000_create_seo_audits` (`phase243c`)। অনুমতি: দেখা `ads.view`, চালানো/মোছা `ads.manage`।
- টেস্ট: ইঞ্জিন ২০, ডাটাবেস ৯, PHP ইউনিট ১৪ (মিউটেশনে সব অসমতুল্য ধরা পড়েছে)।

## [0.72.0] — 2026-11-04 — Phase 24.3b (এজেন্সি মোড: ক্লায়েন্ট)
- নতুন পেজ `/app/clients` (নেভে "ক্লায়েন্ট"): ক্লায়েন্ট তৈরি/সম্পাদনা/আর্কাইভ, প্রতিটির নিজস্ব ব্র্যান্ড (নাম, রং, লোগো, যোগাযোগ) এবং Meta বিজ্ঞাপন অ্যাকাউন্ট, Google Ads অ্যাকাউন্ট ও Facebook পেজ ক্লায়েন্টের নামে বাঁধা।
- রিপোর্টে "কোন ক্লায়েন্টের রিপোর্ট" বাছাই: শুধু তার অ্যাকাউন্ট/পেজের সংখ্যা এবং তার ব্র্যান্ড; প্রিভিউয়ে ফর্মের লেখা আর হারায় না।
- মাইগ্রেশন `2026_12_18_100000_create_clients` (`phase243b`)। শুধু লেবেল — কিছু প্রকাশ বা খরচ হয় না। টেস্ট: ইউনিট ৪+৪, ডাটাবেস ৮+২।

## [0.71.0] — 2026-11-04 — Phase 24.3a (ক্লায়েন্ট মার্কেটিং রিপোর্ট)
- নতুন পেজ `/app/reports`: সাপ্তাহিক (গত ৭ দিন, আগের সপ্তাহের সাথে তুলনা) বা মাসিক (গত ৩০ দিন) রিপোর্ট; বাংলা/ইংরেজি; প্রিভিউ, PDF ও DOCX ডাউনলোড।
- তথ্য আসে Meta ও Google Ads (খরচ, ইমপ্রেশন, ক্লিক, ফলাফল/কনভার্সন) এবং প্রকাশিত সোশ্যাল পোস্ট (রিঅ্যাকশন, কমেন্ট, শেয়ার, রিচ) থেকে; ব্র্যান্ড কিটের লোগো/রং/যোগাযোগ বসে। ফেজ ৯এ-র ডক-রেন্ডারার পুনর্ব্যবহার।
- "আমরা যা করেছি" নোট ব্যবহারকারীর লেখা হুবহু থাকে (AI কিছু বানায় না)। প্রতিটি অ্যাকাউন্ট নিজস্ব মুদ্রায়, কখনো যোগ করা হয় না। পুরনো সিঙ্কের অ্যাকাউন্টে সতর্কতা।
- নতুন মাইগ্রেশন/ডাটাবেস পরিবর্তন নেই। টেস্ট: ইউনিট ১৫, ডাটাবেস ৪।

## [0.70.0] — 2026-11-04 — Phase 24.2d (Google Ads পরামর্শ ও অনুমোদিত বিরতি) — ফেজ ২৪.২ সম্পূর্ণ
- `/app/google-ads`-এ "পরামর্শ (গত ৭ দিন)": নিয়মভিত্তিক (AI নয়, খরচ নেই)। বিরতি: ≥৩ দিনের খরচ ও ≥৩০ ক্লিক কিন্তু কনভার্সন ০, অথবা প্রতি কনভার্সনে খরচ অন্যদের গড়ের ≥২.৫ গুণ (একই অ্যাকাউন্টে অন্তত ২টি তুলনার ক্যাম্পেইন লাগে)। বাজেট বাড়ানো শুধু পরামর্শ — আমরা করি না।
- Google-নির্দিষ্ট সুরক্ষা: যে অ্যাকাউন্টে সপ্তাহে একটিও কনভার্সন নেই সেখানে কোনো পরামর্শ নয় (ট্র্যাকিং সেট না থাকতে পারে); ভগ্নাংশ কনভার্সন উপরে গোল করা হয় (সতর্কতার দিকে)।
- বিরতি: `ads.approve` অনুমতির মানুষ অনুমোদন দিলে `gads:pause` (প্রতি মিনিট) শুধু `status → PAUSED` পাঠায় (updateMask স্থির `status`); ক্যাম্পেইন ENABLED ও অ্যাকাউন্ট সচল হতে হবে; একই ক্যাম্পেইনে একটি খোলা অনুরোধ; ১৫ মিনিটে সাড়া না এলে ব্যর্থ (বিরতি idempotent)।
- মাইগ্রেশন `2026_12_17_100000_create_gads_pause`। টেস্ট: ডিবি ৬, ইউনিট ৮ (সব মিউটেশন ধরা পড়েছে)।

## [0.69.0] — 2026-11-04 — Phase 24.2c (অনুমোদিত Google ড্রাফট → Google Ads-এ PAUSED তৈরি)
- `gads:launch` (প্রতি মিনিট): অনুমোদিত ড্রাফট Google Ads-এ তৈরি করে — বাজেট → ক্যাম্পেইন (সবসময় **PAUSED**) → অ্যাড গ্রুপ → Responsive Search Ad → কীওয়ার্ড (phrase/exact) → লোকেশন, ভাষা ও নেগেটিভ কীওয়ার্ড।
- নিরাপত্তা: API ক্লাসে শুধু তৈরির অনুরোধ আছে (বদল/মোছা/চালু করার পথ নেই); প্রতি ধাপের আইডি সঙ্গে সঙ্গে সংরক্ষণ, আবার চেষ্টায় শেষ হওয়া ধাপ বাদ; ফল অজানা হলে নিজে নিজে আর চেষ্টা নয় (মানুষকে Google Ads দেখে টিক দিতে হয়); দাবি একবারই; ঘণ্টায় ওয়ার্কস্পেসপ্রতি সর্বোচ্চ ৩ চেষ্টা; জরুরি-থামানোয় বন্ধ; দাবির সময় সীমা/সম্পূর্ণতা/অ্যাকাউন্ট আবার যাচাই।
- নতুন: সমর্থিত দেশ-তালিকা (`unsupported`), `gads_launch_*` ফাংশন, ড্রাফট পাতায় তৈরির অবস্থা ও "আবার চেষ্টা", তৈরির পর ড্রাফট আর বদলানো/মোছা যায় না। মাইগ্রেশন `2026_12_16_100000_create_gads_launch`।
- টেস্ট: ডিবি ১৫, ইউনিট ১৪ (মিউটেশনে বাকি সবই ধরা পড়েছে; একটি সমতুল্য)।

## [0.68.0] — 2026-11-04 — Phase 24.2b (Google Ads Search ড্রাফট, AI কপি, সীমা, অনুমোদন)
- `/app/google-ads/drafts`: Search ক্যাম্পেইন ড্রাফট — শিরোনাম (৩–১৫, ≤৩০ অক্ষর), বিবরণ (২–৪, ≤৯০), কীওয়ার্ড (≤৩০; ফ্রেজ/এক্স্যাক্ট — ব্রড কখনো নয়), নেগেটিভ কীওয়ার্ড, দেশ/ভাষা, লিংক, বাজেট, শুরুর তারিখ।
- AI শুধু শব্দ প্রস্তাব করে (ক্রেডিট: ১ reserve → ঠিক ১ settle/release); বাজেট/লিংক/দাম AI দেয় না; সীমার বেশি লম্বা আইটেম কেটে নয়, বাদ দেওয়া হয়।
- Meta থেকে আলাদা খরচ-সীমা (`gads_settings`; মুদ্রা আলাদা বলে); জমা ও অনুমোদন দুই জায়গাতেই সীমা আবার যাচাই; সীমা না থাকলে জমা/অনুমোদন নেই; জরুরি-থামানোয় অনুমোদন নেই; জমা/অনুমোদিত ড্রাফট সম্পাদনা বন্ধ; খোলা ড্রাফট থাকলে অ্যাকাউন্ট সরানো যায় না।
- এখনো Google-এ কিছু যায় না। মাইগ্রেশন `2026_12_15_100000_create_gads_drafts`। টেস্ট: ডিবি ১০, ইউনিট ১০ (মিউটেশনে সব ধরা পড়েছে)।

## [0.67.0] — 2026-11-04 — Phase 24.2a (Google Ads: সংযোগ + শুধু-পড়া সিঙ্ক)
- `/app/google-ads`: Customer ID (ও ঐচ্ছিক ম্যানেজার/MCC ID) দিয়ে "Google দিয়ে সংযোগ" — Google সম্মতি পর্দা (PKCE, শুধু `adwords` স্কোপ), রিফ্রেশ টোকেন সিল করা, গ্রাহক Google Ads-এ যাচাই করে তবেই সংরক্ষণ।
- `gads:sync` (প্রতি ৩০ মিনিট): ক্যাম্পেইন, দৈনিক খরচ/ইম্প্রেশন/ক্লিক/কনভার্সন (GAQL, মাইক্রো→২ দশমিক, ফ্লোট ছাড়া)। শুধু-পড়া; Google-এ কিছু তৈরি/বদল করার পথ নেই।
- প্ল্যাটফর্ম কনফিগ: `GOOGLE_ADS_DEVELOPER_TOKEN`, `GOOGLE_ADS_CLIENT_ID/SECRET` (ডিফল্ট YouTube ক্লায়েন্ট), `GOOGLE_ADS_API_VERSION` (ডিফল্ট v24)।
- নতুন টেবিল `gads_accounts/campaigns/insights_daily` (RLS), ফাংশন `gads_*`; মাইগ্রেশন `2026_12_14_100000_create_gads`। টেস্ট: ডিবি ১০, ইউনিট ১৭ (মিউটেশনে সব ধরা পড়েছে)।

## [0.66.0] — 2026-11-04 — Phase 24.1d (অপ্টিমাইজেশন পরামর্শ ও অনুমোদিত বিরতি)
- `/app/ads`-এ নিয়মভিত্তিক পরামর্শ (AI/খরচ নেই), গত ৭ দিনের কপি-করা ফলাফল থেকে: ≥৩ দিন খরচ ও ≥৩০ ক্লিক অথচ ফলাফল শূন্য → "বিরতি দিন"; প্রতি-ফলাফল খরচ একই অ্যাকাউন্টের অন্যদের গড়ের ≥২.৫ গুণ → "বিরতি দিন"; ≤০.৬ গুণ ও ≥৫ ফলাফল → "বাজেট বাড়ানোর কথা ভাবুন"। তুলনার জন্য অন্তত ২টি অন্য ক্যাম্পেইন লাগে; নইলে তুলনা হয় না।
- `ads.approve` অনুমতিধারী "অনুমোদন দিয়ে বিরতি দিন" চাপলে `ads:pause` (প্রতি মিনিট) Meta-তে ক্যাম্পেইন PAUSED করে। এটিই একমাত্র পরিবর্তন; চালু করা বা বাজেট বাড়ানো সিস্টেম কখনো করে না (স্কেল-পরামর্শ শুধু উপদেশ)।
- নতুন টেবিল `ads_pause_requests`, ফাংশন `ads_pause_request/claim/done`; মাইগ্রেশন `2026_12_13_100000_create_ads_pause`। টেস্ট: ডিবি ৭, ইউনিট ৯ (২৭ মিউটেশনে সব ধরা পড়েছে)।

## [0.65.0] — 2026-11-04 — Phase 24.1c (অনুমোদিত ড্রাফট Meta-তে PAUSED তৈরি)
- অনুমোদনের পর `ads:launch` (প্রতি মিনিট) ক্যাম্পেইন → অ্যাড-সেট → ক্রিয়েটিভ → অ্যাড তৈরি করে — সবই PAUSED। চালু করার কোনো কোড নেই; চালু করবেন মানুষ, Ads Manager থেকে।
- প্রতি ধাপের Meta আইডি সঙ্গে সঙ্গে সংরক্ষণ; আবার চেষ্টায় শুধু বাকিটা তৈরি হয় (ডুপ্লিকেট হয় না)। ফল অজানা হলে নিজে থেকে retry হয় না — Ads Manager দেখে টিক দিয়ে নিশ্চিত করতে হয়।
- সমর্থিত উদ্দেশ্য: ওয়েবসাইট ভিজিট, পরিচিতি, বিক্রি (Pixel লাগে)। লিড/মেসেজ ও "মেসেজ পাঠান" বাটন এখনো নয়। শুরুর তারিখ আবশ্যক; শেষ তারিখ = শুরু + দিন, তাই অনুমোদিত মোট বাজেট পেরোয় না।
- বাজেট একক: শুধু যাচাই-করা ১/১০০ মুদ্রা (BDT, USD, EUR, GBP, INR, AED, SAR, MYR, SGD, AUD, CAD); অন্য মুদ্রায় তৈরি অস্বীকার।
- নতুন: পেজ বাছাই, অ্যাকাউন্টে Pixel ID, লঞ্চ স্ট্যাটাস/ত্রুটি/আইডি, আবার-চেষ্টা। ঘণ্টায় সীমা `ADS_LAUNCHES_PER_HOUR` (ডিফল্ট ৩)। টোকেনে `ads_management` লাগে।
- মাইগ্রেশন `2026_12_12_100000_create_ads_launch`। টেস্ট: ডিবি ১৮ নতুন, ইউনিট ১৪ নতুন (মিউটেশনে সব ধরা পড়েছে)।

## [0.64.0] — 2026-11-04 — Phase 24.1b (Meta বিজ্ঞাপন-ড্রাফট, সীমা ও অনুমোদন)
- বিজ্ঞাপন-ড্রাফট: উদ্দেশ্য, অডিয়েন্স (দেশ/বয়স/লিঙ্গ/আগ্রহ), লেখা, শিরোনাম, বাটন, লিংক, দৈনিক বাজেট × দিন; AI শুধু লেখা ও শুরুর অডিয়েন্স প্রস্তাব করে, বাজেট বা লিংক কখনো নয়।
- খরচের সীমা (দৈনিক ও মোট): সীমা ছাড়া জমা/অনুমোদন হয় না; সীমা কমালে পুরনো জমা-ড্রাফটও অনুমোদনে আটকায়; ডাটাবেসে বলবৎ।
- অনুমোদন: নতুন অনুমতি `ads.manage` (Owner/Admin/Manager), `ads.approve` (Owner/Admin); ইমার্জেন্সি স্টপে অনুমোদন বন্ধ; অনুমোদিত/জমা-ড্রাফটের অ্যাকাউন্ট সরানো যায় না (`busy`)।
- মাইগ্রেশন `2026_12_11_100000_create_ads_drafts`; টেবিল `ads_settings`, `ads_drafts`। Meta-তে কিছুই পাঠানো হয় না।
- টেস্ট: DB ৩৩টি, PHP ইউনিট ৩৬টি নতুন; মিউটেশন টেস্ট করা।

## [0.63.0] — 2026-11-04 — Phase 24.1a (Meta Ads: সংযোগ + শুধু-পড়া সিঙ্ক)
- Meta বিজ্ঞাপন-অ্যাকাউন্ট সংযোগ (আইডি + টোকেন যাচাই করে সিল); টোকেন অ্যাপ-রোল পড়তে পারে না।
- `ads:sync` (প্রতি ৩০ মিনিটে, প্রতি অ্যাকাউন্ট ঘণ্টায় একবার): ক্যাম্পেইন ও গত ৩০ দিনের দৈনিক খরচ/ইম্প্রেশন/ক্লিক/ফলাফল কপি; শুধু পড়া, Meta-তে কিছু বদলায় না।
- `/app/ads` পাতা: সংযুক্ত অ্যাকাউন্ট, ৭/৩০ দিনের ক্যাম্পেইন-ভিত্তিক সারাংশ।
- নতুন অনুমতি `ads.view` (Owner/Admin/Manager/Auditor), `ads.connect` (Owner/Admin)।
- মাইগ্রেশন `2026_12_10_100000_create_ads_meta`; টেবিল `ads_accounts`, `ads_campaigns`, `ads_insights_daily`।
- টেস্ট: DB ১২টি নতুন, PHP ইউনিট ১৭টি নতুন, মিউটেশন সব মারা গেছে।

## [0.62.0] — 2026-11-04 — Phase 23.2d (YouTube: চ্যানেল সংযোগ, ভিডিও আপলোড, AI SEO)
- Google OAuth (PKCE, offline) দিয়ে YouTube চ্যানেল সংযোগ; refresh token সিল করা, অ্যাপ-রোল পড়তে পারে না।
- `social:youtube` ওয়ার্কার: অনুমোদিত ভিডিও একবারই আপলোড (multipart, ≤১০০MB), ডিফল্ট প্রাইভেসি `private`, থাম্বনেইল best-effort।
- আপলোড কোটা: গোটা ইনস্টলেশনে দৈনিক ৫ (`SOCIAL_YOUTUBE_DAILY_UPLOADS`), প্রতি ওয়ার্কস্পেসে ৩ — ডাটাবেসে বলবৎ।
- AI SEO সাজেশন (টাইটেল/বিবরণ/ট্যাগ/থাম্বনেইল লেখা ও আইডিয়া): ক্রেডিট reserve→একবার settle/release, লিংক বাদ।
- মাইগ্রেশন `2026_12_09_100000_create_social_youtube`; Facebook ওয়ার্কার ফাংশনগুলো শুধু facebook অ্যাকাউন্টে সীমিত।
- টেস্ট: DB ১৮টি নতুন (মোট ১৩০৭), PHP ইউনিট ৩১টি নতুন, মিউটেশন সব মারা গেছে।

## [0.61.0] — 2026-11-04 — Phase 23.2c (কমেন্ট-রিপ্লাই, শুধু অনুমোদনের পর)
- নতুন `/app/social/comments` ইনবক্স: সিস্টেম থেকে অটো-পোস্ট করা Facebook পোস্টের টপ-লেভেল কমেন্ট আসে (`social:comments`, প্রায় ১০ মিনিটে; পোস্টের বয়স অনুযায়ী ব্যবধান, ৩০ দিন পর্যন্ত; পোস্টপ্রতি সর্বোচ্চ ৩০০; পেজের নিজের কমেন্ট ও লেখাহীন কমেন্ট বাদ)।
- উত্তরের খসড়া: নিজে লিখুন বা "AI খসড়া" (ক্রেডিট reserve → ঠিক একবার settle/release; কমেন্ট, লেখক, পোস্ট ও ব্যবসার তথ্য DATA হিসেবে বেড়া-দেওয়া; লিংক কেটে ফেলা হয়; অভিযোগ/তথ্য-নির্ভর কমেন্টে `needs_human` — কিছু বানায় না)।
- `social.approve` ক্ষমতার কেউ **হুবহু ওই সংরক্ষিত লেখা** অনুমোদন দিলে তবেই `social:reply` (প্রতি মিনিটে) Facebook-এ পাঠায়। অনুমোদনের পর লেখা বদলানো যায় না (তুলে নিয়ে বদলাতে হয়)। অনুমোদন ৩ দিনের বেশি পুরনো হলে, পেজ অচল হলে বা জরুরি-বন্ধ চালু থাকলে যায় না।
- একবার-মাত্র ক্লেইম: ফলাফল অজানা (সময়সীমা পার/নেটওয়ার্ক ত্রুটি) হলে "পাঠানো যায়নি" — নিজে নিজে আর চেষ্টা হয় না; Facebook-এ দেখে "আবার চেষ্টা" দিতে হয় (নইলে দুবার যেতে পারে)।
- ডেটাবেস: `social_comments` (RLS), `social_posts.comments_checked_at`, ৫টি অ্যাপ-ফাংশন ও ৫টি অ্যাডমিন-ওয়ার্কার ফাংশন (মাইগ্রেশন `2026_12_08_100000_create_social_comments`)।
## [0.60.0] — 2026-11-04 — Phase 23.2b (গ্রোথ বিশ্লেষণ)
- নতুন `/app/social/growth` (৭/৩০/৯০ দিন): সিস্টেম থেকে অটো-পোস্ট করা পোস্টের সংখ্যা থেকে সপ্তাহের দিন, দিনের সময় (ঢাকা), মিডিয়া, ক্যাপশনের দৈর্ঘ্য ও হ্যাশট্যাগ-সংখ্যা ধরে গড় সাড়া (রিঅ্যাকশন + ২×কমেন্ট + ৩×শেয়ার)। এই অংশ সরল হিসাব — বিনামূল্যে, AI ছাড়া।
- "এগিয়ে" চিহ্ন শুধু তখনই যখন অন্তত ৫টি পোস্টের সংখ্যা আছে এবং তুলনার মতো অন্তত দুটি গ্রুপে ২+ পোস্ট আছে; ১টি পোস্টের গ্রুপ কখনো জেতে না।
- ঐচ্ছিক "AI পরামর্শ নিন" (`social.manage`, মিনিটে ৩): AI শুধু হিসাবের সংখ্যা পায় (লক্ষ্য DATA হিসেবে বেড়া-দেওয়া), নিজে সংখ্যা বানাতে পারে না; ক্রেডিট reserve → ঠিক একবার settle/release। পর্যাপ্ত ডেটা না থাকলে কিছু reserve হয় না। শুধু পরামর্শ — কিছু বদলায় না, পোস্ট করে না।
- `posted()` এখন `media_kind`ও দেয়। নতুন DB অবজেক্ট নেই।
## [0.59.0] — 2026-11-04 — Phase 23.2a (AI কন্টেন্ট-প্ল্যান এজেন্ট)
- নতুন `/app/social/plan`: ব্যবসার বিবরণ + দিন + পোস্ট-সংখ্যা + প্ল্যাটফর্ম + সুর দিলে এজেন্ট প্রস্তাবিত পোস্ট (শিরোনাম, ক্যাপশন+হ্যাশট্যাগ, সময়, মিডিয়া আইডিয়া) দেয়। **শুধু ড্রাফট**: আপনি বাছলে তবেই সাধারণ ড্রাফট হয়ে প্ল্যানারে যায়; ছবি/ভিডিও বেছে অনুমোদনে পাঠাতে হয়। নিজে কিছু পোস্ট বা সংরক্ষণ হয় না।
- এজেন্ট সাম্প্রতিক সেরা পোস্ট (ইনসাইট থেকে) ও একই সময়ে আগে-থেকে-প্ল্যান-করা পোস্ট দেখে ভাবে; নিজে দাম/অফার/দাবি বানাতে নিষেধ।
- `social_plans` লগ (অডিট) + দৈনিক ক্যাপ: ১০/ব্যক্তি, ৩০/ওয়ার্কস্পেস। ক্রেডিট reserve→settle/release (ঠিক একবার)। `social_posts.agent_note` (মিডিয়া আইডিয়া), ফাংশন `social_plan_start/finish`, `social_post_set_note`।
- এটি একটি সীমিত একক-অনুরোধ এজেন্ট (ফেজ ২১-এর লুপ-ইঞ্জিন নয়)। বাকি ২৩.২ ধাপ: b গ্রোথ বিশ্লেষণ, c কমেন্ট-রিপ্লাই, d YouTube।

## [0.58.0] — 2026-11-04 — Phase 23c (পোস্ট ইনসাইট) — ফেজ ২৩ কোড সমাপ্ত
- নতুন: `social_post_stats` (স্ন্যাপশট, ≤৬০/পোস্ট, RLS), ভিউ `social_post_latest_stats`, ওয়ার্কার ফাংশন `social_stats_due/save` (শুধু `jarvis_admin`)।
- নতুন: `social:insights` (প্রতি ১৫ মিনিটে) — সিস্টেমে অটো-পোস্ট হওয়া Facebook পোস্টের রিঅ্যাকশন, কমেন্ট, শেয়ার (ভিডিওতে শেয়ার নেই) ও রিচ/ভিউ (পেলে) আনে; পোস্টের ১০ মিনিট পর, প্রথম ১৪ দিন প্রতি ৬ ঘণ্টা, তারপর দৈনিক, ৬০ দিন পর্যন্ত।
- নতুন পাতা `/app/social/insights` (৭/৩০/৯০ দিন, মোট, শীর্ষ ৫, টেবিল) ও পোস্টের পাতায় সংখ্যা।
- ❗ রিচ/ভিউ-এর মেট্রিক নাম Facebook বদলায় (`post_media_view` তারপর `post_impressions_unique` চেষ্টা হয়); না পেলে "—"। রিঅ্যাকশন/কমেন্ট/শেয়ার স্থিতিশীল ফিল্ড। আসল Facebook-এ যাচাই হয়নি।

## [0.57.0] — 2026-11-04 — Phase 23b (Facebook Page অটো-পোস্ট)
- নতুন: `social_accounts` (পেজ আইডি + সিল করা Page টোকেন; অ্যাপ-রোল টোকেন পড়তে পারে না), পোস্টে `account_id/publish_state/...`; ফাংশন `social_account_save/delete/credentials/mark`, `social_post_set_account/retry`, ওয়ার্কার `social_publish_claim/done/fail` (শুধু `jarvis_admin`)।
- নতুন: `social:publish` (প্রতি মিনিটে): অনুমোদিত + সময় হয়েছে + পেজ বাছা পোস্ট Facebook Graph-এ যায় (টেক্সট / ছবি / ভিডিও)। পেজ-সংযোগ পাতা `/app/social/pages` (`social.connect` — Owner/Admin)।
- ডাবল-পোস্ট ঠেকানো: প্রতি পোস্ট একবারই ক্লেম; উত্তর না এলে (১৫ মিনিট) অটো-রিট্রাই হয় না — "ব্যর্থ, পেজ দেখে নিন" হয়ে লেখকের "আবার চেষ্টা" লাগে। ছবি/ভিডিও না পেলে শুধু টেক্সট পোস্ট হয় না। টোকেন বাতিল হলে পেজ "টোকেন সমস্যা" হয়।
- নতুন কনফিগ `config/social.php` (`FACEBOOK_GRAPH_VERSION`, ডিফল্ট v24.0)।
- ❗ আসল Facebook-এ যাচাই হয়নি (টোকেন ছাড়া) — টেস্ট ফেক-HTTP দিয়ে।

## [0.56.0] — 2026-11-04 — Phase 23a (সোশ্যাল কন্টেন্ট প্ল্যানার)
- নতুন: `social_posts` টেবিল (RLS) ও ৭টি SECURITY DEFINER ফাংশন (save/submit/decide/reopen/mark_posted/cancel/delete); লেখকের নিজের ডিজাইন/তৈরি-হওয়া ভিডিও ছাড়া অন্য মিডিয়া বসানো যায় না।
- নতুন: `/app/social` — মাসের ক্যালেন্ডার (শনি-শুরু, ওয়ার্কস্পেসের সময়-অঞ্চল), ড্রাফট, ক্যাপশন (AI ক্যাপশন + হ্যাশট্যাগ, টেক্সট-ক্রেডিট), ডিজাইন/ভিডিও বাছা, অনুমোদন-ধাপ (`social.approve`), "পোস্ট হয়েছে" ট্র্যাকিং (https লিংকসহ)।
- নতুন পারমিশন `social.view|manage|approve` (Owner/Admin/Manager সব; Member/Auditor শুধু view) — `roles:sync` লাগবে।
- সীমা: নিজে থেকে পোস্ট হয় না (২৩b-তে Facebook Graph); ইনসাইট ২৩c।
- ফিক্স (লেখার সময় ধরা): হ্যাশট্যাগ পরিষ্কারে বাংলা স্বরচিহ্ন (া ি ু…) বাদ পড়ত — এখন রাখা হয়।

## [0.55.0] — 2026-11-04 — Phase 22.2c-গ (টাইমলাইন ও AI ছবি) — ফেজ ২২.২ সমাপ্ত
- নতুন: দৃশ্যের টাইমলাইন — রঙিন সময়-বার (ক্লিকে ওই দৃশ্যে যায়), দৃশ্য টেনে সাজানো, ↑ ↓, কপি; মোট ৬০ সেকেন্ড পেরোলে লাল সতর্কতা।
- নতুন: প্রতি দৃশ্যের পেছনের ছবি AI দিয়ে আঁকানো (সর্বোচ্চ ৫টি দৃশ্যে)। ক্রেডিট: রেন্ডার + প্রতি ছবির দাম একসাথে ধরে রাখা; ওয়ার্কারে যে ছবি সত্যিই এসেছে শুধু তার দাম কাটে, না এলে ওই দৃশ্য ব্র্যান্ডের রঙে আঁকা হয়। নিজের ছবি দিলে AI ছবি বাদ।
- সীমা (সততার সাথে): এটা AI ছবি + মোশন; পুরো AI-চলমান ভিডিও (Sora-জাতীয়) বানানো হয়নি — প্রোভাইডারের সঠিক API যাচাই না করে অনুমানে কোড লেখা হয়নি। প্রোভাইডার কী থাকলে আলাদা ফেজে।
- পরীক্ষা: ইউনিট ১০৯, ব্রাউজার-লজিক (jsdom দিয়ে আসল ফর্ম-স্ক্রিপ্ট চালিয়ে) ১৬।

## [0.54.0] — 2026-11-04 — Phase 22.2c-খ (ব্যাকগ্রাউন্ড মিউজিক)
- নতুন: স্লাইড-ভিডিওতে ৪টি ব্যাকগ্রাউন্ড সুর (শান্ত, উষ্ণ, চনমনে, গম্ভীর)। সুর ইঞ্জিনেই সাইন-টোন কর্ড-প্যাড হিসেবে তৈরি হয় — আমাদের নিজেদের, কোনো কপিরাইট ফাইল নেই, ফ্রি (অতিরিক্ত ক্রেডিট নেই)। ফেড ইন/আউট; ভয়েস-ওভার থাকলে ৪৫% আস্তে।
- এটা গান নয়, নরম ব্যাকগ্রাউন্ড সুর। আসল গান/মিউজিক লাইব্রেরি পরে লাইসেন্স-করা ফাইল যোগ করে দেওয়া যাবে।
- পরীক্ষা: ইঞ্জিন +১১ (আসল FFmpeg দিয়ে শব্দ-মাপা), ইউনিট ৯৬। নতুন মাইগ্রেশন নেই।

## [0.53.0] — 2026-11-04 — Phase 22.2c-ক (ক্লিপ এডিটর)
- নতুন: নিজের ভিডিও আপলোড (≤২৫ MB, ≤২ মিনিট) → শুরু/শেষ ছাঁটা, নীরব অংশ নিজে কাটা (silencedetect, ০.৭ সেকেন্ডের বেশি বিরতি, কথার দুই পাশে ০.১২ সেকেন্ড রেখে), রিল/স্টোরি/পোস্ট/অ্যাড সাইজে ক্রপ।
- ইঞ্জিন: `POST /internal/v1/video/probe`, `/video/edit`। একই কিউ (`video_jobs`) ও ক্রেডিট-নিয়ম; ছাঁটা অংশ ২–৬০ সেকেন্ড; দাম প্রতি সেকেন্ড (নীরবতা কাটলেও একই)। নতুন মাইগ্রেশন নেই।
- পরীক্ষা: ইঞ্জিন +২২ (আসল FFmpeg ও ffprobe দিয়ে), ইউনিট ৯৩।

## [0.52.0] — 2026-11-04 — Phase 22.2b (সাবটাইটেল ও AI স্ক্রিপ্ট)
- নতুন: ভিডিওতে সাবটাইটেল — ভয়েস-ওভারের লেখা থেকে (সর্বোচ্চ ৪০ লাইন, প্রতি লাইন ≤১২০ অক্ষর), বাংলা ফন্টে সঠিকভাবে আঁকা, ভয়েসের দৈর্ঘ্য (ffprobe) অনুযায়ী লেখার আকারে সময় ভাগ। শব্দ-ধরে নিখুঁত সময়-মেলানো নয়।
- নতুন: "AI দিয়ে স্ক্রিপ্ট লিখুন" — একটি ব্রিফ থেকে দৃশ্য + ভয়েস-ওভার লিখে ফর্মে বসায়; আপনি দেখে বদলান। টেক্সট-ক্রেডিট লাগে (reserve → ঠিক একবার settle/release)।
- নীরবতা কাটা আপলোড-করা ভিডিও লাগে, তাই ২২.২c-তে টাইমলাইনের সাথে যাবে। নতুন মাইগ্রেশন নেই (সাবটাইটেল-ফ্ল্যাগ `brand` JSON-এ)।
- পরীক্ষা: ইঞ্জিন +১০ (মোট ৩৬ ভিডিও টেস্ট; আসল ফ্রেম পরীক্ষাসহ), ইউনিট ৬৫।

## [0.51.0] — 2026-11-04 — Phase 22.2a (ভিডিও স্টুডিও: স্লাইড-ভিডিও)
- নতুন: `/app/video` — ১–১০টি দৃশ্য (প্রতিটি ২–৮ সেকেন্ড, মোট ≤৬০ সেকেন্ড), প্রতিটিতে শিরোনাম/উপশিরোনাম/বাটন (বাংলা সঠিকভাবে আঁকা), ব্র্যান্ড রং ও লোগো, ঐচ্ছিক নিজের ছবি; ধীরে জুম + ফেড; ঐচ্ছিক AI ভয়েস-ওভার। সাইজ: পোস্ট, পোর্ট্রেট, স্টোরি/রিল, অ্যাড।
- ইঞ্জিন: `POST /internal/v1/video/render` (FFmpeg, একবারে একটি রেন্ডার, ২৮০ সেকেন্ড টাইমআউট, ৬০ MB সীমা)।
- অ্যাসিঙ্ক কিউ: `video:work` প্রতি মিনিটে; ক্রেডিট তৈরির সময় ধরে রাখা হয় (সেকেন্ড × `CREDITS_VIDEO_PER_SECOND`), সফল হলে settle, ব্যর্থ/বাতিল হলে release। প্রতি জন ৩টি সক্রিয়, ৩০টি সংরক্ষিত।
- ডাটাবেস: `video_jobs` + ফাংশন (migration `2026_12_03_100000_create_video_jobs`)।
- পরীক্ষা: ইঞ্জিন ২৬, ডাটাবেস ৩৭, ইউনিট ৪৮ (মিউটেশন-যাচাইসহ)।

## [0.50.0] — 2026-11-04 — Phase 22.1 (ডিজাইন স্টুডিও)
- নতুন: `/app/studio` — পোস্ট, পোর্ট্রেট, স্টোরি, ফেসবুক কভার ও অ্যাড (৫ সাইজ); ব্র্যান্ড কিটের রং/লোগো/ফোন-ওয়েবসাইট নিজে বসে; PNG/JPG ও ZIP ডাউনলোড।
- বাংলা লেখা ইঞ্জিনে আসল ফন্টে (Noto Sans Bengali, OFL) আঁকা হয় — যুক্তাক্ষরসহ নির্ভুল; AI ছবিতে লেখা আঁকে না (প্রম্পটে নিষিদ্ধ)।
- পেছনের ছবি: ব্র্যান্ডের রং (ফ্রি) / নিজের ছবি আপলোড (ফ্রি) / AI (`image_gen` মডেল, প্রতি ছবি `CREDITS_IMAGE_PER_IMAGE` ক্রেডিট, ডিফল্ট ৫)।
- ক্লায়েন্ট-অনুমোদন: গোপন লিংক (৩২ বাইট র‍্যান্ডম, শুধু SHA-256 রাখা হয়), লগইন ছাড়া দেখা ও একবার উত্তর (অনুমোদন / পরিবর্তন চাই + মন্তব্য), মেয়াদ ১–৩০ দিন, বাতিল করা যায়।
- ইঞ্জিন: `/internal/v1/studio/generate`, `/internal/v1/studio/compose`। মাইগ্রেশন `2026_12_02_100000_create_studio`। ফাংশন `design_save/delete`, `review_create/revoke`, `review_public_view/decide`।
- সীমা: ডিজাইন সম্পাদনা নেই (নতুন করে বানাতে হয়); প্রতি জনে ৪০ ডিজাইন; লোগো ডিজাইন বানানোর সময়ের স্ন্যাপশট।
- ফিক্স: ফেজ ১৩ উইজেট টেস্টের অনিয়মিত ফেল (১/১৬ সম্ভাবনা)।

## [0.49.0] — 2026-11-04 — Phase 19.2d (ক্যালেন্ডার ফিড, Slack/Discord, অডিও→মিনিটস)
- নতুন: `/app/connect` — গোপন ICS লিংক (Google secret iCal / Outlook published ICS) থেকে **শুধু-পড়ার** ক্যালেন্ডার; দৈনিক পরিকল্পনায় ও ওয়ার্কফ্লো টুল `calendar.upcoming`-এ আসে।
- নতুন: Slack/Discord ওয়েবহুক (নিজের চ্যানেলে); ওয়ার্কফ্লো টুল `chat.post` (EXTERNAL — অনুমোদন-ধাপ বাধ্যতামূলক)।
- মিটিং-মিনিটস এখন অডিও ফাইল (≤৫ MB) থেকেও: ভয়েস → লেখা → মিনিটস।
- মাইগ্রেশন `2026_12_01_100000_create_user_links`; ফাংশন `link_add/link_delete/link_touch`।
- সীমা: ক্যালেন্ডার লেখা/দ্বি-মুখী সিঙ্ক নেই (OAuth লাগে); RRULE-এর উপসেট; ক্যাশ ১০ মিনিট।

## [0.48.0] — 2026-11-04 — Phase 20 (ভয়েস অ্যাসিস্ট্যান্ট)
- নতুন পেজ `/app/voice` (নেভে "ভয়েস"): ব্রাউজারের মাইকে বলুন → লেখায় রূপান্তর → সংক্ষিপ্ত AI উত্তর → ঐচ্ছিকভাবে জোরে পড়ে শোনানো (৬টি কণ্ঠ; ভাষা: স্বয়ংক্রিয়/বাংলা/English/हिन्दी/العربية)। লিখেও প্রশ্ন করা যায়। **রেকর্ডিং কোথাও জমা হয় না।**
- ইঞ্জিন: `POST /internal/v1/voice/transcribe` (অডিও→লেখা, OpenAI `/audio/transcriptions` ফরম্যাট — OpenAI, Groq, সামঞ্জস্যপূর্ণ সার্ভার) ও `/voice/speak` (লেখা→mp3, `/audio/speech`)। Anthropic/Gemini/Ollama অ্যাডাপ্টার "unsupported" দেয়।
- রেকর্ডিং ফাইলের ধরন প্রথম বাইট দেখে চেনা হয় (ব্রাউজারের দাবি বিশ্বাস নয়): webm/ogg/mp3/wav/m4a; ২০০ বাইট–৫ MB।
- মডেল বাছাই সক্ষমতা দিয়ে: শোনার জন্য `audio_in`, বলার জন্য `audio_out` (অ্যাডমিন মডেলে চালু করবেন; routing task `voice_stt` / `voice_tts`)।
- ক্রেডিট (সোর্স `voice`, নতুন মাইগ্রেশন লাগে না): শোনা — প্রতি MB (`CREDITS_VOICE_LISTEN_PER_MB`, ডিফল্ট ১); বলা — প্রতি ১০০০ অক্ষর (`CREDITS_VOICE_SPEAK_PER_1K`, ডিফল্ট ১); উত্তর — টোকেন হারে। মডেলের গুণক প্রযোজ্য, ০.০০০১ পর্যন্ত উপরের দিকে গোল। প্রতিটি ধাপে reserve → ঠিক একটি settle/release; আগের সফল ধাপের খরচ পরের ধাপ ব্যর্থ হলেও থাকে।
- জার্ভিস এখানে শুধু কথোপকথন করে (কাজ করে না, ভান করে না); ট্রান্সক্রিপ্ট "ডেটা" হিসেবে বেড়া-দেওয়া।
- নতুন: `app/Voice/*`, `VoiceController`, `VoiceServiceProvider`, ইঞ্জিন `routes_voice.py`, `app/voice/audio.py`, ভিউ `voice/index`।
- টেস্ট: ইঞ্জিন +১৭ (২৭৭), PHP +১৭; mutation: বাকি ১টি সমতুল্য।

## [0.47.0] — 2026-11-30 — Phase 21a (এজেন্ট ইঞ্জিন ভিত্তি)
- নতুন পেজ `/app/agents` (নেভে "এজেন্ট"): **ওয়ার্কফ্লো** = ধাপে ধাপে চলা কাজ। ধাপের ধরন: AI, টুল, শর্ত, অনুমোদন, ফলাফল। প্রতিটি ধাপ লগ হয় (ধরন, ফল, ক্রেডিট)। ৫টি তৈরি টেমপ্লেট: সকালের পরিকল্পনা (প্রতিদিন ০৮:০০ ঐচ্ছিক), ইমেইলের উত্তরের খসড়া, নলেজ বেস থেকে উত্তর, সাপ্তাহিক টাস্ক রিপোর্ট, আইডিয়া→টাস্ক (অনুমোদনসহ)।
- **টুল রেজিস্ট্রি + ঝুঁকির শ্রেণি** (read / write_own / external / money): ৬টি টুল — `tasks.add`, `tasks.list`, `plan.today`, `notes.add`, `knowledge.search`, `notify.me`। সবই শুধু নিজের ডেটায়। money টুল ওয়ার্কফ্লোতে নিষিদ্ধ; external টুলের আগে প্রতিটি পথে অনুমোদন-ধাপ বাধ্যতামূলক (সংরক্ষণে ও চালানোর সময় দুইবার যাচাই)। এই সংস্করণে external/money টুল আদৌ নেই।
- ডেফিনিশন JSON (≤৩০ ধাপ, লুপ নেই, ≤৫ ইনপুট); সংরক্ষণে বাংলা ত্রুটি-তালিকা। ভিজ্যুয়াল ক্যানভাস পরের উপ-ফেজে।
- **বাজেট ও সীমা**: প্রতি রানের ক্রেডিট-সীমা (ডিফল্ট ৫); AI ধাপের সর্বোচ্চ মূল্য বাকি বাজেটের বেশি হলে ধাপ শুরুই হয় না। রান প্রতি ≤৪০ ধাপ; ১৫ মিনিটের বেশি আটকে থাকা রান ব্যর্থ। AI ধাপে প্রোভাইডার-ত্রুটিতে ১ বার রিট্রাই (প্রতি চেষ্টায় আলাদা reserve → ঠিক একটি settle/release)। উৎস `agent`।
- অনুমোদন: রান থেমে `waiting_approval`; মালিক ওয়েবে অনুমোদন/বাতিল দিলে কিউতে ফেরে ও ওই ধাপ থেকে চলে; নোটিফিকেশন যায়। যেকোনো সময় বন্ধ করা যায়।
- রান চলে কমান্ড `workflows:work` (প্রতি মিনিটে; শিডিউল-ডিউ → কিউ → প্রতিটি রান নিজের ওয়ার্কস্পেসে)। পুরনো (৩০ দিন+) রান মোছা হয়।
- নতুন: `phase21a.up/down.sql`, মাইগ্রেশন `2026_11_30_100000_create_workflows`, `app/Workflow/*`, `WorkflowController`, `WorkflowServiceProvider`, `WorkflowsWorkCommand`, ভিউ `workflow/{index,edit}`।
- টেস্ট: DB +৪৪, PHP +৪৫; mutation: বাকি শুধু সমতুল্য ৫টি ও ১টি (knowledge টুলে user আইডি) — ফেক রেকর্ড করে না।

## [0.46.0] — 2026-11-29 — Phase 19.2c (লেখা-সহকারী: মিটিং সারাংশ ও ইমেইল)
- নতুন পেজ `/app/assist`: (১) **মিটিং সারাংশ** — ট্রান্সক্রিপ্ট/নোট পেস্ট (≤২০,০০০ অক্ষর) → সারাংশ, সিদ্ধান্ত, করণীয় (দায়িত্বপ্রাপ্ত ও তারিখসহ); করণীয় টিক দিয়ে এক ক্লিকে **টাস্কে** (১৯.২এ) যোগ। (২) **ইমেইল সারাংশ** — মূল পয়েন্ট, সময়সীমা, উত্তর দরকার কিনা। (৩) **ইমেইল লেখা/উত্তর** — পয়েন্ট ও সুর (আনুষ্ঠানিক/বন্ধুত্বপূর্ণ/সংক্ষিপ্ত) দিয়ে খসড়া; অজানা তথ্যে [ফাঁকা চিহ্ন]।
- কিছুই **পাঠানো বা সংরক্ষণ হয় না** (পেজ ছাড়লে ফল যায়); ইমেইল ব্যবহারকারী নিজে কপি করে পাঠান। পেস্ট-করা লেখা "ডেটা" হিসেবে বেড়া-দেওয়া, ভেতরের নির্দেশ না মানার নিয়ম প্রম্পটে; উত্তর `AssistResult`-এ প্লেইন টেক্সটে নামানো ও সীমায় ছাঁটা, তারিখ কেবল বৈধ `YYYY-MM-DD` হলে রাখা হয়।
- ক্রেডিট: reserve → settle/release, সোর্স `assist`; অপাঠ্য উত্তরেও চার্জ (প্রোভাইডার পেয়েছে)।
- নতুন: `phase192c.up/down.sql` (শুধু usage সোর্স `assist`), মাইগ্রেশন `2026_11_29_100000_assist_usage_source`, `AssistServiceProvider`, `app/Assist/*`, `AssistController`।
- টেস্ট: DB +১ (সোর্স), PHP ১৩টি নতুন; mutation: বাকি সব সমতুল্য।

## [0.45.0] — 2026-11-28 — Phase 19.2b (AI দিয়ে ডকুমেন্ট / স্প্রেডশিট / প্রেজেন্টেশন তৈরি)
- নতুন পেজ `/app/create`: ধরন (Word / Excel / PowerPoint) + বর্ণনা (≤২০০০ অক্ষর) → AI একটি কাঠামোবদ্ধ খসড়া বানায় → সংরক্ষিত (ব্যক্তিগত, সর্বোচ্চ ১০০টি) → ডাউনলোড। ডাউনলোডে ফাইল কাঠামো থেকে বানানো হয় — মডেল ডাকে না, ক্রেডিট লাগে না।
- AI ইঞ্জিন: `POST /internal/v1/office/{docx|xlsx|pptx}` (সাইনড, `app/office/*`) — কড়া আকার-সীমা (ডক ≤৩০০ ব্লক; শিট ≤১০টি × ২০০০ সারি × ৫০ কলাম; স্লাইড ≤৪০), কন্ট্রোল ক্যারেক্টার ছাঁটা, শিট-নাম পরিষ্কার ও অনন্য। **শিটের কোনো ঘর সূত্র (formula) হয় না** — `=…`/`+…` লেখাও টেক্সট থাকে (CSV/Excel injection বন্ধ)।
- Laravel: `OfficeService` (reserve → settle/release, সোর্স `docgen`; উত্তর ব্যবহারযোগ্য না হলেও চার্জ — প্রোভাইডার পেয়ে গেছে), `OfficeSpec::parse` (মডেলের উত্তর বিশ্বাস করে না: জানা ফিল্ডই রাখে, সীমায় ছাঁটে), `OfficePrompt` (অনুরোধ বেড়া-দেওয়া; অজানা তথ্যের জায়গায় [নাম]/[Amount] বসাতে বলা), `OfficeController`, `GatewayOfficeEngine`।
- নতুন: `phase192b.up/down.sql` (`generated_files` + RLS + `genfile_save/delete`; usage সোর্স `docgen`), মাইগ্রেশন `2026_11_28_100000_create_generated_files`, `OfficeServiceProvider`; ইঞ্জিনে নতুন নির্ভরতা `openpyxl`, `python-pptx`।
- বাগ-ফিক্স (টেস্টে ধরা): টেবিল-হেডার বোল্ড হচ্ছিল না; বাংলা ফাইলনাম থেকে স্বরচিহ্ন (combining marks) বাদ পড়ছিল।
- টেস্ট: ইঞ্জিন ২৬টি নতুন (মোট ২৫৮), DB ১২টি, PHP ২১টি।

## [0.44.0] — 2026-11-27 — Phase 19.2a (টাস্ক, রিমাইন্ডার, নোট, দৈনিক পরিকল্পনা)
- নতুন পেজ `/app/tasks` (টাস্ক + আজকের পরিকল্পনা) ও `/app/notes` (ব্যক্তিগত নোট)। সব তথ্য শুধু নিজের — ওয়ার্কস্পেসের অন্য কেউ (মালিকসহ) দেখে না। কোনো ক্রেডিট বা বাইরের key লাগে না।
- টাস্ক: শিরোনাম (≤২০০), বিবরণ (≤২০০০), গুরুত্ব (জরুরি/সাধারণ/পরে), শেষ তারিখ, রিমাইন্ডার-সময় (ব্যবহারকারীর টাইমজোনে), টিক/আনটিক, সম্পাদনা, মোছা, শেষ-হওয়াগুলো একসাথে মোছা। সীমা: চলমান ৫০০টি, নোট ১০০০টি।
- রিমাইন্ডার: `php artisan tasks:remind` (প্রতি মিনিট, শিডিউলারে) — সময় হলে প্রতিটি টাস্কে **একবারই** নোটিফিকেশন (অ্যাপের ভেতরে + মোবাইল অ্যাপ থাকলে `mobile:push` দিয়ে ফোনে)। সময় বদলালে আবার সক্রিয়; শেষ-হওয়া টাস্ক রিমাইন্ড করে না; ২ দিনের বেশি পুরনো জমা রিমাইন্ডার নোটিফিকেশন ছাড়াই "জানানো" ধরা হয় (ফোন ভরে না যাওয়ার জন্য)।
- দৈনিক পরিকল্পনা (`DailyPlan`): মেয়াদোত্তীর্ণ → আজকের → আজকের রিমাইন্ডার → আগামী ৩ দিন → তারিখহীন জরুরি; সর্বোচ্চ ৫টি "প্রথমে করুন"। AI ছাড়া, বিনা খরচে।
- নতুন: `phase192a.up/down.sql` (টেবিল `tasks`, `notes`, RLS; ফাংশন `task_add/edit/set_done/delete/clear_done`, `note_save/delete`, `task_reminders_dispatch`), মাইগ্রেশন `2026_11_27_100000_create_productivity`, `ProductivityServiceProvider`, `TasksRemindCommand`।
- টেস্ট: DB ৩৫টি, PHP ১৩টি; mutation: একটি সমতুল্য বাদে সব মারা পড়েছে।

## [0.43.0] — 2026-11-26 — Phase 19d (ছবি পড়া / OCR)
- AI ইঞ্জিন: নতুন সাইনড এন্ডপয়েন্ট `POST /internal/v1/vision/read` (`app/routes_vision.py`, `app/vision/read.py`) — ছবি/PDF → সাধারণ টেক্সট। দুই মোড: `transcribe` (OCR: হুবহু লেখা, টেবিল `|` দিয়ে, লেখা না থাকলে `NO_TEXT`) ও `describe` (ছোট বর্ণনা + "Text:" লেখা)। ছবি অবিশ্বস্ত ডেটা — প্রম্পটে ভেতরের নির্দেশ না মানার কথা; উত্তর পরিষ্কার করা হয় (কন্ট্রোল ক্যারেক্টার, ```বেড়া, বাড়তি ফাঁকা লাইন বাদ, ≤৬০,০০০ অক্ষর)। ছবি রি-এনকোড হয় (EXIF/GPS বাদ), ≤৬ পৃষ্ঠা। **লেখা-স্তরওয়ালা PDF-এ মডেল ডাকা হয় না** (০ টোকেন)।
- Laravel: `VisionReader` (reserve → settle/release, ছবি পড়ার সোর্স `vision`; লেখা-স্তরওয়ালা PDF-এ খরচ নেই, প্রোভাইডারকে টাকা দিলে "খালি" উত্তরেও খরচ কাটে), `ProviderGateway::readImage`, `CatalogReadPicker` (টাস্ক `vision_read`, মডেলে `vision` ক্ষমতা লাগে), `VisionText` (বাংলা বার্তা), `ImageAttachment`।
- নলেজ বেস: ছবি (JPG/PNG/WebP) ও স্ক্যান-করা PDF এখন নেওয়া যায় — **"ছবি/স্ক্যান পড়ে নিন" টিক দিলে তবেই** AI পড়ে (ক্রেডিট খরচ; না দিলে "টিক দিন" বার্তা)। সার্ভারে `pdftotext` না থাকলেও টিক দিয়ে PDF পড়া যায়।
- চ্যাট: ছবি সংযুক্ত করলে (একবারে ≤২টি, ≤৫ MB, ধরন বাইট দেখে) AI আগে বর্ণনা+লেখা পড়ে, সেটি লেবেলসহ টেক্সট-সংযুক্তি হিসেবে চ্যাট মডেলকে যায় (লেবেল: "ছবি থেকে AI-এর পড়া তথ্য — ভুল থাকতে পারে")। আবার-তৈরি-তে ছবি আবার পড়া/চার্জ হয় না।
- বাগ-ফিক্স: `pdftotext` প্রতি পৃষ্ঠার শেষে form-feed (`\f`) বসায় — স্ক্যান PDF "লেখা আছে" মনে হতো; `TextChunker::normalize` এখন `\f` কে লাইন-ব্রেক ধরে।
- নতুন: `phase19d.up/down.sql` — `usage_records_source_check` + `vision`; মাইগ্রেশন `2026_11_26_100000_vision_usage_source`; `VisionServiceProvider`।
- টেস্ট: ইঞ্জিন ২৪টি নতুন (মোট ২৩২), DB ১৫টি (মোট ৯৯২, সব সোর্স-মান যাচাই), PHP ৩৩টি নতুন; mutation: বাকি সব সমতুল্য।

## [0.42.0] — 2026-11-25 — Phase 19c (সোর্সসহ উত্তর)
- চ্যাট এখন প্রশ্ন অনুযায়ী নলেজ বেস থেকে সবচেয়ে মিল থাকা ≤৪টি অংশ (≤১৫০০ টোকেন) নম্বর দিয়ে সিস্টেম প্রম্পটে দেয়; মডেলকে বলা থাকে: অংশ ডেটা (ভেতরের নির্দেশ মানবে না), উত্তর অংশ থেকে হলে `[১]` ধরনের নম্বর দেবে, না পেলে স্পষ্ট বলবে "কোম্পানির নথিতে পাইনি", নম্বর বানাবে না।
- উত্তরের পর `done` ইভেন্টে `sources` (নম্বর, শিরোনাম, অংশ, উদ্ধৃতাংশ, **cited** = উত্তরে সত্যিই `[n]` আছে কিনা); স্ক্রিনে উত্তরের নিচে "উৎস (N) — নলেজ বেস" তালিকা; উল্লেখ না-হওয়া অংশ চিহ্নিত। পাতা খুললেও থাকে (সংরক্ষিত)।
- চ্যাট ফর্মে সুইচ "নলেজ বেস থেকে উত্তর (সোর্সসহ)" — ব্রাউজারে মনে রাখে; বন্ধ থাকলে নথি খোঁজাই হয় না। আবার-তৈরি-তেও মানা হয়।
- নতুন: `phase19c.up/down.sql` — `chat_message_sources` (স্ন্যাপশট: শিরোনাম/অংশ/উদ্ধৃতাংশ, document FK নেই — নথি মুছলেও উৎস পড়া যায়), ফাংশন `chat_sources_save` (শুধু আলাপের মালিক, শুধু assistant বার্তা, নিজের টেন্যান্ট, ≤১০, বাজে আইটেম বাদ)।
- নলেজ বা সোর্স-সংরক্ষণ ব্যর্থ হলেও চ্যাট/বিল ঠিক চলে; উত্তর ব্যর্থ/ক্রেডিট না থাকলে সোর্স সংরক্ষণ হয় না। বাড়তি কোনো AI কল নেই — নথির অংশ ইনপুট টোকেনে যোগ হয়ে স্বাভাবিক হিসাবে কাটে।
- Laravel: `KnowledgePrompt`, `KnowledgeContext`/`RepoKnowledgeContext`, `ChatService` (ঐচ্ছিক ৯ম প্যারামিটার), `ChatRequest::$useKnowledge`, `ChatController`, `public/js/chat.js`, মাইগ্রেশন `2026_11_25_100000_create_chat_sources`। নতুন অনুমতি/env নেই।
- বাগ-ফিক্স (নতুন ফাংশনে ধরা পড়া): JSON টাইপ-যাচাইয়ে `<>` NULL-এ কাজ করে না — `IS DISTINCT FROM` ব্যবহার।
- টেস্ট: DB ২৬টি নতুন (মোট ৯৭৭), PHP ২৫টি নতুন (`KnowledgePromptTest` ৭, `ChatKnowledgeTest` ৮ …); mutation: বাকি সব সমতুল্য।

## [0.41.0] — 2026-11-24 — Phase 19b (নলেজ বেস)
- নতুন: `phase19b.up/down.sql` — `kb_documents` (শিরোনাম, ফাইল, আকার, চালু/বন্ধ, একই লেখা দুবার নয় — টেন্যান্টপ্রতি text_hash ইউনিক) ও `kb_chunks` (অংশ ≤২০০০ অক্ষর, `tsvector` GENERATED + GIN ইনডেক্স); RLS ENABLE+FORCE, অ্যাপ রোল শুধু SELECT; ফাংশন `kb_document_save/update/delete` (SECURITY DEFINER), `kb_query`, `kb_search`।
- খোঁজ: কোনো এক্সটেনশন ছাড়া PostgreSQL full-text (`simple` কনফিগ, বাংলা শব্দ ঠিকঠাক ভাঙে), প্রশ্নের যেকোনো শব্দ মিললেই চলে, ৩+ অক্ষরের শব্দ প্রিফিক্সে মেলে (ঢাকা → ঢাকায়), `ts_rank_cd` দিয়ে সাজানো, সর্বোচ্চ ২০টি; প্রশ্ন ১০০০ অক্ষর ও ১২ শব্দ পর্যন্ত। pgvector লাগে না — cPanel-এও চলবে।
- সীমা: টেন্যান্টপ্রতি ৫০ ডকুমেন্ট, ডকুমেন্টপ্রতি ৬০০ অংশ, ফাইল ≤৫ MB।
- ফাইল পড়া (`TextExtractor`): TXT/MD/CSV/TSV/JSON/LOG, HTML (script/style বাদ), DOCX (ZipArchive), PDF (সার্ভারে `pdftotext` থাকলে; না থাকলে পরিষ্কার বার্তা)। অংশ ভাগ (`TextChunker`): অনুচ্ছেদ → বাক্য (দাঁড়ি `।` সহ) → লাইন → শেষে জোর করে কাটা; ~৮০০ অক্ষর।
- AI/ক্রেডিট লাগে না (এই ধাপে কোনো AI কল নেই)।
- Laravel: `app/Knowledge/*`, `KnowledgeController`, `/app/knowledge` (দেখা/খোঁজা সব সদস্য; যোগ/নাম বদল/বন্ধ/মোছা শুধু `knowledge.manage` — Owner, Admin), নেভ লিংক "নলেজ বেস", মাইগ্রেশন `2026_11_24_100000_create_knowledge`, `KnowledgeServiceProvider`।
- টেস্ট: DB ৩৩টি নতুন (মোট ৯৫১), PHP ৩৩টি নতুন; mutation: বাকি দুর্বলতা সব সমতুল্য।

## [0.40.0] — 2026-11-23 — Phase 19a (মেমোরি সেন্টার)
- নতুন: `phase19a.up/down.sql` — `memories` (ব্যক্তিগত `user` / ব্যবসার `business`, বিভাগ, পিন, চালু/বন্ধ, উৎস user|suggested, ≤৫০০ অক্ষর, একই লেখা দুবার নয়), `memory_settings` (চ্যাটে মেমোরি ব্যবহার চালু/বন্ধ), `memory_suggestions` (AI-র প্রস্তাব: pending|accepted|dismissed); RLS ENABLE+FORCE, অ্যাপ রোল শুধু SELECT, লেখা SECURITY DEFINER ফাংশনে।
- গোপনীয়তা-পাহারা `memory_check_text`: পাসওয়ার্ড/PIN/OTP/CVV/টোকেন/API key/সিক্রেট (বাংলা শব্দসহ), ১৩+ অঙ্কের নম্বর, কী-প্রিফিক্স ও ৩২+ অক্ষরের টোকেন-সদৃশ লেখা সংরক্ষণ হয় না। PHP `MemoryGuard` একই নিয়ম, দুটির সমতা টেস্টে যাচাই।
- সীমা: ব্যক্তিপ্রতি ২০০, ব্যবসার ১০০; প্রস্তাব প্রতিবার ≤৫, অপেক্ষমাণ ≤২০। ব্যক্তিগত নোট সহকর্মী দেখে/বদলায় না; ব্যবসার নোট বদলাতে `memory.manage` (Owner, Admin)।
- চ্যাট: চালু নোট (পিন আগে, ৬০০ টোকেন বাজেট) সিস্টেম প্রম্পটে যোগ হয়; মেমোরি ব্যর্থ হলে চ্যাট চলে। সব মুছে ফেলা শুধু নিজের ব্যক্তিগত নোট ও প্রস্তাব।
- "কথোপকথন থেকে প্রস্তাব নিন": AI শুধু প্রস্তাব দেয়, ব্যবহারকারী গ্রহণ করলে তবেই নোট হয়; ক্রেডিট reserve → settle/release, `usage_records.source` CHECK-এ `memory` যোগ।
- Laravel: `app/Memory/*`, `MemoryController`, `/app/memory`, নেভ লিংক "মেমোরি", মাইগ্রেশন `2026_11_20_100000_create_memory`, নতুন অনুমতি `memory.manage` (`roles:sync` লাগবে)।
- টেস্ট: DB ৭৭টি নতুন (মোট ৯১৮), PHP ১৮+ নতুন; mutation: SQL ও PHP-র বাকি দুর্বলতা সমতুল্য (বন্ধ করা হয়েছে বা সমতুল্য)।

## [0.39.0] — 2026-11-22 — Phase 18d (আর্থিক বিশ্লেষক: বাংলা ব্যাখ্যা ও আনুমানিক পূর্বাভাস)
- **বাগ-ফিক্স (গুরুত্বপূর্ণ):** `usage_records.source` CHECK-এ শুধু ৯টি মান ছিল, অথচ অ্যাপ `catalog` (AI পণ্য-লেখক), `bill_scan` (বিল স্ক্যান) ও `inbox` (সাপোর্ট এজেন্ট/অর্ডার মডেল) দিয়ে settle করত — ফলে `credit_settle()` ব্যর্থ হতো: ওই AI ব্যবহারে ক্রেডিট কাটা হতো না, ব্যবহারও লেখা হতো না (বরাদ্দ পরে ফেরত যেত)। `phase18d`-এ CHECK বাড়িয়ে `catalog, bill_scan, inbox, analyst` যোগ; সব সোর্সের রিগ্রেশন টেস্ট।
- নতুন: `phase18d.up/down.sql` — `analyst_reports` (facts + forecast + বাংলা লেখা, source ai|template, ব্যবহৃত টোকেন/ক্রেডিট), ফাংশন `analyst_report_save`; RLS, অ্যাপ রোল শুধু SELECT।
- সংখ্যা বই থেকে নির্ধারিত (`Facts`): ১২ মাসের আয়/খরচ/লাভ/নগদ প্রবাহ, আগের মাসের তুলনা, যে খরচ বেড়েছে/কমেছে, আয়ের উৎস, নগদ-ব্যাংক-ওয়ালেট ব্যালেন্স, পাওনা/দেনা, টানা ক্ষতির মাস। শেষ হওয়া মাস পর্যন্তই (চলমান মাস বাদ)।
- পূর্বাভাস (`Forecast`): শেষ ≤৬ মাসের সরলরেখা-ট্রেন্ড, ৩ মাস সামনে, ±১ গড়-বিচ্যুতি সীমা, নগদ কত মাস চলবে; ৩ মাসের কম তথ্য হলে দেয় না। সর্বত্র "আনুমানিক" চিহ্নিত।
- বাংলা ব্যাখ্যা: AI শুধু দেওয়া সংখ্যা থেকে লেখে; `NarrativeCheck` লেখার প্রতিটি সংখ্যা বইয়ের সংখ্যার সাথে মেলায় আর পূর্বাভাসে "আনুমানিক" শব্দ আবশ্যক — না মিললে নির্ধারিত বাংলা টেমপ্লেট দেখায় (AI-র খরচ তবু কাটে, কারণ প্রদানকারী টাকা নিয়েছে)। মডেল/ক্রেডিট না থাকলে বা AI বন্ধ রাখলে টেমপ্লেট, খরচ ছাড়াই।
- ক্রেডিট: reserve → (সফলে) settle / (ব্যর্থে) release, বিল-স্ক্যানের মতো একই নিয়ম।
- Laravel: `app/Analyst/*`, `AccountingAnalystController`, `/app/accounting/analyst`, দুটি স্ক্রিন, নেভ লিংক; মাইগ্রেশন `2026_11_19_100000_create_analyst`। নতুন অনুমতি নেই (দেখা `accounting.view`, চালানো `accounting.manage`)।
- টেস্ট: DB ৩৫টি নতুন (মোট ৮৪১), PHP ৩৩টি নতুন (`AnalystFactsTest` ১৭, `AnalystServiceTest` ১৬); mutation: SQL ১৪/১৫ (১টি সমতুল্য), PHP বাকি ১টি সমতুল্য।

## [0.38.0] — 2026-11-21 — Phase 18c (পে-স্লিপ ও বেতন ফাইল)
- নতুন: `phase18c.up/down.sql` — `payroll_pay_details` (কর্মীপ্রতি বেতন-পদ্ধতি: ব্যাংক / বিকাশ / নগদ / নগদ-টাকা; পদ্ধতিভেদে CHECK), ফাংশন `payroll_pay_details_save` (ok | bad_input; অন্য টেন্যান্টের কর্মী → forbidden)। ওয়ালেটের `+88`/`88` ও ফাঁকা বাদ যায়।
- বেতন ফাইল: হিসাবে-তোলা/পরিশোধিত রান থেকে ব্যাংক, বিকাশ, নগদ — আলাদা CSV (UTF-8 BOM, সূত্র-ইনজেকশন-নিরাপদ); নগদ-টাকার কর্মী ও পেমেন্ট-তথ্যহীনদের আলাদা তালিকা (ফাইলে ঢোকে না)। অ্যাপ টাকা পাঠায় না।
- পে-স্লিপ: প্রতি কর্মী বা সবার, প্রিন্ট-ফ্রেন্ডলি (ব্রাউজার থেকে PDF সেভ)। স্লিপে ব্যাংক/ওয়ালেট নম্বর নেই।
- ব্যাংক/ওয়ালেট নম্বর শুধু `payroll.manage`-এর স্ক্রিন ও ফাইলে; স্ক্রিনে শেষ ৪ অঙ্ক ছাড়া মাস্ক করা।
- Laravel: `SalaryFile`, রিপো/কন্ট্রোলার সম্প্রসারণ, ৪টি রুট, `paydetails` ও `slips` স্ক্রিন, রান ও কর্মী পাতায় লিংক; মাইগ্রেশন `2026_11_18_100000_create_payroll_pay`।
- টেস্ট: DB ২৬টি নতুন (মোট ৮০৬), PHP `SalaryFileTest` ৬; mutation ১৬/১৬ ধরা (প্রথমে ৪টি বেঁচে গিয়েছিল — নতুন টেস্টে ঠিক)।

## [0.37.0] — 2026-11-20 — Phase 18b (পে-রোল: কর্মী, বেতন-কাঠামো, মাসিক রান)
- নতুন: `phase18b.up/down.sql` — `payroll_employees`, `payroll_components` (তারিখ-ভিত্তিক, রেইজে ইতিহাস থাকে), `payroll_runs`, `payroll_lines`; ফাংশন `payroll_employee_save/active`, `payroll_component_save/end`, `payroll_run_create`, `payroll_adjust_add/remove`, `payroll_run_post/pay/void`।
- রানের জীবনচক্র: খসড়া → হিসাবে তোলা (ডেবিট বেতন-খরচ; ক্রেডিট বেতনের আয়কর / অন্যান্য কর্তন / বেতন পরিশোধযোগ্য) → পরিশোধ (ডেবিট বেতন পরিশোধযোগ্য; ক্রেডিট ক্যাশ/ব্যাংক)। পরিশোধের আগে বাতিল করলে রিভার্সাল এন্ট্রি।
- কোনো কর-স্ল্যাব/PF-নিয়ম কোডে নেই; আয়কর কর্তন ব্যবহারকারী-চিহ্নিত লাইন। প্রয়োজনীয় দুটি দেনা-অ্যাকাউন্ট (`salary_tax_payable`, `salary_deductions`) প্রথম ব্যবহারে নিজে তৈরি হয়।
- নতুন অনুমতি `payroll.view` (Owner, Admin, Auditor) ও `payroll.manage` (Owner, Admin) — Manager বেতন দেখে না। `php artisan roles:sync` চালাতে হবে।
- Laravel: `app/Payroll/*`, `AccountingPayrollController`, `/app/accounting/payroll…`, ৪টি স্ক্রিন, নেভ লিংক।
- টেস্ট: DB ৪৪টি নতুন (মোট ৭৮০), PHP `PayrollTextTest` ৪; mutation ৪৫টির ৪০টি টেস্টে ধরা, বাকি ৫টির ২টি সমতুল্য ও ৩টির জন্য নতুন টেস্ট যোগ করে ধরা হয়েছে।

## [0.36.0] — 2026-11-19 — Phase 18a (অডিটর: ফাইন্ডিং, চেকলিস্ট, স্বাস্থ্য-পরীক্ষা)
- নতুন: `phase18a.up/down.sql` — `audit_settings`, `audit_findings`, `audit_items`, `audit_item_done`; ফাংশন `audit_scan` (৮টি নিয়ম: ডুপ্লিকেট ডকুমেন্ট/পেমেন্ট/এন্ট্রি, অস্বাভাবিক বড় অঙ্ক, ব্যাক-ডেটেড, গোল-অঙ্কের ম্যানুয়াল এন্ট্রি, বড় নগদ পেমেন্ট, এন্ট্রি-নম্বরে ফাঁক), `audit_review`, `audit_settings_save`, চেকলিস্ট ফাংশন, `audit_health`।
- ফাইন্ডিং প্রশ্ন, রায় নয়: নিশ্চিত/বাদ দিতে হয় (বাদ দিলে কারণ আবশ্যক); একবার ধরা বিষয় আর নতুন করে ওঠে না; হিসাবের বইয়ে কিছু লেখে না।
- নতুন অনুমতি `accounting.audit` (Owner, Admin, Auditor) — `php artisan roles:sync` চালাতে হবে।
- Laravel: `app/Audit/*`, `AccountingAuditController`, `/app/accounting/audit`, স্ক্রিন, নেভ লিংক।
- টেস্ট: DB ২২টি নতুন (মোট ৭৩৬), PHP `AuditTextTest` ৬; mutation ৪০/৪০ ধরা পড়েছে।
- ফিক্স (টেস্টে ধরা): `round(double,int)` টাইপ-ভুল, `sum()` numeric→bigint, কলাম-নামের অস্পষ্টতা।

## [0.35.0] — 2026-11-18 — Phase 17c (কর ক্যালেন্ডার)
- নতুন: `phase17c.up/down.sql` — `tax_obligations` (মাসিক/ত্রৈমাসিক/বার্ষিক/একবার), `tax_obligation_done`; ফাংশন `tax_obligation_save/active`, `tax_cal_done/undo/seed`, `tax_due_matches`।
- কোনো সরকারি তারিখ কোডে নেই: প্রতিটি দিন/মাস ব্যবহারকারীর ডাটা; শুরুর খসড়া তালিকা (৫টি) একবারই নেওয়া যায়, যাচাইয়ের সতর্কতাসহ।
- Laravel: `TaxCalendar` (বিশুদ্ধ তারিখ-হিসাব, ৩১ তারিখ → ছোট মাসের শেষ দিন), `AccountingTaxCalController`, `/app/accounting/calendar`, স্ক্রিন, নেভ লিংক।
- টেস্ট: DB ৪৮টি নতুন (মোট ৭১৪), PHP `TaxCalendarTest` ১০; mutation ১৫/১৫ ধরা পড়েছে।
- ফিক্স (টেস্টে ধরা): সময়সূচির শর্তে NULL-অনিরাপদ তুলনা → `coalesce`।

## [0.34.0] — 2026-11-17 — Phase 17b (উৎসে কর: VDS/TDS ও অগ্রিম আয়কর AIT)
- নতুন: `phase17b.up/down.sql` — `tax_rules` (তারিখ-ভিত্তিক হার, সম্পাদনযোগ্য), `tax_withholdings`, `tax_deposits`, `tax_advances`; ফাংশন `tax_rule_save`, `tax_settle`, `tax_cert_set`, `tax_deposit`, `tax_advance_pay/void`।
- বিল পেমেন্টে কাটা কর → দায় (২৩০০ করপ্রদেয়); ইনভয়েস পেমেন্টে কাটা কর → সম্পদ (অগ্রিম কর); সরকারি কোষাগারে জমা → দায় কমে।
- জমা হয়ে যাওয়া কর-কাটা পেমেন্ট বাতিল ট্রিগার দিয়ে আটকানো (`withheld_tax_deposited`)।
- Laravel: `AccountingTaxController`, `/accounting/tax…`, ২টি স্ক্রিন, নেভ লিংক, ডক-পাতায় "কর কেটে পেমেন্ট"।
- টেস্ট: DB ৪০টি নতুন (মোট ৬৬৬), PHP `TaxItemsTest` ১৬।

## [0.33.0] — 2026-11-16 — Phase 17a (মূসক/VAT: সেটিং, হার, ক্রয়-বিক্রয় বই, রিটার্ন খসড়া)
### Added
- **নীতি:** হার, রিটার্নের সময়কাল (মাসিক/ত্রৈমাসিক) ও তারিখ **কোডে নয়, ব্যবহারকারীর সেটিং** — কারণ NBR-এর সূত্রগুলো পরস্পরবিরোধী (অর্থ আইন ২০২৬-এর পর মাসিক না ত্রৈমাসিক তা সূত্রভেদে ভিন্ন)। স্ক্রিনে সতর্কবার্তা: হিসাবরক্ষক/NBR-এর সাথে মেলান।
- **ডাটাবেস (`phase17a.up/down.sql`, মাইগ্রেশন `2026_11_14_100000_create_vat`):** `vat_settings` (নিবন্ধিত?, BIN ৯–১৩ অঙ্ক, মাসিক/ত্রৈমাসিক), `vat_rates` (বেসিস পয়েন্ট, তারিখ-ভিত্তিক; নতুন হার আগেরটিকে আগের দিনে শেষ করে; ওভারল্যাপ/ব্যাকডেট নিষিদ্ধ), `vat_doc_flags` (বিল/খরচের মূসক ক্রেডিট নেওয়া যাবে কি না — না হলে কারণ বাধ্যতামূলক), `vat_returns` (খসড়া/ফাইল-করা snapshot; ফাইল-করা সময়ে আর খসড়া বদলায় না) — RLS ENABLE+FORCE, অ্যাপ রোল শুধু পড়ে; লেখা ফাংশনে।
- **Laravel (`App\Vat`):** `VatCalc` (পূর্ণসংখ্যায় half-up মূসক, তারিখে হার খোঁজা, % পার্স), `VatBooks` (বিক্রয়/ক্রয় বই, আউটপুট − ক্লেইমযোগ্য ইনপুট = নিট; ঋণাত্মক = টানার ক্রেডিট; void বাদ; পিরিয়ড নির্ণয়; ফর্মুলা-নিরাপদ CSV), `PgVatRepo`, `AccountingVatController`। স্ক্রিন `/app/accounting/vat` (সেটিং, হার, সংরক্ষিত রিটার্ন) ও `/vat/books` (বই, রিটার্ন খসড়া, CSV, "NBR-এ জমা দিয়েছি" নথিভুক্তি)। রিটার্নের অঙ্ক সার্ভারে বই থেকে আবার হিসাব হয় — ব্রাউজারের অঙ্ক নেওয়া হয় না। ফাইলের পর হিসাব বদলালে সতর্কতা।
- টেস্ট: DB ৬২৬ (নতুন ২৯ — সেটিং, হারের ইতিহাস/ওভারল্যাপ, ফ্ল্যাগ, খসড়া/ফাইল, tenant বিচ্ছিন্নতা, সরাসরি-লেখা নিষেধ + PHP-SQL ১), pure PHP ২৩; ২৬ mutation — টিকে থাকা ৩টি সমতুল্য/ব্যাকস্টপ ইনডেক্স।
### Not yet
- 17b VDS/TDS/AIT; 17c ট্যাক্স ক্যালেন্ডার/রিমাইন্ডার; মূসক-৬.৩ চালান ছাপা; সম্পূরক শুল্ক; ট্রেডার/টার্নওভার ট্যাক্স আলাদা হিসাব; আগের সময়ের ক্রেডিট স্বয়ংক্রিয় টানা; হার থেকে ডকুমেন্টে মূসক স্বয়ংক্রিয় বসানো (এখন হাতে, `VatCalc` প্রস্তুত); অর্ডার-অটোপোস্টে মূসক।

## [0.32.0] — 2026-11-15 — Phase 16d (AI বিল-স্ক্যান)
### Added
- **ফ্লো:** বিলের ছবি/PDF আপলোড → ভিশন মডেল পড়ে **খসড়া** বানায় → মানুষ ছবির পাশে মিলিয়ে ঠিক করে নিশ্চিত করে → তবেই বিল/খরচ হিসেবে ওঠে। **AI নিজে কিছু পোস্ট করে না।**
- **AI ইঞ্জিন:** `POST /internal/v1/bills/scan` (signed)। ছবি ১৬০০px-এ ছোট করে JPEG-এ পুনঃএনকোড (EXIF/GPS বাদ), decompression-bomb রক্ষা (৪০MP); PDF-এ লেখা ≥৮০ অক্ষর থাকলে শুধু লেখা পাঠায়, নইলে ≤৩ পাতা ছবি করে; পাসওয়ার্ড/নষ্ট PDF ও ভুল ফাইল-টাইপ প্রত্যাখ্যান (magic bytes)। তিন প্রোভাইডার অ্যাডাপ্টারে (Anthropic, OpenAI-সামঞ্জস্যপূর্ণ, Gemini) ছবি-পার্ট। মডেলের উত্তর যাচাই: টাকার অঙ্ক (বাংলা অঙ্ক/৳/কমা), অ্যাকাউন্ট-কোড কেবল দেওয়া তালিকা থেকে, ফ্ল্যাগ (মোট না মেলা, বিদেশি মুদ্রা, তারিখ নেই, কম আত্মবিশ্বাস…)।
- **ডাটাবেস (`phase16d.up/down.sql`, মাইগ্রেশন `2026_11_13_110000_create_bill_scans`):** `bill_scans` (RLS ENABLE+FORCE, অ্যাপ রোল শুধু পড়ে) ও `bill_scan_start/ready/fail/discard/post`। একই ফাইল (sha256) আবার দিলে নতুন স্ক্যান/খরচ হয় না; একটি স্ক্যান থেকে সর্বোচ্চ একটি ডকুমেন্ট; ১০ মিনিট আটকে থাকা স্ক্যান বন্ধ হয়; ডকুমেন্ট `acct_doc_create`-এর সব নিয়মে (লক, অ্যাকাউন্ট, ব্যালান্স) বানানো, প্রত্যাখ্যাত হলে খসড়া থেকে যায়।
- **Laravel (`App\BillScan`):** `BillFile`, `BillDraft` (prefill/parse), `BillScanService` (ক্রেডিট reserve → সেটেল/রিলিজ; প্রতিটি reserve ঠিক একবার settle বা release), `PgBillScanRepo`, `ProviderGateway::scanBill`, `CatalogVisionPicker` (`vision` ক্ষমতার মডেল), `LocalBillFileStore` (প্রাইভেট ডিস্ক)। স্ক্রিন `/app/accounting/bills/scan` (আপলোড, ছবির পাশে খসড়া-ফর্ম, ফাইল শুধু লগইন+tenant-যাচাইয়ে)।
- টেস্ট: DB ৫৯৭ (নতুন ২৬ — hypothesis: ডকুমেন্ট = used স্ক্যান, জীবিত sha অনন্য, ট্রায়াল ব্যালান্স মেলে), engine ২০৭ (নতুন ৬০), pure PHP ৩২ (নতুন), PHP-SQL ২; ২৬ mutation পরীক্ষিত — টিকে থাকা সবই সমতুল্য/ডিফেন্স-ইন-ডেপথ।
### Not yet
- হাতের লেখা/কম-মানের ছবির নিশ্চয়তা, বহু-পাতার বিল ৩ পাতার বেশি, সরবরাহকারীর আগের বিল থেকে অ্যাকাউন্ট শেখা, ইমেইল/WhatsApp থেকে বিল গ্রহণ।

## [0.31.0] — 2026-11-14 — Phase 16c (অর্ডার/কুরিয়ার থেকে স্বয়ংক্রিয় পোস্টিং)
### Added
- **ডাটাবেস (`phase16c.up/down.sql`, মাইগ্রেশন `2026_11_12_110000_create_autopost`):** `autopost_settings`, `autopost_queue`, `courier_settlements` — RLS ENABLE+FORCE, অ্যাপ রোল শুধু পড়ে।
- **অর্ডার `delivered` হলে** trigger শুধু সারিতে একটি row যোগ করে (নিজের ত্রুটি গিলে ফেলে) — হিসাব ভাঙলেও অর্ডারের কাজ থামে না (টেস্টে ভাঙা সারি দিয়ে প্রমাণিত)। ডিফল্টে **বন্ধ**; মালিক চালু করার পর থেকে ডেলিভারি হওয়া অর্ডারই ওঠে।
- **`autopost_run`** (বোতাম + `accounting:autopost` প্রতি ১০ মিনিটে): প্রতিটি row আলাদা sub-transaction; ব্যর্থ হলে `failed` + কারণ ("পোস্ট বাকি" তালিকা), বাকিগুলো চলে; আবার চালালে পুনরায় চেষ্টা; `autopost_skip` দিয়ে বাদ।
- **বিক্রি:** Dr কুরিয়ার-পাওনা (কুরিয়ার বুকিং থাকলে) বা নগদ (নিজের ডেলিভারি) / Cr বিক্রি (ছাড়ের পর) + ডেলিভারি আয়; ইউনিট-খরচ জানা থাকলে Dr বিক্রীত পণ্যের ব্যয় / Cr মজুত (খরচ নেই এমন আইটেম নোটে চিহ্নিত)। **ফেরত (delivered→returned):** এন্ট্রি একবার উল্টে যায়; পোস্টের আগেই ফেরত হলে দুটোই বাদ।
- **কুরিয়ার সেটেলমেন্ট** (`courier_settle`/`courier_settle_void`): Dr ব্যাংক/নগদ (মোট−কাটা) + Dr কুরিয়ার খরচ / Cr কুরিয়ার-পাওনা; পাওনার বেশি বা ফেরত-আসা পার্সেলের টাকা নেওয়া যায় না; বাতিল = উল্টো এন্ট্রি। `courier_receivable_report` কুরিয়ারভিত্তিক বাকি।
- **স্ক্রিন** `/app/accounting/autopost`: চালু/বন্ধ, পোস্ট বাকি তালিকা, কুরিয়ারের পাওনা, টাকা-পাওয়া ফর্ম ও ইতিহাস।
- টেস্ট: DB ৫৭১ (নতুন ৩০ + PHP-SQL ৩ + hypothesis: খাতার কুরিয়ার-পাওনা সর্বদা রিপোর্টের সমান, বিক্রি = ডেলিভারি-থাকা অর্ডার, ট্রায়াল ব্যালান্স মেলে), AccText ৭; ৩৯+ mutation পরীক্ষিত — টিকে থাকা ২টি সমতুল্য/একযোগ-লক (`SKIP LOCKED`, courier শুধু posted-এ বসে)।
### Not yet
- ডেলিভারির আগে/অগ্রিম পেমেন্ট, VAT, কুরিয়ার API থেকে সেটেলমেন্ট আমদানি (এখন হাতে), ওয়েটেড-অ্যাভারেজ খরচ, চালুর আগের অর্ডারের ব্যাকফিল, AI বিল-স্ক্যান (16d)।

## [0.30.0] — 2026-11-13 — Phase 16b (স্থায়ী সম্পদ ও অবচয়, ইনভেন্টরি মূল্যায়ন)
### Added
- **ডাটাবেস (`phase16b.up/down.sql`, মাইগ্রেশন `2026_11_11_110000_create_assets_inventory`):** `fixed_assets`, `fa_dep_lines` (immutable), `inv_costs` (immutable ইতিহাস) — RLS ENABLE+FORCE, অ্যাপ রোল শুধু পড়ে।
- **অবচয় (সরলরৈখিক, মাসভিত্তিক):** কেনার মাস থেকে পুরো মাস; মাসের পরিমাণ `floor(base·(k+1)/life) − floor(base·k/life)` — **মোট ঠিক খরচ−অবশিষ্টমূল্য, গোলাকরণে এক পয়সাও হারায় না**। `fa_register` (শুধু স্থায়ী-সম্পদ গোষ্ঠীর অ্যাকাউন্ট; নগদ/প্রাপ্য/মজুত ইত্যাদি নয়), `fa_pending(asof)`, `fa_run(month)` — মাসে একটি জার্নাল এন্ট্রি (Dr অবচয় ব্যয় / Cr পুঞ্জীভূত অবচয়), মাসের শেষ দিনে (চলতি মাস হলে আজ), যেকোনো ক্রমে চালানো যায়, একই মাস দুবার নয়, দেরিতে নিবন্ধিত সম্পদের ঝুলে-থাকা মাস ধরা পড়ে; লক-করা সময়ে কিছুই তৈরি হয় না।
- **বিক্রি/বাতিল (`fa_dispose`):** আগের সব মাসের অবচয় দেওয়া থাকতে হবে (বিক্রির মাসে অবচয় নেই); Cr সম্পদ, Dr পুঞ্জীভূত অবচয়, Dr নগদ/ব্যাংক (পাওয়া টাকা), পার্থক্য লাভ → "অন্যান্য আয়" / ক্ষতি → "বিবিধ ব্যয়"।
- **মজুত মূল্যায়ন:** `inv_cost_set` (প্রতি ভ্যারিয়েন্টের একক খরচ, তারিখসহ ইতিহাস), `inv_valuation(asof)` = সেই দিনের শেষে (ঢাকা সময়) স্টক-পরিমাণ × সেই দিনের খরচ; খরচ না থাকলে ০ ধরে চিহ্নিত। `inv_revalue` — খাতার "মজুত পণ্য" অ্যাকাউন্ট ঐ তারিখের স্টক-মূল্যে মিলিয়ে দেয়, পার্থক্য বাছা আয়/ব্যয় অ্যাকাউন্টে (সাধারণত COGS); অন্য ওয়ার্কস্পেসের স্টক কখনও গোনা হয় না।
- **স্ক্রিন:** `/app/accounting/assets` (তালিকা, বাকি অবচয়ের বাটন, নিবন্ধন, সম্পদের পাতায় মাসের ছক ও বিক্রি/বাতিল), `/app/accounting/inventory` (মূল্যায়ন, খাতার সাথে পার্থক্য, খরচ বসানো, "খাতা মিলিয়ে দিন")।
- টেস্ট: DB ৫২৭ (নতুন ১৯ + PHP-SQL ৩ + hypothesis: যেকোনো নিবন্ধন/অবচয়/বিক্রির ক্রমে সম্পদ-অ্যাকাউন্ট = চালু সম্পদের খরচ, পুঞ্জীভূত = চালু সম্পদের অবচয়, ট্রায়াল ব্যালান্স মেলে), pure PHP AccText ৬; ৩৬+ mutation পরীক্ষিত (একটি সমতুল্য ছাড়া সব ধরা)।
### Not yet
- সরলরৈখিক ছাড়া অন্য পদ্ধতি (reducing-balance, আয়কর অবচয়-হার); অবচয় বাতিল/উল্টানো (ভুল হলে হাতে সমন্বয়-এন্ট্রি); কেনার এন্ট্রি স্বয়ংক্রিয় নয়; ইনভেন্টরিতে FIFO/ওয়েটেড-অ্যাভারেজ ও ক্রয়-রসিদ থেকে খরচ (এখন খরচ হাতে বসাতে হয়); লট/ব্যাচ; auto-posting (16c), AI বিল-স্ক্যান (16d)।

## [0.29.0] — 2026-11-12 — Phase 16a (ব্যাংক / bKash / Nagad স্টেটমেন্ট ইমপোর্ট ও রিকনসিলিয়েশন)
### Added
- **ডাটাবেস (`phase16a.up/down.sql`, মাইগ্রেশন `2026_11_11_100000_create_bank_reconciliation`):** `bank_imports`, `bank_lines` (RLS ENABLE+FORCE; অ্যাপ রোল শুধু পড়ে, লেখে ফাংশনে)। শুধু `cash_account` অ্যাকাউন্টে স্টেটমেন্ট।
- **`bank_import`:** ১–৫০০০ লাইন, এক লাইন ভুল হলে পুরো ফাইল বাতিল (কোন লাইন বলে দেয়)। **একই ফাইল আবার দিলে ডুপ্লিকেট হয় না** (তারিখ+টাকা+বিবরণ+রেফারেন্স+"ফাইলে কত নম্বর একই লাইন" দিয়ে কী); সত্যিকারের একই-রকম দুটি লেনদেন দুটোই থাকে।
- **মেলানো:** `bank_match` (একই অ্যাকাউন্ট, একই টাকা ও দিক, একটি জার্নাল লাইন একবারই), `bank_unmatch`, `bank_ignore`, `bank_auto_match` (একই দিন আগে, তারপর ১, ২, ৩ দিনের ফারাক — কাছেরটা দূরেরটা কেড়ে নেয় না), `bank_suggestions`।
- **খাতায় নেই এমন লাইন:** `bank_create_entry` — বিপরীত অ্যাকাউন্ট বেছে `journal_post`-এর মধ্য দিয়ে এন্ট্রি (তারিখ = স্টেটমেন্টের দিন, source `system`, ref `bank:<id>`), লক করা সময়ে হলে কিছুই হয় না।
- **`bank_reconciliation`:** খাতার ব্যালান্স, স্টেটমেন্টের শেষ ব্যালান্স, মেলেনি এমন লাইন (দুই দিকেই), বাদ-দেওয়া; খাতার পার্থক্য = "খাতায় আছে, স্টেটমেন্টে আসেনি" (যেমন ক্লিয়ার না হওয়া চেক)।
- **CSV পার্সার (`BankCsv`):** হেডারের নাম (ইংরেজি/বাংলা) থেকে কলাম চেনে, ডেবিট/ক্রেডিট আলাদা বা এক সাইনড কলাম, BOM, `;`/ট্যাব, দিন-আগে তারিখ (`03/11/2026`, `03-Nov-2026`), বাংলা অঙ্ক, `(500)`/`500 Dr`, উল্টো-চিহ্নের সুইচ; কলাম নিজে বাছা যায়; লাইন-ধরে ভুলের তালিকা।
- **স্ক্রিন `/app/accounting/bank`:** অ্যাকাউন্ট তালিকা, মেলানোর পাতা (সারসংক্ষেপ, সম্ভাব্য মিল, "এটাই", নতুন এন্ট্রি, বাদ), আপলোড → প্রিভিউ (কলাম বদল) → ইমপোর্ট।
- টেস্ট: DB ৫০৫ (নতুন ১০ + PHP-SQL ১ + hypothesis: যেকোনো খাতা/স্টেটমেন্ট মিশ্রণে প্রতিটি লাইন ঠিক একবার মেলে, টাকা সমান, রিকনসিলিয়েশন শূন্য), pure PHP BankCsv ৫ + AccText; ৪০+ mutation পরীক্ষিত। টেস্ট একটি আসল বাগ ধরেছে (plpgsql-এ `PERFORM` `FOUND` বদলে দেয় — অচেনা অ্যাকাউন্ট গ্রহণ হচ্ছিল), ঠিক করা।
### Not yet
- **আসল ব্যাংক/bKash/Nagad ফাইল দিয়ে পরীক্ষা হয়নি** (ফরম্যাট আমার জানা নেই — তাই প্রিভিউ ও কলাম-বাছাই); PDF/Excel(.xlsx) স্টেটমেন্ট (আগে CSV করুন); API/লাইভ ফিড; এক স্টেটমেন্ট লাইন = একটি জার্নাল লাইন (ভাগ/জোড়া মেলানো নেই); স্থায়ী সম্পদ/ইনভেন্টরি (16b), auto-posting (16c), AI বিল-স্ক্যান (16d)।

## [0.28.0] — 2026-11-11 — Phase 15c (লাভ-ক্ষতি, ব্যালান্স শিট, ক্যাশ ফ্লো) — Phase 15 সম্পূর্ণ
### Added
- **ডাটাবেস (`phase15c.up/down.sql`, মাইগ্রেশন `2026_11_10_120000_create_accounting_reports`):** শুধু পড়ার তিনটি invoker ফাংশন (RLS — এক ওয়ার্কস্পেস): `acct_profit_loss(from, to)`, `acct_balance_sheet(asof)`, `acct_cash_flow(from, to)`। নতুন টেবিল নেই, লেখা নেই।
- **লাভ-ক্ষতি:** আয় / বিক্রীত পণ্যের ব্যয় (৫xxx) / পরিচালন ব্যয় / অ-পরিচালন (৭xxx) → মোট মুনাফা, পরিচালন মুনাফা, নিট মুনাফা/ক্ষতি; তারিখ দুই প্রান্তসহ; বাতিল এন্ট্রি ও রিভার্সাল একসাথে শূন্য।
- **ব্যালান্স শিট:** সম্পদ / দায় / মালিকানা স্বত্ব + "এখন পর্যন্ত মুনাফা" (বছর-শেষ বন্ধ করা ছাড়াই হিসাব); সবসময় সম্পদ = দায় + স্বত্ব, স্ক্রিনে ✔/✘।
- **ক্যাশ ফ্লো (সরাসরি পদ্ধতি):** নগদ-নড়া প্রতিটি এন্ট্রি তার **নগদ-বহির্ভূত লাইন** ধরে ভাগ হয় (ঠিক যোগফল, ভাগের ভগ্নাংশ নেই); ব্যবসা পরিচালনা / বিনিয়োগ (সম্পদ ≥১৬০০) / অর্থায়ন (মালিকানা স্বত্ব, দায় ≥২৬০০); নগদ↔ব্যাংক স্থানান্তর বাদ; শুরুর + প্রবাহ = শেষের নগদ সবসময়।
- **স্ক্রিন:** `/app/accounting/reports/{profit-loss,balance-sheet,cash-flow}`; মাস / গত মাস / অর্থবছর (জুলাই–জুন) / গত অর্থবছর / নিজের তারিখ; প্রিন্ট বাটন।
- টেস্ট: DB ৪৯৪ (নতুন ৭ + PHP-SQL ১ + **hypothesis**: যেকোনো এন্ট্রি-মিশ্রণে সম্পদ=দায়+স্বত্ব+মুনাফা, সর্বকালের লাভ-ক্ষতি = ব্যালান্স শিটের মুনাফা, ক্যাশ ফ্লো = ক্যাশ বুক), pure PHP AccReport ৫; ২৫+ mutation পরীক্ষিত — **টেস্টই একটি আসল বাগ ধরেছে** (ব্যালান্স শিটে খরচ উল্টো যোগ হচ্ছিল), ঠিক করা।
### Not yet
- বছর-শেষ ক্লোজিং এন্ট্রি; ইনডাইরেক্ট ক্যাশ ফ্লো; তুলনামূলক (আগের মাস/বছরের পাশাপাশি) কলাম; PDF/Excel এক্সপোর্ট (প্রিন্ট আছে); অ্যাকাউন্টের হিসাবভুক্তি তারিখ নয়, নগদ-চিহ্ন বর্তমান অবস্থা ধরে।

## [0.27.0] — 2026-11-11 — Phase 15b (ইনভয়েস, বিল, খরচ, পেমেন্ট, পাওনা/দেনা বয়স-রিপোর্ট, ক্যাশ-ব্যাংক বুক)
### Added
- **ডাটাবেস (`phase15b.up/down.sql`, মাইগ্রেশন `2026_11_10_110000_create_accounting_docs.php`):** `acct_parties` (গ্রাহক/সরবরাহকারী), `acct_docs` (invoice|bill|expense; INV-/BILL-/EXP- ক্রমিক নম্বর), `acct_doc_lines` (immutable), `acct_payments`; `accounts.cash_account` ফ্ল্যাগ (নগদ/ব্যাংক/মোবাইল-ওয়ালেট স্বয়ংক্রিয়, নিজের অ্যাকাউন্ট চিহ্নিত করা যায়)। অ্যাপ রোল সরাসরি লিখতে পারে না — শুধু ফাংশনে।
- **পোস্টিং (সব `journal_post`-এর মধ্য দিয়ে, একই লেনদেনে):** ইনভয়েস Dr প্রাপ্য / Cr আয় (+আউটপুট ভ্যাট); বিল Dr খরচ|সম্পদ (+ইনপুট ভ্যাট) / Cr দেনা; খরচ Dr খরচ / Cr নগদ-ব্যাংক; পেমেন্ট Dr নগদ / Cr প্রাপ্য (ইনভয়েস) বা Dr দেনা / Cr নগদ (বিল)। জার্নাল প্রত্যাখ্যান করলে (লক করা সময় ইত্যাদি) ডকুমেন্ট ও নম্বর কিছুই থাকে না।
- **নিয়ম:** আংশিক পেমেন্ট, অতিরিক্ত পেমেন্ট নিষেধ, পেমেন্ট বাতিল (রিভার্সাল এন্ট্রি), পেমেন্ট থাকলে ডকুমেন্ট বাতিল নিষেধ, ভ্যাট হাতে দেওয়া (হার ও স্বয়ংক্রিয় হিসাব Phase 17)।
- **রিড:** `acct_aging(kind, asof)` (current / ১–৩০ / ৩১–৬০ / ৬১–৯০ / ৯০+, বাতিল ও পরে-পরিশোধিত বিবেচনায়), `acct_cash_balances(asof)`।
- **স্ক্রিন:** গ্রাহক/সরবরাহকারী, ইনভয়েস/বিল/খরচ তালিকা ও নতুন ফর্ম, ডকুমেন্ট দেখা + টাকা পেয়েছি/দিলাম + বাতিল, পাওনা/দেনা বয়স-রিপোর্ট, ক্যাশ-ব্যাংক বুক।
- টেস্ট: DB ৪৮৬ (নতুন ১৩ + PHP-SQL ১ + hypothesis property "প্রাপ্য/দেনা = খোলা ডকুমেন্টের যোগফল"), pure PHP AccText ৪; ২০+ mutation পরীক্ষিত (ঝুলে থাকা ৪টি দুর্বলতা পেয়ে টেস্ট শক্ত করা)।
### Not yet
- P&L, ব্যালান্স শিট, ক্যাশ ফ্লো (15c); ভ্যাট হার/মুসক ফাইলিং (17); অর্ডার/কুরিয়ার থেকে অটো-পোস্টিং ও OCR (16); PDF ইনভয়েস/প্রিন্ট নেই।

## [0.26.0] — 2026-11-10 — Phase 15a (হিসাবের কোর: চার্ট অফ অ্যাকাউন্টস, জার্নাল, লেজার, ট্রায়াল ব্যালান্স, period lock)
### Added
- **ডাটাবেস (`phase15.up/down.sql`, মাইগ্রেশন `2026_11_10_100000_create_accounting.php`):** `accounts`, `journal_entries`, `journal_lines`, `acct_settings`, `acct_lock_log` — সব RLS ENABLE+FORCE; অ্যাপ রোল এন্ট্রি/লাইন/লক সরাসরি লিখতে পারে না (শুধু ফাংশনে)।
- **ডাবল-এন্ট্রি নিয়ম ডাটাবেসে:** `journal_post` — কমপক্ষে ২ লাইন, প্রতি লাইনে ডেবিট **অথবা** ক্রেডিট (>০, সর্বোচ্চ সীমা), **মোট ডেবিট = মোট ক্রেডিট**, অ্যাকাউন্ট একই ওয়ার্কস্পেসের + চালু + পোস্টযোগ্য (শ্রেণি-শিরোনামে নয়), তারিখ ২০০০–আগামীকাল, বিবরণ বাধ্যতামূলক; ক্রমিক নম্বর (ব্যর্থ চেষ্টায় নম্বর খরচ হয় না)। **দ্বিতীয় প্রতিরক্ষা:** deferred constraint trigger — ফাংশন এড়িয়ে (টেবিল-মালিক দিয়েও) অসমান এন্ট্রি কমিটই হয় না।
- **append-only:** এন্ট্রি/লাইন/লক-লগ UPDATE/DELETE ট্রিগারে বন্ধ; ভুল শোধরাতে `journal_reverse` — ডেবিট-ক্রেডিট উল্টে নতুন এন্ট্রি, একবারই; বাতিল-এন্ট্রি আবার বাতিল হয় না।
- **period lock:** `acct_lock` শুধু সামনের দিকে, আজকের পরে নয়; লকের তারিখ বা আগের কোনো এন্ট্রি/রিভার্সাল নয় (লকে আটকা ভুল খোলা সময়ে রিভার্সাল দিয়ে শোধরানো যায়); `acct_unlock` শুধু **পিছনে**, কারণ (≥৫ অক্ষর) বাধ্যতামূলক, লগে।
- **BD চার্ট অফ অ্যাকাউন্টস টেমপ্লেট (`acct_seed`, ৫১ অ্যাকাউন্ট, বাংলা নামসহ):** নগদ/ব্যাংক/বিকাশ-নগদ, প্রাপ্য, কুরিয়ারের কাছে COD পাওনা, মজুত, ইনপুট/আউটপুট ভ্যাট, TDS/AIT, বেতন, মূলধন, বিক্রয়, COGS, বিজ্ঞাপন, কুরিয়ার খরচ, ব্যাংক চার্জ, অবচয় ইত্যাদি; স্থিতিশীল `system_key` (পরের ধাপগুলো এগুলো ধরে অটো-পোস্ট করবে)। idempotent; নিজের অ্যাকাউন্ট যোগ (`acct_account_add`), নাম/চালু-বন্ধ।
- **রিড:** `acct_trial_balance(asof)` (শুধু শূন্য-নয় ব্যালান্স), `acct_ledger(account, from, to)` (শুরুর ব্যালান্স + চলতি ব্যালান্স)।
- **স্ক্রিন `/app/accounting`:** জার্নাল, নতুন এন্ট্রি (৬ লাইন, বাংলা অঙ্ক/কমা চলে), এন্ট্রি দেখা + রিভার্স, অ্যাকাউন্ট চার্ট, লেজার, ট্রায়াল ব্যালান্স (✔ মিলেছে/✘), লক-আনলক ও ইতিহাস। নতুন অনুমতি `accounting.view|manage|settings` (Admin/Owner সব; Manager ও Auditor দেখতে) — `roles:sync` চালান।
- টেস্ট: DB ৪৭২ (নতুন ১১ + PHP-SQL ১ + রাউন্ডট্রিপ), **hypothesis property-based** ৬০ উদাহরণ × ৮টি এন্ট্রি (ভালো/মন্দ/রিভার্সাল মিশিয়ে: প্রতি ধাপে মোট ডেবিট=ক্রেডিট, ব্যর্থ এন্ট্রি কিছু লেখে না, অ্যাকাউন্টপ্রতি নেট ঠিক), pure PHP নতুন ৩; ১৫+ mutation ধরা পড়েছে (একটি দুর্বলতা পেয়ে টেস্ট শক্ত করা হয়েছে)।
### Not yet
- ইনভয়েস/বিল/খরচ/পেমেন্ট, AR/AP, ক্যাশ-ব্যাংক বুক (15b); P&L, ব্যালান্স শিট, ক্যাশ ফ্লো (15c); অর্ডার/কুরিয়ার থেকে অটো-পোস্টিং ও OCR (Phase 16); শুধু BDT; আর্থিক বছর সেটিংস/বছর-শেষ ক্লোজিং এন্ট্রি নেই (লক দিয়ে চলে)।

## [0.25.0] — 2026-11-09 — Phase 14c (কুরিয়ার: Steadfast / Pathao / RedX + দৈনিক রিপোর্ট) — Phase 14 সম্পূর্ণ
### Added
- **কুরিয়ার অ্যাকাউন্ট (`courier_accounts`):** প্রতি ওয়ার্কস্পেসে কুরিয়ারপ্রতি একটি; কী-গুলো `CredentialVault` (AES-256-GCM) দিয়ে সিল, অ্যাপ রোল `credentials_ct` **SELECT করতে পারে না** — শুধু `courier_credentials()` (ওয়ার্কস্পেস প্রমাণের পর)। কনফিগে শুধু `sandbox`/`store_id`/`pickup_store_id` (সংখ্যা যাচাই); **কোনো ইউজার-দেওয়া URL নেই** (SSRF নেই)। স্ক্রিন `/app/orders/couriers` (নতুন অনুমতি `couriers.manage` — Owner/Admin; `roles:sync` চালান)।
- **তিন ধাপের বুকিং (দুইবার বুক অসম্ভব):** `shipment_reserve` (শুধু নিশ্চিত/প্যাক অর্ডার; অর্ডারপ্রতি একটি চলমান পার্সেল — আংশিক ইউনিক ইনডেক্স; **COD = অর্ডারের মোট, DB থেকে**; জরুরি স্টপে বন্ধ) → কুরিয়ার API → `shipment_booked` (অর্ডার packed হয়ে shipped) বা `shipment_fail`। কুরিয়ার স্পষ্ট প্রত্যাখ্যান (4xx) করলে অর্ডার মুক্ত; **টাইমআউট/5xx হলে বুকিং আটকে থাকে** (পার্সেল তৈরি হয়ে থাকতে পারে) — ১০ মিনিট পর কর্মী কুরিয়ারের প্যানেল দেখে "মুক্ত করুন" চাপে।
- **স্ট্যাটাস সিঙ্ক (`couriers:sync`, প্রতি ১৫ মিনিট):** কুরিয়ারের শব্দ → `CourierStatus::map` → `shipment_apply`: পৌঁছেছে → অর্ডার delivered; ফেরত/বাতিল → অর্ডার returned (স্টক ঠিক একবার ফেরত, `order_transition`-এর মধ্য দিয়ে); অজানা/আংশিক/হোল্ড → "নজর দরকার" (অনুমান নয়)। শেষ হওয়া পার্সেল আর নড়ে না; কেউ হাতে অর্ডার বদলে ফেললে জোর করে নয় — লগে "order did not follow"। টানা ২০ বার চেক ব্যর্থ হলে "নজর দরকার"। কুরিয়ারে ফেরত/বাতিল ও পৌঁছানো আগের ফেক-অর্ডার স্কোরে নিজে থেকেই যুক্ত (ফোনের ইতিহাস)।
- **অ্যাডাপ্টার (`app/Couriers/*`):** Steadfast (Api-Key/Secret-Key, `create_order`, `status_by_cid`), Pathao (OAuth `issue-token` → `orders`, `orders/{id}/info`), RedX (`API-ACCESS-TOKEN`, `/parcel`, `/parcel/track`; ডেলিভারি-এলাকা ঠিকানা থেকে মিলিয়ে বা কর্মীর দেওয়া area id)। কী কখনো ত্রুটি-বার্তায় যায় না (scrub)।
- **দৈনিক রিপোর্ট:** `order_report_calc` (Asia/Dhaka দিন; নতুন/নিশ্চিত/পাঠানো/পৌঁছেছে/বাতিল/ফেরত, চলমান, কুরিয়ারের হাতে COD, নজর-দরকার, ৩০ দিনে কুরিয়ারের সফলতা), `orders:daily-report` রাত ১২:১০ (ঢাকা), `order_reports`-এ সংরক্ষিত; স্ক্রিন `/app/orders/report` (আজকের লাইভ + শেষ ১৪ দিন, বাংলায়)।
- স্ক্রিন: অর্ডার পাতায় "কুরিয়ার" বক্স (বুক, এখনই স্ট্যাটাস দেখুন, মুক্ত করুন), `/app/orders/shipments`।
- ডাটাবেস: `phase14c.up/down.sql` (মাইগ্রেশন `2026_11_09_120000_create_couriers.php`); নতুন `CourierServiceProvider` (`bootstrap-web.sh`-এ যোগ)।
- টেস্ট: DB ৪৬১ (নতুন ২০ — ১৬ নিয়ম + ৪ PHP-SQL), pure PHP নতুন ২২ (অ্যাডাপ্টার ১৪, সার্ভিস/রিপোর্ট ৮), রাউন্ডট্রিপ বাড়ানো; SQL ৭ + PHP ৮ + SQL-কোয়েরি ৪টি mutation ধরা পড়েছে (দুটি দুর্বল টেস্ট শক্ত করা হয়েছে)।
### Fixed (টেস্টে ধরা)
- বাংলা অঙ্ক রূপান্তরে `strtr(from, to)` বাইট-ভিত্তিক বলে অঙ্ক ভেঙে যাচ্ছিল → array-form।
### Not yet — **গুরুত্বপূর্ণ**
- **তিন কুরিয়ারের API আসল অ্যাকাউন্টে চালানো হয়নি।** অফিসিয়াল নথি এই পরিবেশ থেকে পড়া যায়নি; এন্ডপয়েন্ট/ফিল্ড/স্ট্যাটাস-শব্দ প্রচলিত গড়ন ও থার্ড-পার্টি লাইব্রেরি থেকে লেখা — আপনার সেন্ডবক্স/আসল কী দিয়ে একটি ছোট অর্ডারে আগে মিলিয়ে নিন (TESTING_GUIDE)।
- Pathao-র city/zone/area পাঠানো হয় না (Pathao ঠিকানা থেকে ধরে বলে ধরা হয়েছে — অযাচাইকৃত); লেবেল/ইনভয়েস প্রিন্ট, কুরিয়ার ওয়েবহুক (এখন পোলিং), কুরিয়ার থেকে COD-টাকা মিলানো, রিপোর্ট ইমেইল/টেলিগ্রামে পাঠানো নেই।

## [0.24.0] — 2026-11-09 — Phase 14b (চ্যাটে অর্ডার — Sales Agent)
### Added
- **চ্যাটে অর্ডার নেওয়া (`app/Inbox/Agent/Orders/*`):** গ্রাহক "অর্ডার করব" বললে AI শুধু লেখা থেকে **পণ্য/সংখ্যা/নাম/ফোন/ঠিকানা বের করে** (JSON); প্রশ্ন, দাম, সারাংশ, মোট, নিশ্চিতকরণ — সব **কোড** বানায়/যাচাই করে (মডেল কোনো দাম বা "নিশ্চিত" লেখে না)। অনুপস্থিত তথ্য এক বার্তায় চাওয়া হয়; একাধিক ভ্যারিয়েন্ট (সাইজ/রং) থাকলে কোনটি নেবেন জিজ্ঞেস করে; স্টকে না থাকলে জানায়; বিক্রিযোগ্য নয় এমন ভ্যারিয়েন্ট-আইডি (মডেলের বানানো) উপেক্ষিত; সংখ্যা সর্বোচ্চ ১০।
- **নিশ্চিতকরণ নিয়ম:** সারাংশ দেখানোর পর গ্রাহকের **সাদামাটা "হ্যাঁ"** (`Affirm` — না/কিন্তু/বদলান/সংখ্যা থাকলে হ্যাঁ নয়) এবং সারাংশের **ফিঙ্গারপ্রিন্ট অপরিবর্তিত** থাকলেই অর্ডার তৈরি; মাঝে দাম/স্টক/তথ্য বদলালে নতুন সারাংশ। "হ্যাঁ" ধাপে মডেল ডাকাই হয় না।
- **অর্ডার তৈরি = 14a-র `order_create`** (`source='agent'`, ছাড় **০%**, ফোন/স্টক/দাম/ঝুঁকি-স্কোর DB-তে)। সফল হলে গ্রাহককে অর্ডার নম্বর ও মোট (COD) জানানো হয় এবং চ্যাট **মানুষের হাতে** যায় (কারণ "AI অর্ডার নিয়েছে — ফোনে নিশ্চিত করুন")। ব্যর্থতায়: ফোন/ঠিকানা/নাম ভুল হলে আবার চায়; স্টক নেই/অন্য ব্যর্থতায় মানুষ।
- **আপসেল:** সারাংশের সাথে একবার, একই ক্যাটাগরির একটি স্টকে-থাকা পণ্য (দামসহ) — ছাড় নয়, জোর নয়।
- **ড্রাফট সংরক্ষণ (`inbox_order_drafts`, RLS):** রিস্টার্ট/দ্বিতীয় ওয়ার্কার নিরাপদ; ২৪ ঘণ্টা পর বাসি; ১২ বার্তার বেশি লাগলে মানুষ। চলমান অর্ডারে অভিযোগ/"মানুষ চাই" এলে ড্রাফট মুছে হস্তান্তর।
- **মালিকের সুইচ:** `/app/orders/settings`-এ "চ্যাটে AI অর্ডার নিতে পারবে" (ডিফল্ট **বন্ধ**)। কাজ করে শুধু **অটো মোডের** চ্যানেলে; বন্ধ বা খসড়া মোডে অর্ডারের কথা উঠলে আগের মতোই মানুষ।
- **ক্রেডিট:** `ModelCall` — reserve → ব্যবহারযোগ্য JSON হলে settle, নইলে release; ক্রেডিট/মডেল না থাকলে হস্তান্তর।
- ডাটাবেস: `phase14b.up/down.sql` (মাইগ্রেশন `2026_11_09_110000_create_order_chat.php`): `order_settings.chat_orders`, `inbox_order_drafts`।
- টেস্ট: DB ৪৪১ (নতুন ৫ + `PgAgentRepo::byVariants/related`/ড্রাফট-রিপোর SQL আসল PG-তে), pure PHP নতুন ১৭ (`OrderFlowTest`), Pest নতুন ১ (এখানে চালানো যায়নি), ১৫টি mutation ধরা পড়েছে (দুটি দুর্বলতা পেয়ে টেস্ট শক্ত করা হয়েছে)।
### Not yet
- গ্রাহকের ভাষা সত্ত্বেও অর্ডারের বার্তা **বাংলা হরফে** (Banglish/ইংরেজিতে লিখলেও); **আসল LLM-এর তথ্য-বের-করার মান** অযাচাইকৃত; কুরিয়ার/ট্র্যাকিং/দৈনিক রিপোর্ট (14c); সাজেশন-ইঞ্জিন (বর্তমানে শুধু একটি আপসেল)।

## [0.23.0] — 2026-11-09 — Phase 14a (অর্ডার কোর: COD, স্টক হোল্ড, ছাড়-সীমা, ফেক-অর্ডার স্কোর)
### Added
- **ডাটাবেস (`phase14.up/down.sql`, মাইগ্রেশন `2026_11_09_100000_create_orders.php`):** `store_orders` (নাম `store_orders` — billing-এর `orders` আলাদা), `store_order_items` (দাম/শিরোনাম স্ন্যাপশট), `store_order_events` (append-only লগ), `order_settings`। সব RLS ENABLE+FORCE; অ্যাপ রোল সরাসরি অর্ডার/আইটেম/লগে লিখতে পারে না — শুধু ফাংশনে।
- **`order_create` — নিয়ম ডাটাবেসে:** (১) **দাম কখনোই কলারের কাছ থেকে নয়** — প্রকাশিত পণ্যের অনুমোদিত `live` কপি থেকে (খসড়া সম্পাদনায় দাম বদলালেও গ্রাহক পুরনো অনুমোদিত দামই দেয়; ভ্যারিয়েন্ট live কপিতে না থাকলে বিক্রি হয় না); (২) **স্টক সাথে সাথে আটকায়** (লেজারে `sale`), কম পড়লে পুরো অর্ডার বাতিল ও আগের লাইনের হোল্ড ফেরত (একই সাব-ট্রানজ্যাকশন); (৩) **AI এজেন্টের ছাড় ≤ মালিকের সীমা** (ডিফল্ট ০%; কর্মী বেশি দিতে পারে); (৪) নম্বর যাচাই (বাংলা অঙ্কসহ → `+8801…`), নাম/ঠিকানা/এলাকা যাচাই; (৫) একই নম্বরে ৩টির বেশি চলমান অর্ডার নয়; (৬) জরুরি স্টপে নতুন অর্ডার নয়; (৭) ডেলিভারি চার্জ সেটিংস থেকে; (৮) অর্ডার নম্বর ওয়ার্কস্পেসপ্রতি ক্রমিক।
- **ফেক-অর্ডার স্কোর (`order_risk_calc`, শুধু নিয়ম, AI নয়):** আগে বাতিল/ফেরত (+২০ প্রতিটি, সর্বোচ্চ ৫০), ২৪ ঘণ্টায় আরেকটি চলমান (+২০), সন্দেহজনক নম্বর (একই অঙ্ক ৭+ বার, +২৫), ছোট ঠিকানা (+১০), সংখ্যা ≥১০ (+১৫), মূল্য ≥৫০,০০০ (+১০), আগে সফল ডেলিভারি (−১৫)। সীমা (ডিফল্ট ৫০) ছুঁলে "যাচাই দরকার"।
- **`order_transition`:** নির্দিষ্ট পথ pending→confirmed→packed→shipped→delivered (+ cancelled/returned); বাতিল/ফেরতে স্টক ঠিক একবার ফেরত; জরুরি স্টপে নিশ্চিত করা বন্ধ (বাতিল সবসময় চলে); প্রতিটি ধাপ লগে।
- **স্ক্রিন:** `/app/orders` (ফিল্টার, ঝুঁকি ব্যাজ), নতুন অর্ডার (পণ্য-তালিকা live কপি থেকে), অর্ডার বিস্তারিত (ঝুঁকির কারণ বাংলায়, বাটন শুধু বৈধ ধাপ), সেটিংস। নতুন অনুমতি `orders.view|manage|settings` (সেটিংস শুধু Owner/Admin; Manager অর্ডার করতে/বদলাতে পারে; Member/Auditor দেখতে) — `php artisan roles:sync` চালান।
- টেস্ট: DB ৪৩৬ (নতুন ১৬ + রাউন্ডট্রিপ), pure PHP নতুন ৫ (`OrderTextTest`), Pest নতুন ৩ (এখানে চালানো যায়নি), ৯টি mutation ধরা পড়েছে।
### Changed
- 13c এজেন্টের পণ্য-খোঁজা এখন "live কপি আছে" ধরে (সম্পাদনার জন্য খসড়ায় ফেরা অনুমোদিত পণ্য আর উধাও হয় না)।
### Not yet
- চ্যাটে অর্ডার নেওয়া (14b), কুরিয়ার/ট্র্যাকিং ও দৈনিক রিপোর্ট (14c), প্রিপেইড/অনলাইন পেমেন্ট, ফোনের অ্যাপে অর্ডার।

## [0.22.0] — 2026-11-08 — Phase 13c (AI সাপোর্ট এজেন্ট)
### Added
- **চ্যানেলপ্রতি মোড `agent_mode`: বন্ধ (ডিফল্ট) | খসড়া | অটো।** খসড়া মোডে AI উত্তর লিখে রাখে, কর্মী দেখে-বদলে পাঠান (ইনবক্সে বেগুনি ব্যানার + টেক্সটবক্সে বসানো)। অটো মোডে AI নিজে পাঠায়। **ডাটাবেস নিজেই** অটো ছাড়া `agent` বার্তা ফেরায় (`agent_off`) এবং একটি কথোপকথনে ঘণ্টায় ১২টির বেশি AI-উত্তর ফেরায় (`agent_limit`)।
- **উত্তর শুধু প্রকাশিত পণ্য + মালিকের "ব্যবসার তথ্য" থেকে** (`Knowledge`): দাম/সেল-দাম/ভ্যারিয়েন্ট-স্টক `live` কপি ও আসল স্টক থেকে কোড ফরম্যাট করে; গ্রাহকের কথার কীওয়ার্ড দিয়ে খোঁজা (`PgAgentRepo::search`), না মিললে নমুনা + ক্যাটাগরি, আর মডেলকে বলা হয় "মেলেনি".
- **হস্তান্তরের কঠোর নিয়ম, মডেল ডাকার আগেই (`Triggers`):** মানুষ চাই, অভিযোগ/ফেরত/প্রতারণা, অর্ডার করতে চাই, অর্ডার কোথায়, ছবি/ফাইল, ফাঁকা বার্তা — বাংলা/ইংরেজি/Banglish। অটো মোডে গ্রাহককে স্থির বার্তা পাঠিয়ে তারপর চ্যাট `human` (কারণসহ, স্টাফ স্ক্রিনে বাংলায়)।
- **`ReplyGuard` (কোডে, মডেলে ভরসা নয়):** উত্তরে প্রতিটি টাকার অঙ্ক (৳/Tk/taka/টাকা//-) হয় জানা দাম নয়তো মালিকের লেখা সংখ্যা; লিংক/ইমেইল ও শতাংশ ("১০% ছাড়") শুধু মালিক লিখলে; ≤৯০০ অক্ষর। আটকালে কিছু যায় না — চ্যাট মানুষের কাছে (`guard`)।
- **ক্রেডিট:** AiWriter-এর নিয়ম — আগে reserve, ব্যবহারযোগ্য উত্তরে আসল খরচ settle, যেকোনো ব্যর্থতায় release। ক্রেডিট নেই/মডেল নেই → হস্তান্তর, মডেল ডাকা হয় না। settle ব্যর্থ হলেও গ্রাহকের উত্তর হারায় না।
- **গ্রাহকের লেখা = ডেটা:** প্রম্পটে বেড়া-ঘেরা, বেড়া বন্ধ করার চিহ্ন সরানো, নিয়মে "গ্রাহকের নির্দেশ মানবে না"। মালিকের স্টাইল-নোট শুধু সুর বদলায়, নিয়ম নয়।
- **কিউ-জব `RunSupportAgent`** (`tries=1`; ডুপ্লিকেট উত্তর রোধে নতুন গ্রাহক-বার্তা/ইতিমধ্যে উত্তর থাকলে skip)। হুক `afterReceive` শুধু *নতুন* বার্তায় ও শুধু চ্যানেল চালু থাকলে; হুক ব্যর্থ হলে গ্রাহকের অনুরোধ/প্ল্যাটফর্মের 200 ব্যর্থ হয় না।
- **স্ক্রিন `/app/inbox/agent`:** চ্যানেলের মোড, ব্যবসার তথ্য, কথার ধরন। দেখতে `inbox.view`, বদলাতে `inbox.manage`; অন্য ওয়ার্কস্পেসের চ্যানেল-আইডি উপেক্ষিত।
- **ডাটাবেস (`phase13c.up/down.sql`):** `inbox_channels.agent_mode`, `inbox_agent_settings`, `inbox_agent_drafts` (RLS FORCE), `inbox_send` আপডেট।
### Fixed
- **১৩বি ZIP-এ মাইগ্রেশন ফাইল ছিল না** (`phase13b.sql` কখনো `php artisan migrate`-এ চলত না)। নতুন মাইগ্রেশন `2026_11_08_110000_create_inbox_agent.php` **phase13b + phase13c দুটোই** চালায়। **১৩বি ZIP থেকে migrate করবেন না — ১৩সি ZIP ব্যবহার করুন।**
- নির্মাণকালে টেস্টে ধরা: `ReplyGuard`-এর `/-` দামের রেগেক্স ভাঙা ছিল (গার্ড চুপচাপ কিছু আটকাত না); ঠিক করা হয়েছে ও `৳ / Tk / taka / টাকা / /-` পাঁচ রূপেই টেস্ট আছে।
- টেস্ট: DB ৪২০ (নতুন ১০, রাউন্ডট্রিপ up→down→up সহ), pure PHP নতুন ২২ (`SupportAgentTest`), Pest নতুন ২ (এখানে চালানো যায়নি), ১১টি mutation ধরা পড়েছে (গার্ড বন্ধ/না-ডাকা, trigger বাদ, release বাদ, খসড়ায় পাঠানো, নোটিশ বাদ, hook বাদ, hook-এর catch বাদ)।
### Not yet
- **আসল AI মডেলের উত্তরের মান** (বিশেষ করে Banglish) আমরা যাচাই করিনি — তাই "খসড়া" দিয়ে শুরু করুন। **অর্ডার নেওয়া** নেই (Phase 14); **কমেন্ট-রিপ্লাই** নেই; গ্রাহকের ছবি AI দেখে না (মানুষের কাছে যায়)।

## [0.21.0] — 2026-11-08 — Phase 13b (Messenger, Instagram DM, WhatsApp Cloud, Telegram)
### Added
- **Webhook (`/webhooks/inbox/{key}`):** চ্যানেলের পাবলিক কী → ওয়ার্কস্পেসে ঢোকা → **প্ল্যাটফর্মের সই যাচাই (Meta: `X-Hub-Signature-256` HMAC; Telegram: গোপন হেডার; সবই constant-time)** → তারপর বডি পড়া। Meta GET handshake (verify token চ্যানেল-কী থেকে বের করা, আলাদা সংরক্ষণ নেই)। অন্য পেজ/নম্বরের ট্র্যাফিক বাদ; নিজের পাঠানো echo বাদ; ছবি/ভয়েস ইত্যাদি "[ছবি পাঠিয়েছেন]" লেখায়; প্ল্যাটফর্মের পুনঃচেষ্টায় ডুপ্লিকেট হয় না (message id)।
- **পাঠানো (`inbox:dispatch`, প্রতি মিনিটে + উত্তর দেওয়ামাত্র চেষ্টা):** `ChannelDispatcher` — জরুরি স্টপে বার্তা আটকে থাকে (কিছুই যায় না); **২৪-ঘণ্টা নিয়ম পাঠানোর মুহূর্তে আবার যাচাই**; বন্ধ চ্যানেলে ব্যর্থ; সাময়িক ত্রুটিতে সর্বোচ্চ ৫ বার retry; স্থায়ী ত্রুটিতে সাথে সাথে ব্যর্থ; লম্বা বার্তা প্ল্যাটফর্মের সীমা অনুযায়ী ভাগ (Messenger ২০০০, Instagram ১০০০, WhatsApp ৪০৯৬, Telegram ৪০০০); অর্ধেক গিয়ে থাকলে আর পুনরায় পাঠানো হয় না।
- ⚠️ এই ZIP-এ `phase13b`-এর মাইগ্রেশন ফাইল ভুলে বাদ পড়েছিল — ১৩সি-তে (0.22.0) ঠিক হয়েছে।
- **ডাটাবেস (`phase13b.up/down.sql`):** চ্যানেল-সিক্রেট শুধু `inbox_credentials()`-এ পড়া যায়; একটি প্ল্যাটফর্ম-অ্যাকাউন্ট একটিমাত্র ওয়ার্কস্পেসে (unique); `inbox_dispatch_ctx()`, `inbox_message_retry()`; `attempts` কলাম।
- **সেটআপ স্ক্রিন:** Messenger/Instagram/WhatsApp — আইডি + টোকেন + অ্যাপ সিক্রেট (এক কল দিয়ে যাচাই, তারপর এনক্রিপ্ট, আর দেখানো হয় না) ও Meta-তে বসানোর callback URL + verify token দেখায়; Telegram — বট টোকেন দিলেই `getMe` + `setWebhook` নিজে হয়।
- নতুন কনফিগ `INBOX_GRAPH_VERSION` (ডিফল্ট v21.0), `INBOX_DISPATCH_BATCH`।
- টেস্ট: DB ৪১০ (নতুন ৯), pure PHP নতুন ১৫ (সই, parser, webhook, অ্যাডাপ্টার, dispatcher), Pest নতুন ২ (এখানে চালানো যায়নি), ৭টি mutation ধরা পড়েছে।
### Not yet
- **কমেন্ট-রিপ্লাই** (পোস্টের কমেন্টে উত্তর/ইনবক্সে প্রাইভেট রিপ্লাই) — আলাদা মডেল দরকার, পরের ধাপ।

## [0.20.0] — 2026-11-08 — Phase 13a (ইউনিফাইড ইনবক্স কোর + ওয়েব চ্যাট উইজেট)
### Added
- **ডাটাবেস (`phase13.up/down.sql`):** `inbox_channels` (web_widget/messenger/instagram/whatsapp/telegram; অনুমানের অযোগ্য ৩২-হেক্স `public_key`; অনুমোদিত origin তালিকা — শুধু https বা localhost; গোপন কী কলাম অ্যাপ পড়তে পারে না), `inbox_contacts`, `inbox_conversations` (প্রতি কন্টাক্টে একটি চলমান কথোপকথন; `mode` bot|human), `inbox_messages` (দিক/প্রেরক সংগতি CHECK; webhook পুনঃচেষ্টায় ডুপ্লিকেট রোধ)। সব টেবিলে RLS ENABLE+FORCE; অ্যাপ রোল কথোপকথন/মেসেজ **সরাসরি লিখতে পারে না** — শুধু ফাংশনে।
- **নিয়ম ডাটাবেসে বলবৎ:** ২৪ ঘণ্টার উইন্ডো (Messenger/Instagram/WhatsApp: গ্রাহকের শেষ বার্তার ২৪ ঘণ্টার পর উত্তর নয়); জরুরি স্টপে সব বহির্গামী বার্তা বন্ধ (আসা বার্তা ঢোকে); কর্মী উত্তর দিলে চ্যাট `human` হয় ও AI চুপ (`human_mode`); হস্তান্তরের কারণ রেকর্ড; ফ্লাড সীমা (মিনিটে ২০ আসা/৩০ যাওয়া, চ্যানেলে ঘণ্টায় ৩০০ নতুন কন্টাক্ট)।
- **ওয়েব চ্যাট উইজেট:** `public/js/jarvis-widget.js` (এক লাইন embed, কুকি নেই, শুধু `textContent`) + পাবলিক `/widget/v1/{key}/{start|messages}`। ভিজিটর পরিচয় = সার্ভার-দেওয়া আইডি + গোপন টোকেন (শুধু SHA-256 হ্যাশ রাখা হয়); CORS শুধু মালিকের তালিকাভুক্ত origin-কে; অন্য origin বা Origin-ছাড়া অনুরোধ ৪০৩; `client_id` দিয়ে পুনঃচেষ্টা নিরাপদ।
- **স্টাফ স্ক্রিন:** `/app/inbox` (খোলা/অপঠিত/মানুষের হাতে/আমার/সমাধান), কথোপকথন (উত্তর, "আমি নিচ্ছি", "AI-কে ফেরত দিন", সমাধান), `/app/inbox/channels` (উইজেট তৈরি, embed কোড, origin)। নতুন অনুমতি `inbox.view|reply|manage` — `php artisan roles:sync` চালান।
- টেস্ট: DB ১৯ (নতুন; মোট ৪০১), pure PHP ৯ (`WidgetServiceTest`), Pest `InboxTest` (এখানে চালানো যায়নি), ৬টি mutation ধরা পড়েছে।

## [0.19.0] — 2026-11-08 — Phase 12c (JARVIS Connector — Laravel প্যাকেজ)
### Added
- **প্যাকেজ `packages/jarvis-connector` (`jarvis/connector`):** নিজের Laravel শপে বসে `/jarvis-connector/v1` (`ping`, `products`, `products/{id}`, `stock`) দেয়। প্রতিটি অনুরোধ HMAC-SHA256 সই (timestamp ±৫ মিনিট + একবার-ব্যবহারযোগ্য nonce + method + path/query + বডির hash); সই মেলার পরেই nonce ক্যাশে ঢোকে (অচেনা কেউ ক্যাশ ভরতে পারে না); সিক্রেট <৩২ অক্ষর হলে ৫০৩; বডি ≤১ MB; ভুল পণ্য ৪২২ (কঠোর যাচাই)।
- **কলাম-ম্যাপ ভিত্তিক `DatabaseProductStore`:** কনফিগে শপের টেবিল/কলাম বলে দিলে পণ্য পড়ে/লেখে (দাম major/minor, ভ্যারিয়েন্ট টেবিল, soft delete, `where` ফিল্টার, ছবি শুধু পড়া)। অনুরোধ থেকে SQL/কলাম আসে না; ডিলিট নেই; ট্রানজ্যাকশনে লেখা; ভ্যারিয়েন্ট অপশন-সিগনেচারে মেলে, মোছা হয় না। `jarvis-connector:secret`, `jarvis-connector:check` কমান্ড।
- **JARVIS-এর দিকে `ConnectorAdapter`** (`platform = jarvis_connector`): একই সই-নিয়ম (`ConnectorSignature`), প্রতি অনুরোধে নতুন nonce, শপের উত্তর অবিশ্বাস্য ধরে পরিষ্কার করে (ছবি শুধু http(s), সংখ্যা সীমিত)। `StoreGuard`/`StoreFactory`/`StoreConnector`/স্ক্রিনে নতুন প্ল্যাটফর্ম; সংযোগের আগে সইকৃত ping দিয়ে সিক্রেট যাচাই।
- টেস্ট: প্যাকেজ ২৩ (Handler ১১, SQL-স্টোর sqlite-এ ১২), JARVIS pure ৭ (দুটি **cross-test**: আসল adapter ↔ আসল Handler ↔ আসল স্টোর), `StoreAdaptersTest` ১০; ৬টি mutation ধরা পড়েছে।

## [0.18.0] — 2026-11-07 — Phase 12b (WooCommerce + Shopify সংযোগ)
### Added
- **অ্যাডাপ্টার:** `WooAdapter` (REST v3, Consumer Key/Secret, https), `ShopifyAdapter` (Admin REST, Access Token, `since_id` পেজিং; API সংস্করণ `SHOPIFY_API_VERSION`, ডিফল্ট 2026-07)। একই canonical পণ্য-রূপে পড়ে (বিবরণ HTML→টেক্সট, দাম paisa, ভ্যারিয়েন্ট, স্টক)।
- **সিঙ্ক (`StoreSync`):** (১) **pull** — দোকানের পণ্য আমাদের **খসড়া** হয়ে ঢোকে (SKU মিললে আগের পণ্যের সাথে জোড়া লাগে, নকল হয় না); বদলায়নি এমন পণ্য ছোঁয়া হয় না (content hash)। (২) **push** — শুধু **অনুমোদিত ও প্রকাশিত `live` কপি**, শেষ সিঙ্কের পরে প্রকাশ হওয়া পণ্য; পাঠানোর পর দোকান থেকে আবার পড়ে hash রাখে যাতে পরের pull পণ্যকে খসড়ায় নামায় না। (৩) **স্টক** তিন-মুখী মিল (`remote_stock`): শুধু দোকান বদলালে আমরা নিই, শুধু আমরা বদলালে দোকানকে জানাই, দুজনেই বদলালে দোকানের সংখ্যা জেতে (লেজারে `sync`)। জরুরি স্টপে push ও স্টক-push বন্ধ। কী রিজেক্ট হলে রান থামে ও দোকান "সমস্যা" চিহ্নিত; একটি পণ্য ব্যর্থ হলে বাকিগুলো চলে।
- **ডাটাবেস (`phase12b.up/down.sql`):** `store_sync_runs` (দোকানপ্রতি একসাথে একটি রান — unique index; ১৫ মিনিটের বেশি আটকানো রান `store_sync_reap` পরিষ্কার করে), গোপন কী কলাম অ্যাপ রোল **সরাসরি পড়তে পারে না** — শুধু `store_credentials()` ফাংশনে (নিজের ওয়ার্কস্পেসে)। `products.remote_hash/remote_synced_at`, `product_variants.remote_stock`, `store_connections.currency`।
- **নিরাপত্তা:** কী `CredentialVault`-এ এনক্রিপ্ট; সংযোগের আগে এক কল দিয়ে যাচাই; ঠিকানা শুধু সর্বজনীন https (private IP/localhost/পোর্ট/ক্রেডেনশিয়াল/`..` নিষিদ্ধ, SSRF গার্ড); Shopify শুধু `*.myshopify.com`।
- **UI:** `/app/catalog/stores` — সংযোগ, সম্পাদনা/নতুন কী, বন্ধ/চালু, "এখন সিঙ্ক করুন", শেষ রানের ফল। `php artisan catalog:sync` প্রতি ৫ মিনিটে (দোকানপ্রতি ১৫ মিনিট; `CATALOG_SYNC_EVERY_MINUTES`)। নতুন অনুমতি `stores.manage` (Owner, Admin) — `roles:sync` চালান।
- টেস্ট: DB ৩৮২ (নতুন ৭), pure PHP নতুন ২৩ (অ্যাডাপ্টার HTTP ১০, সিঙ্ক ১৩), Pest `StoreConnectionTest` (এখানে চালানো যায়নি), ৫টি mutation ধরা পড়েছে।
### Changed
- `CatalogRepo::create` এখন `external_id` নেয় ও `userId` nullable (সিঙ্কের স্বয়ংক্রিয় রানে ব্যবহারকারী নেই)।

## [0.17.0] — 2026-11-06 — Phase 12a (দেশি পণ্য ক্যাটালগ)
### Added
- **ডাটাবেস (`phase12.up/down.sql`):** `store_connections` (এখন শুধু `native`; WooCommerce/Shopify/Connector 12b/12c-তে), `products`, `product_variants` (সবসময় অন্তত একটি; ডিফল্ট `{}`), `stock_movements` ও `product_events` (append-only), `catalog_imports`। সব টেবিলে RLS ENABLE+FORCE।
- **Draft → Approval → Publish ডাটাবেসে বলবৎ:** অ্যাপ রোল `status`/`approved_*`/`live`/`published_*` লিখতে পারে না; শুধু `catalog_submit` → `catalog_decide` → `catalog_publish` (প্রতিটি নামধারী ব্যবহারকারী, `product_events`-এ লগ)। অনুমোদিত/প্রকাশিত/অপেক্ষমাণ পণ্যের বিষয়বস্তু বা ভ্যারিয়েন্ট বদলালে **trigger নিজেই খসড়ায় ফেরায়**; গ্রাহকের দেখা `live` কপি অপরিবর্তিত থাকে। জরুরি স্টপে অনুমোদন ও প্রকাশ বন্ধ।
- **স্টক লেজার:** পরিমাণ শুধু `stock_adjust()`-এ বদলায় (কলাম-গ্রান্ট নেই), ঋণাত্মক হয় না (`insufficient_stock`), প্রতিটি পরিবর্তন `stock_movements`-এ।
- **CSV বাল্ক ইমপোর্ট:** BOM, `,`/`;`, ২ MB/২০০০ সারি; দাম টাকায় (বাংলা অঙ্কও); `parent_sku` দিয়ে ভ্যারিয়েন্ট; একই SKU আবার দিলে আপডেট ও স্টক মেলানো; ভুল সারি আলাদা রিপোর্ট, বাকিগুলো হয়। সব ইমপোর্ট **খসড়া**।
- **AI বিবরণ:** `AiWriter` — চ্যাটের মতোই reserve → settle/release; JSON (বিবরণ + SEO), শুধু দেওয়া তথ্য; ব্যবহারকারী দেখে সংরক্ষণ না করলে কিছুই সেভ হয় না; এরপরও অনুমোদন লাগে। (AI ছবি Phase 22 Image Studio-তে।)
- **ওয়েব:** `/app/catalog` (তালিকা, খোঁজ, অবস্থা-ফিল্টার), এডিটর (ভ্যারিয়েন্ট, স্টক, অনুমোদন ধাপ, ইতিহাস), `/app/catalog/import` + নমুনা CSV। নতুন অনুমতি `catalog.view|manage|approve` — `php artisan roles:sync` চালান।
- **মোবাইল:** Approval Inbox-এ `product` ধরন (`mobile_approvals`/`mobile_decide` বাড়ানো, অনুমোদনে PIN step-up আগের মতোই); `ApprovalController` ধরন অনুযায়ী অনুমতি দেখে (outreach → `outreach.approve`, product → `catalog.approve`)।
- টেস্ট: DB ৩৭৫ (নতুন ২২), engine ১৪৭, pure PHP নতুন ২২ (CSV, সার্ভিস, AI লেখক, pg-array), Pest `CatalogTest` (এখানে চালানো যায়নি)।

## [0.16.0] — 2026-11-05 — Phase 11 (মোবাইল অ্যাপ v1)
### Added
- **Mobile API (`/api/v1/mobile/*`, Laravel):** bootstrap (ব্যবহারকারী, ওয়ার্কস্পেস, কে কী করতে পারে), ডিভাইস নিবন্ধন (প্রতি ওয়ার্কস্পেসে), push address, ড্যাশবোর্ড, ব্যবহার, অটোমেশন মনিটর, সিস্টেম স্বাস্থ্য (Owner/Admin), **Approval Inbox**, নোটিফিকেশন, চ্যাট (একবারে পূর্ণ উত্তর; বিলিং ওয়েবের মতোই)। লগইন বিদ্যমান Sanctum `POST /api/v1/auth/login`।
- **PIN step-up (ডাটাবেসে বলবৎ — `phase11.up.sql`):** ৬ সংখ্যার PIN (bcrypt হ্যাশ; সহজ PIN নিষিদ্ধ; সেট/বদলাতে অ্যাকাউন্টের পাসওয়ার্ড লাগে)। PIN ঠিক হলে ঐ ফোনে ৫ মিনিটের step-up জানালা। **অনুমোদন (`mobile_decide`) ও কাজ আবার চালু step-up ছাড়া DB-ই প্রত্যাখ্যান করে**; ৫ ভুলে ১৫ মিনিট লক (লক অবস্থায় PIN যাচাইই হয় না)। বাতিল (reject) ও জরুরি স্টপে PIN লাগে না।
- **জরুরি স্টপ:** `emergency_events` (append-only)। স্টপ এক ট্যাপে; চালু করতে PIN। স্টপ থাকলে মোবাইল **ও ওয়েব** কোনো অনুমোদনই হয় না এবং আউটরিচ সেন্ডার কিছু ক্লেইম করে না (`emergency_active()`), অনুমোদিত মেইল অপেক্ষায় থাকে ও চালুর পর যায়। ওয়ার্কস্পেসের সব ফোনে নোটিফিকেশন। নতুন অনুমতি `emergency.control` (Owner, Admin) — `roles:sync` চালান।
- **Push:** Expo Push Service (FCM/APNs-এ রিলে); `php artisan mobile:push` প্রতি মিনিটে: অনুমোদনের অপেক্ষার ডাইজেস্ট (একজনের একটি অপঠিত নোট), অপাঠানো push পাঠানো, মৃত টোকেন বাদ, লগআউট/মেয়াদ-শেষ টোকেনের ফোন অবসর। push-এ শুধু শিরোনাম/সংক্ষিপ্ত বার্তা।
- **Expo অ্যাপ (`apps/mobile`, SDK 58, expo-router, TypeScript strict):** লগইন (২FA সহ, সার্ভার ঠিকানা), হোম, চ্যাট, অনুমোদন, মনিটর, আরও (নোটিফিকেশন, ব্যবহার, নিরাপত্তা/PIN/আঙুলের ছাপ, ওয়ার্কস্পেস বদল, সিস্টেম স্বাস্থ্য), push নিবন্ধন, EAS বিল্ড কনফিগ (`eas.json`), আইকন/স্প্ল্যাশ।
- টেস্ট: DB ৩৫০ (নতুন ২৮), মোবাইল ২৬ (API ক্লায়েন্ট, step-up প্রবাহ, ফরম্যাট, PIN নিয়ম), pure PHP নতুন ২১, app↔Laravel route contract ৩, **`expo export` দিয়ে সত্যিকারের Android বান্ডেল**। CI-তে `mobile` জব, Makefile `mobile-test`।
### Changed
- `PgOutreachRepo`: `approve` ও `claim` এখন `emergency_active()` মানে। `AuthController`/`SecurityController`: টোকেন বাতিল/লগআউটে ফোন অবসর। `TenantRoles`: `emergency.control`।

## [0.15.0] — 2026-11-04 — Phase 10 (পেমেন্ট ও সাবস্ক্রিপশন)
### Added
- **ডাটাবেসে টাকার নিয়ম (`phase10.up/down.sql`):** দাম, কুপন, proration, VAT একটি ফাংশনেই (`billing_quote`) — ব্রাউজার কখনো অঙ্ক ঠিক করে না। অর্ডার (`orders`) শুধু `billing_create_order`-এ তৈরি; অঙ্ক/আইটেম পরে বদলানো যায় না (trigger)। অর্ডার `paid` হয় একমাত্র `billing_settle()`-এ: গেটওয়ে, মুদ্রা ও **হুবহু অঙ্ক** মেলে কিনা দেখে; একই লেনদেন দুবার এলে `duplicate` (কিছু বদলায় না); একই txn দুই অর্ডারে নয়; তারপর একই ট্রানজেকশনে সাবস্ক্রিপশন চালু/নবায়ন বা ক্রেডিট যোগ (বিদ্যমান ledger-এর `credit_grant` দিয়ে), কুপন গণনা, গ্যাপ-হীন নম্বরের **VAT ইনভয়েস** (`INV-২০২৬-০০০০০১`; বিক্রেতা/ক্রেতার তথ্য স্ন্যাপশট; অপরিবর্তনীয়)।
- সাবস্ক্রিপশন চক্র: আপগ্রেড (অব্যবহৃত সময়ের proration ক্রেডিট), ডাউনগ্রেড (মেয়াদ শেষে কার্যকর, এখন টাকা কাটা/ফেরত নেই), মেয়াদ-শেষে বাতিল ও পুনরায় চালু, **গ্রেস পিরিয়ড** (ডিফল্ট ৭ দিন; `effective_feature` এখন গ্রেস মানে), মেয়াদ শেষ → ফ্রি, বার্ষিক প্ল্যানে মাসিক ক্রেডিট রিফিল, পুরনো pending অর্ডার expire (`billing_lifecycle()`, `php artisan billing:run`, প্রতি ১০ মিনিটে)।
- কুপন (শতকরা/নির্দিষ্ট, মেয়াদ, মোট ও প্রতি-ওয়ার্কস্পেস সীমা, প্রযোজ্য ধরন); কুপন তালিকা অ্যাপ থেকে দেখা যায় না। ক্রেডিট প্যাকেজ কেনা। **রিফান্ড রেকর্ড** (admin): ক্রেডিট নোট, আংশিক/পূর্ণ ফেরত, ঐচ্ছিক ক্রেডিট ফেরত-নেওয়া ও সাবস্ক্রিপশন বাতিল। `payment_events`: প্রতিটি গেটওয়ে কলব্যাকের লগ (admin-only, append-only)।
- **গেটওয়ে অ্যাডাপ্টার (`app/Billing/Gateways`):** SSLCommerz, bKash (tokenized), Nagad (RSA), Stripe Checkout, এবং `demo` (BILLING_DEMO=true, কোনো টাকা কাটে না, সইযুক্ত বোতাম — cPanel/ডেমোর জন্য)। কলব্যাক কখনো বিশ্বাস করা হয় না: প্রতিটিতে **গেটওয়েকে সরাসরি জিজ্ঞেস** করা হয় (SSLCommerz Validation API, bKash execute/query, Nagad verify, Stripe session fetch + webhook signature)।
- UI: `/app/billing` (প্ল্যান, বর্তমান অবস্থা, cancel/resume/downgrade), চেকআউট (কুপন প্রিভিউ), ক্রেডিট কেনা, অর্ডার, ইনভয়েস (প্রিন্ট/PDF), বিলিং তথ্য (BIN/TIN)। `/admin/billing`: MRR, মাসওয়ারি আয়/VAT/ফেরত, প্ল্যান অনুযায়ী গ্রাহক, churn, গেটওয়ে সাফল্য, যাচাই-সারি, কুপন, রিফান্ড, বিলিং সেটিংস।
- নতুন অনুমতি: `billing.manage` (Owner, Admin) ও admin `billing.manage` — `php artisan roles:sync` চালান।
### Changed
- `effective_feature`: `active`/`trialing` সাবস্ক্রিপশনের মেয়াদ গেলেও গ্রেস দিন পর্যন্ত সুবিধা চালু (আগে সঙ্গে সঙ্গে ফ্রি)। অ্যাপ role আর `subscriptions`-এ সরাসরি লিখতে পারে না — শুধু ফাংশনের মাধ্যমে।
### Tests
- DB ৩২১ (নতুন `test_billing.py` ৪৫, `test_billing_php_sql.py` ৭; amount/currency/gateway/duplicate/VAT-rounding মিউটেশন ধরা পড়ে)। বিশুদ্ধ PHP: `BillingServiceTest` ১৯ (নকল redirect, amount mismatch, duplicate IPN, ভুল গেটওয়ে, ঝুঁকিপূর্ণ পেমেন্ট), `BillingGatewaysTest` ১৫ (Nagad-এ আসল RSA), `BillingFactoryTest` ৫। Pest `BillingTest` যোগ — অচালিত।
### Not verified
- **আসল SSLCommerz/bKash/Nagad/Stripe sandbox-এ কিছুই চালানো হয়নি** (কোনো কী নেই)। অ্যাডাপ্টার প্রকাশ্য ডকুমেন্টেশন থেকে লেখা; ফিল্ডের নাম/URL আপনার sandbox-এ মিলিয়ে নিতে হবে। Nagad sandbox সাধারণ `http` — অ্যাপ শুধু https বলে সেটি ব্যবহারযোগ্য নয় (live বা নিজের https প্রক্সি)। `migrate`, Pest, Blade রেন্ডার, scheduler চালানো হয়নি।
### Not yet
- স্বয়ংক্রিয় নবায়ন (সংরক্ষিত কার্ড/মান্ডেট) নেই — মেয়াদ শেষের আগে গ্রাহককে নিজে নবায়ন করতে হয়; রিমাইন্ডার ইমেইল নেই। ট্রায়াল শুরুর UI নেই। গেটওয়ে API দিয়ে স্বয়ংক্রিয় রিফান্ড নেই (admin গেটওয়ে ড্যাশবোর্ডে টাকা ফেরত দেয়, এখানে নথিভুক্ত করে)। ইনভয়েস PDF সার্ভার-সাইড নয় (ব্রাউজার প্রিন্ট)।

## [0.14.0] — 2026-11-03 — Phase 9c (Gmail / Microsoft Graph + স্বয়ংক্রিয় reply/bounce)
### Added
- **OAuth 2.0 (Authorization Code + PKCE S256 + এক-ব্যবহারের state):** `/app/outreach/accounts`-এ "Gmail সংযুক্ত করুন" / "Outlook সংযুক্ত করুন"। অনুমতি: মেইল পাঠানো + ইনবক্স পড়া (Gmail: `gmail.send`, `gmail.readonly`; Microsoft: `Mail.Send`, `Mail.Read`, `offline_access`, `User.Read`)। শুধু refresh token vault-এ এনক্রিপ্ট করে রাখা হয়; access token কোথাও সংরক্ষিত হয় না। Microsoft-এর ঘুরে-যাওয়া (rotating) refresh token নতুনটি সংরক্ষিত হয়।
- **পাঠানো:** `GmailTransport` (`users.messages.send`, base64url RFC 5322), `GraphTransport` (`/me/sendMail`, base64 MIME `text/plain` — তাই `List-Unsubscribe` হেডার থাকে)। নিজস্ব `Mime` লেখক: হেডারে লাইন-ব্রেক অসম্ভব, বাংলা বিষয়/নাম RFC 2047, ঠিকানা কঠোরভাবে যাচাই।
- **ত্রুটি-নীতি:** অ্যাকাউন্টের সমস্যা (লগইন বাতিল, কোটা, আউটেজ) → ব্যাচ থামে, মেইল অনুমোদিত অবস্থায় অপেক্ষা করে, চেষ্টা ফেরত যায়, UI-তে কারণ দেখায়। শুধু ঐ প্রাপককে প্রত্যাখ্যান (400) → ঐ মেইল ব্যর্থ।
- **reply/bounce স্বয়ংক্রিয়:** প্রতি ৫ মিনিটে (শুধু যে অ্যাকাউন্টে উত্তরের অপেক্ষায় মেইল আছে) Gmail/Graph ইনবক্সের **মেটাডেটা ও ছোট স্নিপেট** পড়ে `InboundClassifier` দিয়ে ভাগ করে: *reply* (→ "উত্তর এসেছে", ফলো-আপ বাতিল), *bounce* (→ "ফেরত", ঠিকানা নিষিদ্ধ), *auto* (out-of-office/রোবট — কিছু বদলায় না), *other*। প্রতিটি ইনবাউন্ড মেইল একবারই (`outreach_inbound` PK)। সহকর্মীর উত্তরও থ্রেড/Message-ID দিয়ে ধরা হয়।
- DB (`phase9c.up/down.sql`): `email_accounts.provider/refresh_ct/last_error/inbox_checked_at`, `outreach_messages.provider_message_id/thread_id`, `outreach_inbound`।
### Changed
- `MailTransport::send()` এখন `['message_id','provider_id','thread_id']` ফেরত দেয়; নতুন `outreach.*` কনফিগ/env (`config/outreach.php`)।
### Not yet
- SMTP অ্যাকাউন্টের reply/bounce স্বয়ংক্রিয় নয় (IMAP বাকি) — হাতে চিহ্নিত করতে হয়। WhatsApp click-to-chat বোতাম। প্রস্তাব PDF মেইলে সংযুক্ত করা। HTML মেইল। Gmail-এর `gmail.readonly` একটি "restricted" scope — প্রকাশ্য অ্যাপের জন্য Google যাচাই লাগে (ENVIRONMENT_SETUP.md)।
### Tests
- DB ২৬৮ (নতুন `test_outreach_oauth.py` ৬, `test_outreach_inbound_sql.py` ১০)। বিশুদ্ধ PHP: `OutreachOAuthTest` ১৭, `OutreachInboxTest` ১০ (মিউটেশন ধরা পড়ে)। Pest `OutreachTest`-এ OAuth টেস্ট যোগ — অচালিত। **আসল Google/Microsoft-এর সাথে কিছুই যাচাই হয়নি** (নকল HTTP দিয়ে লজিক যাচাই)।

## [0.13.0] — 2026-11-02 — Phase 9b (ইমেইল আউটরিচ কোর, SMTP)
### Added
- DB (`phase9b.up/down.sql`): `email_accounts` (SMTP পাসওয়ার্ড শুধু vault-ciphertext; plaintext SMTP নেই), `outreach_campaigns`, `outreach_messages`, `outreach_suppressions`। **ডাটাবেস নিজেই আটকায়:** অনুমোদনকারী ও সময় ছাড়া `approved/sending/sent` হওয়া যায় না (CHECK); নিষিদ্ধ ঠিকানা queue/approve/send-এর দিকে যেতে পারে না (trigger); অন্য ওয়ার্কস্পেসের ক্যাম্পেইন/লিড/কন্টাক্ট জোড়া যায় না; একই ব্যক্তি একই ধাপের মেইল দুবার পায় না (unique); bounce হলে ঠিকানা আপনা-আপনি নিষিদ্ধ।
- `outreach_unsubscribe(token)` (SECURITY DEFINER, লগইন ছাড়া): ১২৮-বিট টোকেন → নিষিদ্ধ তালিকা + অপেক্ষমাণ মেইল বাতিল + কন্টাক্ট `unsubscribed`; ভুল টোকেনে একই উত্তর।
- Laravel `app/Outreach`: `Warmup` (প্রথম দিন ৫, প্রতিদিন +৩, ব্যবহারকারীর সীমা পর্যন্ত), `OutreachService` (queue → approve → send; পাঠানোর ঠিক আগে আবার নিষিদ্ধ-তালিকা মেলানো; ব্যাচে ২–৬ সেকেন্ড বিরতি; ভুল লগইন/সার্ভার ডাউনে ব্যাচ থামে ও চেষ্টা ফেরত; ৩ বার ব্যর্থ হলে failed; ফলো-আপ ড্রাফট অনুমোদন-সারিতে), `DnsAuth` (SPF/DKIM/DMARC), `HostGuard` (SMTP হোস্ট প্রাইভেট নেটওয়ার্কে হলে প্রত্যাখ্যান), `Compose` (সাবজেক্ট এক লাইন, প্রতি মেইলে আনসাবস্ক্রাইব ফুটার + `List-Unsubscribe` / One-Click হেডার), `SymfonyMailTransport`।
- UI: `/app/outreach` (ক্যাম্পেইন), `/queue` (অনুমোদন-সারি), `/accounts` (SMTP + DNS পরীক্ষা), `/suppressions`; পাবলিক `/u/{token}` (GET শুধু নিশ্চিতকরণ পাতা, POST-এ কাজ)। `php artisan outreach:run` প্রতি মিনিটে (scheduler)। নতুন পারমিশন `outreach.view/manage/approve` (`roles:sync` লাগবে)।
### Not yet
- reply/bounce **স্বয়ংক্রিয় শনাক্ত নেই** (IMAP/Gmail/Graph পোলিং ৯সি) — এখন হাতে "উত্তর এসেছে/ফেরত এসেছে" চিহ্নিত করতে হয়। Gmail API/Microsoft Graph (৯সি, আপনার OAuth key লাগবে)। WhatsApp click-to-chat বোতাম। প্রস্তাব PDF মেইলে সংযুক্তি। শুধু plain-text মেইল। নিষিদ্ধ তালিকা থেকে সরানোর UI ইচ্ছাকৃতভাবে নেই।
### Tests
- DB ২৫২ (নতুন `test_outreach.py` ১০ — ৬টি মিউটেশন ধরা পড়ে; `test_outreach_php_sql.py` ৯)। বিশুদ্ধ PHP `OutreachPureTest` ১৬ (৯টি মিউটেশন ধরা পড়ে)। Pest `OutreachTest` অচালিত। আসল SMTP সার্ভারে পাঠানো **যাচাই হয়নি**।

## [0.12.0] — 2026-11-02 — Phase 9a (Portfolio + Brand Kit + Proposal, PDF/DOCX)
### Added
- **Engine** (`apps/ai-engine/app/docs`, `POST /internal/v1/render/proposal.pdf|.docx`, signed): কাঠামোবদ্ধ প্রস্তাব → PDF (headless Chromium, **JavaScript বন্ধ, নেটওয়ার্ক বন্ধ** — শুধু `data:` URL চলে) ও DOCX (python-docx; বাংলার জন্য complex-script ফন্ট/সাইজ সেট)। বাংলা শেপিং ঠিক রাখতে Noto Sans Bengali + Noto Sans (OFL, `app/docs/fonts/`) PDF-এ এমবেড হয়। সব ইউজার-টেক্সট escape; রঙ শুধু `#RRGGBB`; লোগো শুধু PNG/JPEG ≤৬০০KB; লিংক শুধু http(s)।
- DB (`phase9a.up/down.sql`): `brand_kits` (ওয়ার্কস্পেসে একটি ডিফল্ট), `portfolio_items`, `proposal_templates` (প্ল্যাটফর্মের ৬টি টেমপ্লেট — web_design/marketing/general × bn/en — সব ওয়ার্কস্পেস পড়ে, বদলাতে পারে না; নিজেরটি বানানো যায়), `proposals`। cross-tenant ট্রিগার (অন্য ওয়ার্কস্পেসের লিড/ব্র্যান্ড/টেমপ্লেটে লিংক হয় না)। লিডের ইতিহাসে `proposal` ঘটনা।
- Laravel `app/Proposals`: `Money` (পূর্ণ পয়সায় হিসাব; বাংলা অঙ্ক ও লাখ-কোটি গ্রুপিং `৳ ১২,৫০,০০০`), `Pricing` (লাইন-পার্স, ছাড়/ভ্যাট basis point-এ, half-up রাউন্ডিং, overflow-নিরাপদ), `TemplateFiller` (`{{client_name}} {{domain}} {{brand_name}} {{gaps_list}}`; একবারই বসায়), `DocumentBuilder`, `ProposalService`, `PgProposalRepo`, `HttpProposalEngine`।
- UI: `/app/brand`, `/app/portfolio`, `/app/proposals` (+ নতুন/এডিটর/ডাউনলোড), লিডের পাতায় "নতুন প্রস্তাব বানান"। লিডের সাইট-দুর্বলতা প্রস্তাবে আপনা-আপনি বসে। নতুন পারমিশন `proposals.view/manage` (`php artisan roles:sync` লাগবে)।
### Changed
- AI ইঞ্জিনের Docker image এখন ডিফল্টে Chromium সহ (PDF-এর জন্য বাধ্যতামূলক; ~৪০০MB)। হালকা চাইলে `--build-arg WITH_RENDER=0` (DOCX চলবে, PDF 503 দেবে)।
### Not yet (Phase 9b/9c)
- ইমেইল আউটরিচ (ক্যাম্পেইন, অনুমোদন-সারি, সাপ্রেশন, আনসাবস্ক্রাইব, দৈনিক সীমা, SPF/DKIM/DMARC চেকার, ফলো-আপ, bounce/reply), Gmail/Microsoft Graph। প্রস্তাবে পোর্টফোলিওর ছবি/থাম্বনেইল নেই (শুধু লেখা+লিংক)। নিজের টেমপ্লেট সম্পাদনার UI নেই (ডাটাবেসে আছে)। প্রস্তাব ইমেইলে পাঠানো ৯বি-তে।
### Tests
- Engine ১৪৭ (নতুন ১৬: escape, রঙ/লোগো যাচাই, নেটওয়ার্ক-ব্লক, DOCX বিষয়বস্তু ও ফন্ট, **আসল Chromium-এ বাংলা PDF** — ফন্ট এমবেড, পেজ-সংখ্যা, ছবি খালি নয়; আমি রেন্ডার করা পাতা চোখেও দেখেছি: যুক্তাক্ষর ঠিক)।
- DB ২৩৩ (নতুন: `test_proposals.py` ৯, `test_proposals_php_sql.py` ৫)। বিশুদ্ধ PHP `ProposalsPureTest` ১৫ (১২টি মিউটেশন ধরা পড়ে)। Pest `ProposalTest` অচালিত।

## [0.11.0] — 2026-11-02 — Phase 8b (CRM v1)
### Added
- DB (`phase8b.up/down.sql`): `lead_activities` (শুধু যোগ হয়, বদল/মোছা যায় না — নোট ও ধাপ-ইতিহাস), `crm_tasks`, ট্যাগ সীমা (সর্বোচ্চ ১০), cross-tenant ট্রিগার `lead_child_same_tenant`, ফাংশন `lead_move_stage` (ধাপ বদল + ইতিহাস একসাথে; একই ধাপে আবার গেলে কিছু লেখে না; won/lost হলে ফলো-আপ মুছে যায়)।
- Laravel `app/Crm`: `CrmService` (নিয়ম: ধাপ/নোট/টাস্ক/ট্যাগ/ফলো-আপ; তারিখ ওয়ার্কস্পেসের টাইমজোনে, অতীতের ফলো-আপ ও বন্ধ লিডে ফলো-আপ নিষেধ), `TagNormalizer`, `BdPhone`, `LeadCsv` (পার্স/রপ্তানি, ফর্মুলা-ইনজেকশন সুরক্ষিত), `CsvImporter`, `PgCrmStore`।
- UI: `/app/leads/board` (৬ কলামের পাইপলাইন, ট্যাগ ফিল্টার, কার্ডে "সরান"), লিডের পাতায় ধাপ/ফলো-আপ/ট্যাগ/টাস্ক/নোট/ইতিহাস, `/app/leads/tasks` (খোলা টাস্ক + ১৪ দিনের ফলো-আপ), `/app/leads/import` ও `/app/leads/export` (CSV UTF-8, Excel-এ বাংলা ঠিক)।
- লেখার কাজ শুধু `leads.manage` পারমিশনে; Member/Auditor দেখতে পারে।
### Changed
- আমদানি করা কোম্পানির `analyzed_at` ফাঁকা থাকে (বিশ্লেষণ হয়নি বোঝাতে); `PgLeadStore::createCompany` এখন `analyzed` ফ্ল্যাগ নেয়।
### Not yet
- AI-লেখা সাইট-বিশ্লেষণ সারাংশ (ক্রেডিট কাটার ফ্লো লাগবে) — আলাদা ছোট ধাপ। বোর্ডে drag-and-drop নেই (ড্রপডাউনে সরানো)। কোম্পানি/কন্টাক্ট হাতে যোগ-সম্পাদনা নেই (CSV আমদানি দিয়ে যোগ হয়)।
### Tests
- DB ২১৯ (নতুন: `test_crm.py` ৯, `test_crm_php_sql.py` ৩; আগের লিড টেস্ট ঠিক করা)। বিশুদ্ধ PHP `CrmPureTest` ১২ (১০টি মিউটেশন ধরা পড়ে)। Pest `LeadCrmTest` অচালিত।
- টেস্টে ধরা পড়া বাগ: `LeadController`-এর SQL স্ট্রিংয়ে `','` থাকায় PHP স্ট্রিং ভেঙে যাচ্ছিল (`php -l` ধরেনি) → `chr(44)`।

## [0.10.0] — 2026-11-02 — Phase 8a (Website Lead Extractor)
### Added
- **Engine** (`apps/ai-engine/app/leads`, `POST /internal/v1/leads/crawl`, signed): একটি সাইট ক্রল করে তথ্য ফেরত দেয় (stateless; সংরক্ষণ Laravel-এ)। `ssrf.py` — দুই স্তর: URL পার্স (শুধু http/https, credential/IP-literal/অস্বাভাবিক পোর্ট বাদ) + *সংযোগের সময়* DNS রিজলভ করে একটিও প্রাইভেট উত্তর থাকলে প্রত্যাখ্যান (rebinding প্রতিরোধ), প্রতিটি redirect আবার যাচাই। `fetch.py` — robots.txt মানে (4xx = খোলা, 5xx = কিছুই নয়, Crawl-delay), প্রতি হোস্টে বিরতি, ১.৫MB সীমা, শুধু HTML। `extract.py` — ইমেইল (mailto, Cloudflare-এনকোডেড, `[at]/[dot]`), ফোন (libphonenumber, ডিফল্ট BD → E.164), সোশ্যাল, প্রযুক্তি, ট্র্যাকিং (GA/GTM/Pixel), ফর্ম, ঠিকানা। `emailcheck.py` — MX যাচাই (পাঠানো ছাড়া)। `analyze.py` — সাইটের দুর্বলতা (বাংলা লেবেলসহ সম্ভাব্য সার্ভিস) ও ০–১০০ স্কোর (ব্যাখ্যাযোগ্য সূত্র)। `render.py` — ঐচ্ছিক Playwright (JS সাইট), প্রতিটি সাব-রিকোয়েস্ট যাচাই।
- DB (`phase8.up/down.sql`): `lead_research_jobs`, `lead_research_items`, `companies`, `contacts`, `leads` (RLS)। ডুপ্লিকেট রোধ ডাটাবেসেই: কোম্পানি = (tenant, domain), একটি জীবিত লিড প্রতি কোম্পানি, ইমেইল প্রতি tenant-এ একবার, একই কোম্পানিতে একই ফোন একবার। cross-tenant ট্রিগার; মুছে ফেলা যায় না (`deleted_at`)। আটকে থাকা কাজ ফেরত আনে `lead_items_requeue_stale`।
- Laravel `app/Leads`: `DomainNormalizer`, `UrlList` (টেক্সট/CSV), `LeadIngestor` (কোম্পানি+কন্টাক্ট+লিড, নাম কখনো ওভাররাইট নয়), `ResearchRunner` (ইঞ্জিন বন্ধ থাকলে ৩ বার চেষ্টা, একটি সাইট ব্যর্থ হলে বাকিরা চলে), `ProcessLeadItem` job, `ResearchLimits` (প্রতি গবেষণায় ২০০ সাইট, ২৪ঘ-এ ২০টি, একসাথে ২টি)।
- UI: `/app/leads/research` (URL/CSV, অগ্রগতি, বাতিল), `/app/leads` (তালিকা, ফিল্টার), `/app/leads/{id}` (যোগাযোগ, দুর্বলতা→সার্ভিস)। নতুন tenant পারমিশন `leads.view`/`leads.manage` (Member শুধু দেখতে); `php artisan roles:sync` (update.sh চালায়)।
### Not yet (Phase 8b)
- পাইপলাইন বোর্ড, নোট, টাস্ক, ট্যাগ, ফলো-আপ, লিড CSV import/export, AI-লিখিত সাইট বিশ্লেষণ সারাংশ (এখনকার বিশ্লেষণ নিয়মভিত্তিক, ক্রেডিট লাগে না)।
### Tests
- Engine ১৩১ (নতুন ৭২: ফিক্সচার সাইট, robots, SSRF ৪০+ কেস, আসল loopback সার্ভারে guarded transport, আসল Chromium রেন্ডার); ruff + mypy strict পাস।
- DB ২০৭ (নতুন: leads ১৫ + PHP SQL ৩)। বিশুদ্ধ PHP ১৩ চেক + মিউটেশন। Pest `LeadResearchTest` অচালিত।
### Notes
- ক্রলিং-এ ক্রেডিট কাটে না (v1); নিয়ন্ত্রণ ভলিউম-সীমায়। প্রকাশ্য ওয়েবসাইটের যোগাযোগ তথ্য সংগ্রহের স্থানীয় আইন/প্ল্যাটফর্ম নীতি ব্যবহারকারীর দায়িত্ব; outreach (Phase 9) আলাদা সম্মতি/unsubscribe নিয়মে হবে।

## [0.9.0] — 2026-11-02 — Phase 7 (Messaging Gateway + Telegram)
### Added
- DB (`phase7.up/down.sql`): `channel_accounts` (প্ল্যাটফর্ম বট; টোকেন এনক্রিপ্টেড, অ্যাপ/অ্যাডমিন কেউ পড়তে পারে না), `channel_link_codes`, `channel_links`, `channel_events` (শুধু metadata, বার্তার লেখা নয়)। webhook-এ tenant context থাকে না, তাই ঢোকার একমাত্র পথ SECURITY DEFINER ফাংশন: `channel_account_authenticate`, `channel_event_claim/finish`, `channel_resolve` (শুধু সক্রিয় workspace-এর সক্রিয় সদস্য), `channel_link_redeem`, `channel_account_for_send`, `channel_account_token_for_admin` (শুধু অ্যাডমিন গ্রুপ), `channel_account_health`, `channel_events_purge`।
- নিয়ম: একটি Telegram পরিচয় = একটি JARVIS ইউজার (সব tenant মিলিয়ে); কোড একবার ব্যবহারযোগ্য, ১০ মিনিট, হ্যাশ করে জমা; একই update দুবার প্রসেস হয় না; ক্র্যাশ করা update ২ মিনিট পর Telegram retry-তে আবার নেওয়া যায়।
- Laravel `app/Messaging`: `TelegramNormalizer`, `GatewayHandler` (নীতি), `PgChannelStore`, `TelegramApi`/`TelegramClient` (টোকেন লগে/ত্রুটিতে যায় না), `ServiceChatBridge` (Phase 6 ChatService + ক্রেডিট), `TextSplitter`, `LinkCode`, `ProcessChannelUpdate` job, webhook `POST /webhooks/telegram/{account}` (secret-token হেডার, ভুল হলে 403)।
- বট কমান্ড: `/start`, `/link কোড`, `/new`, `/unlink`, `/help`। টেক্সট ও টেক্সট-ফাইল (≤২০০KB, UTF-8) সমর্থিত। অচেনা ইউজার, বট, গ্রুপ চ্যাট উপেক্ষিত (অচেনা কেউ `/start` লিখলে শুধু স্থির "কীভাবে যুক্ত করবেন" লেখা পায়)। ভুল কোড ৫ বারের পর ১০ মিনিট আটকা।
- কাস্টমার `/app/settings/telegram` (কোড নিন, আলাদা করুন)। অ্যাডমিন `/admin/channels` (বট যুক্ত + টোকেন যাচাই, চালু/বন্ধ = setWebhook/deleteWebhook, secret বদল, Telegram-এর অবস্থা, ২৪ঘ সারাংশ, ইভেন্ট লগ)।
- Approve/Reject বাটনের ধরন (`ap:<id>:a|r`) পার্স হয়, `ApprovalDecider` ইন্টারফেসে যায়; approvals ইঞ্জিন এখনো নেই, তাই ডিফল্ট উত্তর "আর বৈধ নেই"।
### Not yet
- ছবি ও ভয়েস: গ্রহণ করে সৎভাবে জানায় "এখনো চালু হয়নি" (কোনো ক্রেডিট কাটে না)। Telegram-এ স্ট্রিমিং নয়, সম্পূর্ণ উত্তর একবারে।
### Tests
- DB ১৮৯ (নতুন: চ্যানেল ১৫ + PHP-র SQL স্ট্রিং আসল PG-তে ২)। বিশুদ্ধ PHP ৬৯ চেক; GatewayHandler-এর ৫টি মিউটেশন ধরা পড়ে।
- Pest `TelegramWebhookTest` অচালিত।

## [0.8.0] — 2026-11-02 — Phase 6 (Chat + Dashboard v1 + Deploy Kit)
### Added
- DB (`phase6.up/down.sql`): `conversations`, `messages` (শেষ হওয়া মেসেজ অপরিবর্তনীয়, শুধু `hidden_at`; মোছা যায় না), `chat_attachments`; তিনটিতেই RLS; cross-tenant insert ট্রিগারে বন্ধ; `chat_finalize_stale()`।
- চ্যাট কোর (`app/Chat`): মডেল বাছাই → মেসেজ সংরক্ষণ → `credit_reserve` → SSE স্ট্রিম → `credit_settle`/`credit_release`। প্রতিটি reservation ঠিক একবার settle বা release হয়; দুটোই ব্যর্থ হলে `credit_release_stale()` ফেরত দেয়। Stop = আংশিক উত্তর, ব্যবহৃত টোকেনের বিল। ক্রেডিট হিসাব পূর্ণসংখ্যায় (float নেই)।
- `/app/chat`: স্ট্রিমিং, ইতিহাস, মডেল পরিবর্তন, সংযুক্তি (টেক্সট ফাইল), stop/regenerate/copy। ড্যাশবোর্ড v1, প্রোফাইল (নাম/ভাষা/টাইমজোন)।
- অ্যাডমিন `/admin/chat` চ্যাট অ্যানালিটিক্স (`providers.manage`)। `user:verify`, `chat:finalize-stale` (৫ মিনিট) কমান্ড।
- **ডিপ্লয় কিট `deploy/`**: `install-vps.sh`, `update.sh`, `healthcheck.sh`, `backup.sh`, `enable-remote.sh`, `make-cpanel-bundle.sh`, nginx/horizon টেমপ্লেট, `README.md`, `CPANEL.md`।
### Fixed
- `config:cache`-এ `env()` ব্যবহারের সমস্যা (`config/admin_db.php`)।
### Tests
- DB ১৭২ পাস (নতুন চ্যাট ৮ + PHP SQL স্ট্রিং আসল PG-তে)। Python ৭১ + ruff/mypy। বিশুদ্ধ PHP (shim): ৪৩ চেক, ChatService-এর ৪টি মিউটেশন ধরা পড়ে।
- Pest `ChatTest` অচালিত (Laravel এই পরিবেশে চলে না)।
### Notes
- ইনস্টলার, nginx, certbot, Docker ও cPanel পথ এখানে চালিয়ে দেখা যায়নি।

## [0.7.0] — 2026-11-02 — Phase 5 (Credits & Usage Engine)
### Added
- DB (`phase5.up/down.sql`): `wallets`, append-only `credit_transactions` (ledger), `credit_lots` (মেয়াদসহ), `credit_reservations`, append-only `usage_records`, `credit_alerts`, `credit_packages`, append-only `provider_cost_records`। টাকা/ক্রেডিট নড়ে **শুধু** SECURITY DEFINER ফাংশনে: `credit_grant/adjust/reserve/settle/release/release_stale/expire_lots/wallet_configure/reconcile`। অ্যাপ/অ্যাডমিন লগইনের এই টেবিলগুলোতে শুধু SELECT।
- নিয়ম: প্রতিটি ফাংশনে idempotency key বাধ্যতামূলক (একই key = একই ফল, ভিন্ন তথ্য = `idempotency_conflict`); wallet লক আগে; reservation ছাড়িয়ে খরচ শুধু ফ্রি ক্রেডিট থেকে, বাকিটা `shortfall`; মেয়াদ-আগে-শেষ lot আগে খরচ; মাসিক খরচ-সীমা; কম-ব্যালেন্স সতর্কতা একবারই (ব্যালেন্স ফিরলে পুনঃসক্রিয়)।
- Laravel: `CreditService` (একমাত্র দরজা), `CreditMath` (দশমিক স্ট্রিং, float নিষিদ্ধ), `AdminCredits`, workspace তৈরিতে স্বাগত ক্রেডিট (`CREDITS_SIGNUP_GRANT`, ডিফল্ট ১০০; ব্যর্থ হলে রেজিস্ট্রেশন আটকায় না, idempotent)।
- কমান্ড + শিডিউল: `credits:release-stale` (প্রতি মিনিট), `credits:expire` (ঘণ্টায়), `credits:notify-low-balance` (১০ মিনিট), `credits:reconcile` (দৈনিক ০৩:১০; অমিল হলে FAIL + audit + critical লগ)।
- কাস্টমার `/app/credits`: ব্যালেন্স/আটকানো/মাসিক খরচ, দিন-মডেল-এজেন্ট-বিভাগ অনুযায়ী চার্ট (৭/৩০/৯০ দিন), লেনদেনের ইতিহাস (ফিল্টার+পেজিনেশন), সতর্কতা ও খরচ-সীমা সেটিংস (`billing.view` দেখতে, `workspace.manage` বদলাতে)।
- অ্যাডমিন (`credits.manage`; Finance Admin-এ ডিফল্ট): `/admin/credits` — reconciliation রিপোর্ট, প্যাকেজ CRUD (নতুন = hidden), provider খরচ রেকর্ড (insert-only); workspace পেজে ওয়ালেট + সমন্বয়/বোনাস ফর্ম (কারণ বাধ্যতামূলক, ডাবল-সাবমিট নিরাপদ, অডিটে)।
### Tests
- DB ১৬৪ (নতুন ৪৬: কনকারেন্সি ১০০ রিকোয়েস্ট ঠিক-ঠিক ব্যালেন্স, ডুপ্লিকেট idempotency, append-only, র‍্যান্ডম প্রপার্টি টেস্ট, PHP সার্ভিসের আসল SQL স্ট্রিং আসল PostgreSQL-এ); ৭টি মিউটেশন সবকটা ধরা পড়ে।
- Pest (অচালিত): `CreditMathTest`, `CreditTest`, `AdminCreditTest`।
### Notes
- DB টেস্ট সুইটে PostgreSQL `max_connections >= 150` লাগে (CI ও docker-compose আপডেট করা হয়েছে)।
- পেমেন্ট এখনো নেই: `credit_grant('purchase'|'refund')` শুধু ভবিষ্যৎ পেমেন্ট ফ্লো যাচাইয়ের পরে ডাকবে।

## [0.6.0] — 2026-11-02 — Phase 4b (Provider ম্যানেজার UI + কাস্টমার মডেল) → Phase 4 কোড সম্পূর্ণ
### Added
- অ্যাডমিন (`providers.manage`, Provider Manager রোল): provider CRUD (সবসময় disabled অবস্থায় শুরু), API key যোগ/রোটেট/বাতিল (শো-ওয়ান্স নয়, কখনো ফেরত দেখানো হয় না), connection **Test** বোতাম, মডেল + capability, insert-only মূল্য, প্ল্যান-ভিত্তিক অ্যাক্সেস, fallback, রাউটিং নিয়ম, হেলথ টেবিল (শেষ চেক/২৪ঘ আপটাইম/গড় সাড়া)।
- নিরাপত্তা-গার্ড: key ছাড়া provider সক্রিয় হয় না; মূল্য ছাড়া (ফ্রি-টিয়ার ছাড়া) মডেল সক্রিয় হয় না; URL/adapter বদলালে key-reuse নিশ্চিতকরণ; private ঠিকানা শুধু Ollama/compatible।
- `ai:health-check` (প্রতি ৫ মিনিট): ৩ বার ফেল → degraded, সফল → active; disabled কখনো বদলায় না।
- কাস্টমার `/app/models`: প্ল্যানে ব্যবহারযোগ্য মডেল, তুলনা (ক্রেডিট-গুণক, প্রোভাইডারের আসল মূল্য নয়), ডিফল্ট মডেল ও রাউটিং মোড।
- `ModelRouter` (cheapest/fastest/best_quality/balanced/manual + অ্যাডমিন rule + capability ফিল্টার + fallback chain) — আসল PHP দিয়ে ২৯ চেক পাস।
- DB: `provider_secret_for_test()` (শুধু অ্যাডমিন গ্রুপ)। `apps/ai-engine/scripts/provider_smoke.py` — আসল key দিয়ে এক কমান্ডে যাচাই।
### Tests
- Python ৭১, DB ১১৮, ModelRouter/HealthPolicy ২৯ চেক (প্লেইন PHP-তে চালানো); Pest: `ModelRouterTest`, `AdminProviderTest` (অচালিত)।

## [0.5.0] — 2026-11-02 — Phase 4a (AI provider কোর; অ্যাডমিন/কাস্টমার UI পরের ধাপ 4b)
### Added
- Python adapters: OpenAI, Anthropic, Gemini, OpenRouter, Groq, Ollama, OpenAI-compatible — complete + stream + connection check; endpoints `/internal/v1/providers/test`, `/ai/complete`, `/ai/stream` (signed)।
- নিরাপত্তা: credential শুধু রিকোয়েস্ট-বডিতে আসে (সংরক্ষণ/লগ/রেসপন্সে নেই); upstream এরর থেকে key redact; FastAPI 422 আর input echo করে না; redirect অনুসরণ নেই; SSRF গার্ড (private/metadata ঠিকানা বাদ)।
- DB (`phase4.up/down.sql`): `ai_providers` (fallback-cycle ট্রিগার), `provider_credentials` (অ্যাপ রোল secret কলাম পড়তে পারে না; একমাত্র পথ `provider_secret_for_call()`), `ai_models`, `model_capabilities`, `model_pricing` (append-only ইতিহাস), `model_access_rules`, `routing_rules`, `provider_health_checks`, `tenant_model_preferences` (RLS), ফাংশন `tenant_plan_id`, `available_models` (rule > free_tier > deny)।
- Laravel: `CredentialVault` (AES-256-GCM, আলাদা ভার্সনড কী, রোটেশন), `ProviderGateway`, `DbProviderStore`, `AiEngineClient::post`; Pest `CredentialVaultTest`, `ProviderGatewayTest`।
### Tests
- Python ৭১; DB ১১৭ (মিউটেশন দিয়ে যাচাই: secret কলাম খোলা, cycle ট্রিগার, fail-open, pricing mutable, function public — সবকটা ধরা পড়ে)।
### Known limitations
- আসল provider-এ connection test এখনো হয়নি (key পেলে 4b-তে); Laravel অংশ অচালিত।
- SSRF গার্ড literal IP/hostname দেখে; DNS rebinding কভার করে না।

## [0.4.0] — 2026-11-02 — Phase 3 (কোড সম্পূর্ণ, Laravel অংশ অচালিত)
### Added
- Super Admin Panel (`/admin`): ড্যাশবোর্ড (বাস্তব সংখ্যা), ইউজার/Workspace CRUD (suspend, restore, soft-delete, সেশন/টোকেন বাতিল, রিসেট লিংক), প্ল্যাটফর্ম রোল ও পারমিশন ম্যাট্রিক্স, প্ল্যান (মূল্য আলাদা `plan_prices` টেবিলে; মূল্য সিড করা নেই), ফিচার ক্যাটালগ ও সীমা, tenant override (কারণ বাধ্যতামূলক), ফিচার ফ্ল্যাগ, সিস্টেম সেটিংস (ব্র্যান্ডিং, সাইনআপ সুইচ, মেইনটেনেন্স মোড), অডিট লগ ভিউয়ার, গ্লোবাল সার্চ।
- আলাদা DB লগইন `jarvis_admin_login` (connection `admin`); `AdminAccess` গেট: প্ল্যাটফর্ম-অ্যাডমিন + সক্রিয় + নিশ্চিত 2FA + ৩০ মিনিট idle-এ পাসওয়ার্ড পুনঃনিশ্চিতকরণ + `admin.access`।
- `admin:promote {email}` কমান্ড (প্রথম Super Admin)।
- DB: `plans, plan_prices, features, plan_features, tenant_feature_overrides, subscriptions, feature_flags, system_settings`, `effective_feature()` (fail-closed); সিড: ৫ প্ল্যান, ১৮ ফিচার।
- `Entitlements`-এ সদস্য-সীমা প্রয়োগ (আমন্ত্রণে); সাইনআপ বন্ধ থাকলে রেজিস্ট্রেশন 403।
- Pest `AdminTest` (১২ টেস্ট); DB স্যুট ১০০ টেস্ট।
### Known limitations
- Laravel/Blade/Pest/Larastan/Pint এখানে চালানো যায়নি; শুধু `php -l`, রাউট↔মেথড, রাউট নাম, Blade ডিরেক্টিভ ব্যালান্স ও পারমিশন নাম ক্রস-চেক হয়েছে।
- `AdminTest`-এর দ্বিতীয় অংশ ডেটা কমিট করে — শুধু আলাদা টেস্ট ডাটাবেসে চালান।

## [0.3.0] — 2026-11-02 — Phase 2c (কোড সম্পূর্ণ)
### Added
- Fortify UI ও কনফিগ, spatie roles (tenant প্রতি), Sanctum API `/api/v1/auth/*`, সেশন/টোকেন পেজ, workspace সুইচার, সদস্য ও আমন্ত্রণ UI, rate limiter, লগইন লগ, `EnsureActiveWorkspace`, seeders, hardened `bootstrap-web.sh`, `make db-provision`।
- Pest ফিচার টেস্ট (`AuthFlowTest`); DB স্যুট ৫৬ টেস্ট।
### Changed
- `db-provision.sh` আগে-থেকে-থাকা DB/স্কিমার মালিকানা `jarvis_owner`-কে হস্তান্তর করে।
### Known limitations
- Laravel কোড এখনো চালিয়ে যাচাই হয়নি (Packagist বন্ধ)।

## [0.2.1] — 2026-11-02 — Phase 2c-1
### Added
- `CreateNewUser`, `CreateWorkspace`, `ManageInvitations`, `InvitationException`; Pest ফিচার টেস্ট; `tests/db/test_app_contract.py` (৫৫ DB টেস্ট পাস)।

## [0.2.0] — 2026-11-02 — Phase 2a/2b (আংশিক)

### Added
- PostgreSQL স্কিমা + RLS (`database/sql/`), invitation ফাংশন, append-only ও admin-escalation trigger; ৫৩টা DB টেস্ট (`tests/db`), CI `database` জব।
- Laravel tenancy স্তর (context, scope, middleware, queue propagation, `tenancy:check`), `User` মডেল, `scripts/db-provision.sh`।

### Changed
- 2FA কলাম `users`-এ (Fortify-র সাথে সামঞ্জস্য); DATABASE_SCHEMA §6 দেখুন।

### Known limitations
- Laravel কোড অচালিত (Packagist বন্ধ); ২সি (UI, spatie, Sanctum, rate limit) বাকি।

## [0.1.0] — 2026-11-02 — Phase 1 (আংশিক)

### Added
- `apps/ai-engine` (FastAPI): signed-request auth (HMAC + JWT 60s + nonce replay guard), ২৫টা টেস্ট।
- `apps/web-overlay`: Laravel signer/client/command/config/seeder/Pest টেস্ট; `scripts/bootstrap-web.sh`।
- `infra/` Docker Compose, Dockerfile; `Makefile`; GitHub Actions CI; `.env.example`।

### Known limitations
- Laravel অ্যাপ এই পরিবেশে বানানো যায়নি (Packagist বন্ধ); Docker daemon নেই।

## [0.0.0] — 2026-11-02 — Phase 0: Planning & Documentation

### Added
- README, PROJECT_PLAN (৩১ ফেজ + সাব-ফেজ ও চেকলিস্ট), ARCHITECTURE (প্রযুক্তি সিদ্ধান্ত, সার্ভিস আর্কিটেকচার, ৮টা flow ডায়াগ্রাম, ১৫টা ADR, ঝুঁকি, credential তালিকা), DATABASE_SCHEMA (২০টা মডিউল, ER ডায়াগ্রাম, কনভেনশন, RLS), API_DOCUMENTATION (কনভেনশন, endpoint ম্যাপ, internal API, webhook), SECURITY (threat model, approval policy, domain rules), ENVIRONMENT_SETUP, DEPLOYMENT_GUIDE, TESTING_GUIDE, DEVELOPMENT_STATUS, PROJECT_HANDOVER।
- `docs/diagrams/`-এ সব Mermaid ডায়াগ্রামের সোর্স।
- স্কোপে যোগ: Mobile app, Telegram/WhatsApp চ্যানেল, Website Lead Extractor + আউটরিচ, AI E-commerce Manager, Accounting/VAT/Audit, Google Ads Manager, Video Editor।
- ChatGPT ব্লুপ্রিন্ট পর্যালোচনার পর যোগ: Productivity Suite + Slack/Discord (১৯.২), Coding Assistant (২১, ২৫), লোকাল SQLite ও অফলাইন সিঙ্ক (২৫), AI Character & Avatar (২৫.২), System Monitor ও বাড়তি ডেস্কটপ কন্ট্রোল (২৬), Developer Platform + Plugin Marketplace (২৭.২), ঐচ্ছিক Face Auth (১৯), ঐচ্ছিক World Monitor (৩১+)।

### Verified
- ১২টা Mermaid ডায়াগ্রাম mermaid@11 পার্সার দিয়ে যাচাই: ১২/১২ OK।
- ZIP-এ secret স্ক্যান: কোনো secret পাওয়া যায়নি।

### Decisions
- Web = Laravel 13 + Livewire 4 (কোনো আলাদা React web app নয়); single DB + PostgreSQL RLS tenancy; AI কল শুধু Python-এ; append-only credit ledger; ULID; VPS-only; ব্র্যান্ড নাম কনফিগযোগ্য।
