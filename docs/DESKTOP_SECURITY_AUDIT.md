# CAN AI Desktop — security audit summary (self-audit, not independent)

Scope: the desktop window (`apps/pc-agent/jarvis_agent/gui.py`, `ui/`), the agent engine, the new Laravel desktop API and token rotation. Method: reading the code and writing tests that try to break it. **No external penetration test, no fuzzing campaign, no Windows-specific review was done.**

## Trust model in one paragraph
The Laravel server and the person's phone decide what may run; the PC agent re-checks everything locally (folders, apps, commands, hosts, kinds) and refuses anything its own rules forbid. The window is just a local face: it cannot approve anything. Text from files, web pages and window titles is data and can never become an instruction.

## Controls and evidence
| Threat | Control | Evidence |
|---|---|---|
| Another web page drives the local window | 127.0.0.1 only; random per-run token in a header (not a cookie); Host check (DNS rebinding); Origin check on writes; CSP `script-src 'self'`, no inline script/style, no `innerHTML`; body limits (8 KB, 64 KB, 2.5 MB voice only) | `tests/test_gui.py` (token, host, origin, bad bodies), `tests/test_desktop_ui.py`, e2e |
| Device token leaks | token never in `/api/state` or any reply; config file 0600; cloud calls go through a local proxy that adds the token | `test_state_never_contains_the_device_token`, `test_cloud_proxy_forwards…never_leaks_it`, `test_window_rotate_endpoint_and_token_never_returned` |
| Stolen/old token | rotation (agent-made token, old dies instantly, revoked devices cannot rotate); revoke on website/phone ends the agent loop | `tests/db/test_pc_rotation.py`, `tests/test_rotation.py`, `test_a_really_revoked_device_still_ends_the_loop` |
| A model/page/file tells the agent what to do | plans are fixed lists; outputs from outside are tainted, scanned for instructions, and need per-step approval to flow into actions | `test_taskengine.py::test_injection_*`, `::test_clean_data_flows_but_needs_step_approval…`, `window_info` is tainted (`test_phase_d.py`) |
| Running something nobody approved | high-risk steps need an exact, single-use, expiring, device-bound, HMAC-signed approval; plans are signed by the database and cannot be replayed | `test_taskengine.py` (approvals), `tests/test_serverplan.py` (tamper, swap, replay, wrong device, expiry) |
| The window/voice approves things | no approve route in the desktop API (static test), no approve button in the window (e2e), a spoken "yes" is never an approval | `tests/Unit/DesktopApiRoutesTest.php`, `gui.e2e.cjs`, `VOICE_CAN_APPROVE=false` in `voice-session.js` |
| Escaping the allowed folders | resolved real paths, symlink and `..` refusal, drive roots refused | `tests/test_guard.py` |
| Typing/clicking into the wrong window or a password prompt | foreground app allowlist, sensitive-window refusal (password/bank/security), click bounds, key allowlist with blocked combos, 30 actions/min; typed text never logged | `test_devices_phase6.py`, `test_phase_d.py` |
| Runaway or unwanted task | emergency stop (window, CLI, file flag, server) kills child processes; pause/continue; 30-min pause give-up | `test_taskengine.py::test_stop_*`, `test_watchdog.py`, `test_phase_d.py` |
| Hidden microphone | mic opens only after a click/key or the consent-gated hands-free switch; indicator always visible; wake word off by default | `test_desktop_ui.py::test_microphone_is_never_opened_without_a_user_action`, `voice.e2e.cjs` |
| Tampered audit trail | hash-chained audit log, verified in the window | `test_audit_chain_detects_tampering_and_redacts` |
| One user reads another's data via the new API | every desktop call runs as the device's owner through RLS-protected services | **code review + static test only — not exercised against a live database** |

## Phase 13 additions (0.146.0)
| Threat | Control | Evidence |
|---|---|---|
| A task runs a shell/interpreter and so anything at all | shells, interpreters, script hosts, system tools can never be allowed or run | `test_security_hardening.py::test_shells_interpreters…` (28 names) |
| Planted program in the current folder | PATH lookup only in absolute folders | `…planted_in_the_current_folder…` |
| Windows path tricks (device names, `:stream`, `\\?\`, wildcards) | refused on every platform | `…dangerous_paths_are_refused` (19) |
| A command floods memory | 1 MB output cap, process stopped | `…floods_output…` |
| Secrets leak into logs/audit/crash reports | broader redaction (JSON, JWT, cloud keys, URL passwords, Basic/Bearer, Bengali) | `…secrets_are_redacted` (19) + clean-text test; token absent from agent log and crash report |
| Hidden/obfuscated injection in pages and files | text normalised (invisible chars, NFKC) before scanning | `…injection_attempts_are_flagged_even_when_disguised` (16) |
| Typing into money/secret screens | wider sensitive-window list | `…money_and_secret_windows…` (15) |
| Token forwarded by a redirect | redirects never followed | `…redirect_never_carries_the_device_token…` |
| Token copied from disk | DPAPI at rest on Windows; other-user copy = unpaired | `…token_is_encrypted…` etc. (**fake backend only**) |
| Junk tool input | fuzz of all tool kinds | `…fuzzing_every_tool…` |

## Known weaknesses and residual risks (read these)
1. **The new Laravel `DesktopController` has never run.** Its authorization story (owner from the device token, RLS) is by construction, not by test. A bug there could expose or reject data. Test with two workspaces before trusting it.
2. **Windows behaviour is untested**: foreground-window detection, `pyautogui`/`mss`, process kill on Windows. The sensitive-window word list is a guard rail, not a guarantee.
3. **Unsigned installer/exe**: SmartScreen warnings; an attacker who can replace the installer can replace everything. Sign before distributing.
4. **Local compromise = game over for the agent**: malware running as the same Windows user can read `config.json` (token) and the local window token. The agent is not a sandbox.
5. **Browser speech (Chrome/Edge) sends microphone audio to the browser vendor.** Wake-word mode therefore streams audio to a third party while on; consent text says so; the cloud path uses your own providers.
6. **Voice endpoints cost credits**: abusable by a stolen device token until revoked; rate limits are 30/min per route.
7. The AI planner can only draft file-type plans; GUI automation plans are written by a person/template. If you later let the model plan mouse/keyboard steps, redo this review.
8. The local audit chain is copied to the server (0.143.0) and the two are compared (`jarvis-agent` run loop every 5 min when the file changed, and the window's "Sync with server" button). This detects a local file rewritten/truncated AFTER an upload; it cannot prove what an already-compromised agent wrote before uploading. The server stores rows append-only and recomputes every hash. The website does not yet show the server chain to the owner (only the agent reports the comparison).
9. No independent review of the SQL functions beyond the test suites (`tests/db`).
10. Crash reports (0.145.0) are local only, private (0600), redacted, hold no config/tokens/task data, and are never uploaded; the release workflow can sign only from repository secrets, creates only DRAFT releases and removes the signing material from the runner (all unverified until the workflow runs).
11. Phase 13 lists (dangerous programs, sensitive-window words, injection phrases) are guard rails: a determined attacker can find a gap. DPAPI protects against copied files/other accounts, not malware running as the same Windows user. The DPAPI and Windows-path code has never run on Windows. There is still no secure auto-update mechanism (updates are manual installers; verify the SHA-256 from the release).
