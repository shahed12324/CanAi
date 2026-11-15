# CAN AI Desktop — what a person must check on a real Windows PC / phone

Nothing below has been run by the authors (no Windows machine, microphone or phone was available). Do it in order; write PASS/FAIL and paste the exact messages of every FAIL. Agent-only checks (screen/mouse/keyboard/stop) are also in `WINDOWS_VERIFICATION.md`; this file adds the desktop window, voice, character, pause, rotation and the 26-test mapping (`DESKTOP_TEST_MATRIX.md`).

Prepare: Windows 10/11, Chrome or Edge installed, a **test** folder `C:\CANAI-Test`, Notepad. Use a throw-away workspace on the server, not production data.

## A. Install and start (tests 1, 25, 26)
1. Unzip `CANAI-Desktop-Windows.zip` → run `1-INSTALL.bat` (needs Python 3.10+). Expect "Installed". FAIL → paste the messages.
2. Run `2-START.bat`. Expect the window at `http://127.0.0.1:<port>/#<token>` with 14 menu items and no console errors.
3. Close the window; run `2-START.bat` again. Expect the same settings (theme/language) and pairing kept. (restart recovery)
4. (Installer path) GitHub → Actions → `desktop-release` → Run workflow. Expect a `windows-installer` artifact; install it; expect a tray icon and the same window. Windows SmartScreen will warn: the installer is **unsigned**.
5. In PowerShell: `.venv\Scripts\jarvis-agent selfcheck` → every line PASS (`mss`/`pyautogui` lines included).

## B. Pairing, modes, rotation (tests 2, 3, 4, 23)
1. Web app → My computer → "Pair" → code. In the window enter server + code. Expect "paired" and the profile name on Home.
2. Switch Companion → ask the window to do a file task from the website. Expect: nothing runs. Back to Agent.
3. Pairing screen → "Rotate device token". Expect "New token active". Home still shows connected; the Computer screen keeps working for 1 minute; on the website the device is still online.
4. Website → remove the device. Expect within ~10 s the window says the server no longer accepts this computer.
5. Server side (first call ever against real Laravel!): with a paired device open Chat in the window, send "hello". FAIL means the new `DesktopController` needs a fix — paste the Laravel log line (`storage/logs/laravel.log`).
6. Agents / Workflows / Memory / Models list / credits on Home: each should load. Note which ones error.

## C. Computer control (tests 8–11, 13, 15, 17–20)
Add `C:\CANAI-Test` under Rules → folders; add `notepad` under apps; switch on `open_app`, `input_control`, `screenshot`, `window_info`, `wait_window` (high-risk kinds need phone approval).
1. From the website make a plan: open Notepad → wait for the Notepad window → type `hello` → screenshot to `C:\CANAI-Test\proof.png`. Approve high-risk steps on the phone. Expect each step ✓ in "Running now", the text in Notepad, a non-empty `proof.png` that shows Notepad.
2. Start the same plan but press **Pause** after step 1. Expect the next step waits; press Continue → finishes.
3. Start it and press **Emergency stop** mid-way (window button, then website button, then phone). Expect stopping within ~3 s; Resume works.
4. Put a window titled "Sign in …" in front and try a screenshot step. Expect refusal (sensitive window).
5. Try a click outside the Notepad window, `alt+f4`, and a path outside `C:\CANAI-Test`. Expect refusal each time.

## D. Voice and character (tests 5, 6, 7) — Chrome/Edge window or the Tauri WebView2 window
1. Home: Microphone → Test microphone → allow. Expect the level bar to move when you speak.
2. Tap 🎤 in Chat and say a short English sentence, then a Bengali sentence. Expect the text to appear as your message and a reply. Write down how many words were wrong. In WebView2 the browser speech engine may be **missing**: then switch Speech-to-text to "Cloud" (needs the server `voice/listen`).
3. Turn on "Speak replies" → expect audio (browser voice, then try Cloud). Check Bengali voice quality honestly.
4. Barge-in: while it speaks, press the push-to-talk key (F9) → speech stops.
5. Wake word: Allowed → tick consent → "Turn on hands-free" → say "Hey CAN AI, what time is it". Expect it to react once; ordinary talk must not trigger it. Turn off; confirm the mic indicator goes off.
6. Mute: with mute on, the mic never opens. Watch the character: idle → listening → thinking → speaking → idle.

## E. Mobile (test 22) — needs the real phone app against your server
1. Create a plan with a high-risk step on the website/desktop (`plan`). Phone: Computer → approve with PIN. Expect the agent to run the step within ~5 s; the window "Approvals" screen lists it while pending (read-only).
2. Let a pending approval expire (wait past its expiry) → approving must be refused. Approve once, try to approve again → refused (replay).

## E2. Screen understanding (Phase 1, needs Tesseract installed)
1. `jarvis-agent selfcheck` → "text recognition (Tesseract)", "monitors and scaling" should PASS; note the scale per monitor (100 %/125 %/150 %).
2. Rules: allow the app `notepad`, enable kinds `screen_read`, `screen_find`, `ui_click`. Open Notepad with a few words, bring it to the front. Plan `screen_read` → approve on the website → expect the words, window title and a confidence number.
3. `screen_find` with text of a menu item (e.g. `File`). Expect a match with coordinates. Then `ui_click` on it with a verify `element_present` for another menu text. Expect the menu to open.
4. Repeat on a **second monitor** and on a monitor with **150 % scaling**: the click must land on the same item. Move the window to a monitor left of the main one (negative coordinates).
5. Try an ambiguous word that appears twice → expect a refusal that asks for `index`. Try a word that is not there → refusal.
6. Put a window titled "Sign in" in front → all four kinds must refuse. Put an app that is not allowed in front → refuse.
7. If `uiautomation` is installed: `screen_find` with role `button` on a dialog (e.g. Notepad "Save As" → `Save`) should report source `accessibility`. Write down what happens without it.

## E3. Browser automation (Phase 2; needs `pip install playwright` and Chrome or Edge)
1. Allow one harmless site you control (host list), enable `browser_run`. Create a plan step `browser_run` with a form on that site (fill, click, `expect_text`). Approve on the website/phone → the form should be submitted and the result line "verified: text is on the page" appear.
2. Point a step at a site that is NOT allowed → refused before anything opens. Use a page that embeds an outside image/script → result says N requests were blocked.
3. Try a sign-in page and a "Pay now" button → both must be refused. Note any site that blocks the automated browser.
4. Confirm Chrome windows do not stay open after the run (Task Manager).

## E4. Planner (Phase 3; needs a connected AI provider on your server)
1. Put a small `sales.csv` in an allowed folder. On the website choose the computer → "Tell the computer what you want": "Make an Excel report of sales.csv with totals per region". Expect a draft with a `make_report` step; approve; open the .xlsx and check a sum by hand.
2. Ask for something forbidden ("run this command", "pay my bill"). Expect a refusal or an invalid draft, never a plan.
3. With Notepad allowed and `ui_click`/`input_control` not denied: "Open Notepad, type hello, save with ctrl+s". Each high-risk step must ask for approval on the phone. Write down how often the draft is wrong - this is the real quality test of your model.

## F. Report back
For each numbered item: PASS / FAIL / NOT RUN + the first error line. Send `jarvis-agent selfcheck` output and `%USERPROFILE%\.jarvis-agent\audit.jsonl` (it redacts typed text).

## E5. Streaming chat (Phase 4; needs server updated)
1. Send a long question: text must appear piece by piece.
2. Press ■ Stop mid-answer: text stays, no error; check the credit ledger shows ONE charge for the partial answer.
3. Against a server without `chat/stream`: the answer still arrives (fallback).

## E6. Voice in the Tauri/WebView2 window (Phase 5)
1. Settings -> Voice -> **Voice diagnostics**: paste the text into your report (shows whether the speech engine is missing/failing and which plan was chosen).
2. Tap 🎤 and speak. If the speech engine fails, a notice says cloud voice is used (credits); the text must still arrive. Press again: no first failure/delay this time.
3. Unplug the microphone / choose another in the list: expect a clear message, not silence.
4. First use may show a Windows/WebView2 microphone permission prompt: write down exactly what appeared; if none appeared and the mic is blocked, check Windows Settings -> Privacy -> Microphone.

## E7. Character lip sync and floating window (Phase 6)
1. Cloud voice on, send a message: the mouth must open/close with the audible voice and stop when it stops. Write down whether it looks in time.
2. Browser voice: mouth follows words. Bengali voice: note honestly how it looks.
3. Settings: tick Compact -> the window shrinks to a narrow panel within ~2 s; untick -> returns to full size. Tick Always on top -> window stays above other apps (try a full-screen browser, a second monitor).
4. Close to tray and reopen: size/position sane.

## E8. Real control validation (Phase 7) - do this FIRST on Windows
1. Install Tesseract OCR (UB-Mannheim build) if you want the screen-reading checks; run `1-INSTALL.bat`.
2. Double-click `3-VALIDATE.bat` (or `jarvis-agent validate-windows --yes`). Hands off mouse/keyboard for ~20 s.
3. Paste the whole output and the `validation-*.json` file. Every FAIL needs the exact line. SKIP lines say why (window not in front -> run again; no Tesseract).
4. Repeat on: a 125%/150% display scale, a second monitor (move the test window there first), Windows 10 and 11 if you have both.
5. Still by hand (the tool cannot): corner fail-safe, Notepad typing, UAC prompt behaviour, a real website.

## E9. Server side after deploy (Phase 8)
1. Run `update.sh`, then `php artisan migrate` (expect no error; `php artisan migrate:rollback --step=1` then `migrate` again must also work on a COPY/staging DB first).
2. In the window set the character personality to Playful, ask the same question as with Calm: the tone should differ a little (write down honestly if not).
3. Turn on "Speak replies" and use the microphone: the answer should be short plain sentences (no lists/markdown read out).

## E10. Phone approval and push (Phase 9) - needs the built phone app
1. Server: set `MOBILE_PUSH=expo` (and the Expo access token if you use one); the phone app must be an EAS build with a project id, push permission allowed.
2. From the website ask the computer for a plan / an action that needs approval. Expect a push "Your computer is waiting for approval" within a few seconds (write the real delay). Tap it: My Computer opens with the item.
3. Keep the app open on My Computer, create another request on the website: the list should update without pulling down (push or within 60 s).
4. Deny push permission (or use a phone without it) and repeat: the list must still update within ~12 s (polling fallback).
5. Turn on airplane mode for a minute and back: the list recovers by itself, no error loop.
6. Approve a high-risk step: PIN is still demanded. A push alone never approves anything.

## E11. Audit sync (Phase 10) - after the server is updated and migrated
1. Pair, run a small task. In the window: Agent view -> Local tasks -> **Sync with server**. Expect "matches the server copy (N/N)".
2. Run another task, press again: only the new rows go (N grows).
3. Close the agent, open `%USERPROFILE%\.jarvis-agent\audit.jsonl`, change one word, save, start again. The chain shows BROKEN and sync says "local file is broken - nothing uploaded".
4. Restore the file from a backup that is shorter (delete the last 2 lines instead): chain looks intact but sync must say "does NOT match the server copy".
5. Take the PC offline and press the button: "server not reachable", no crash.

## E12. English UI, Bengali voice (Phase 11)
1. Open the window: every menu, button and message is English; Settings has no language switch.
2. Settings -> Voice language -> **বাংলা**. Press the mic and say a short Bengali sentence: it is recognised and placed in the chat box (needs a working Bengali speech engine on that PC; if recognition is missing the window tells you in English).
3. Turn on "Speak replies aloud" with Bengali selected and send a Bengali message: the reply is spoken (needs a Bengali voice installed in Windows; otherwise it falls back and says so).
4. Say "থামো" while it speaks: speech stops without any server call.
5. Trigger a refusal (e.g. add `/` as an allowed folder): the message is in English.

## E13. Installer and crash reports (Phase 12) - after the GitHub workflow produced `windows-installer`
1. Read `SIGNING-STATUS.txt`. Compare the installer's SHA-256 with `SHA256SUMS.txt` (`Get-FileHash file.exe`).
2. Run the NSIS installer. Unsigned: SmartScreen warns ("More info -> Run anyway") - expected. Signed: right-click the file -> Properties -> Digital Signatures shows your certificate and a timestamp.
3. Start the app from the Start menu: the tray tooltip and window title say "CAN AI", menu items "Open" and "Quit (and stop the agent)".
4. Install a newer build over it: settings (`%USERPROFILE%\.jarvis-agent`) are kept. Uninstall: the app and tray entry are gone; the settings folder stays (delete it yourself if you want).
5. Crash report: in a terminal run the installed `jarvis-agent.exe crash-report` -> "No crash reports". Then provoke one (e.g. end the agent from Task Manager does NOT count; instead run `jarvis-agent.exe task nofile.json` - a normal error - and a real fault only if you can cause one). Open Settings -> Crash reports; Details must show no tokens; Delete all must empty it.
6. Check `%USERPROFILE%\.jarvis-agent\crashes\` holds nothing but small .json files and that no network traffic is made for reports.

## E14. Security hardening on Windows (Phase 13)
1. Pair the PC, then open `%USERPROFILE%\.jarvis-agent\config.json`: `token` starts with `dpapi1:` (not a 64-character hex string). `jarvis-agent status` says "Paired: yes".
2. Copy `config.json` to another Windows account (or PC) and run `jarvis-agent status` there: it says the token cannot be opened and to pair again - no crash.
3. `jarvis-agent policy add-command powershell` (also cmd, python, curl) is refused with the "can never be allowed" message.
4. With a read_file / write_file task try the paths `C:\allowed\CON`, `C:\allowed\a.txt:hidden`, `C:\allowed\a*.txt`, `\\?\C:\allowed\a.txt`: every one is refused; a normal file in the allowed folder still works.
5. Add a harmless allowed program that prints without end (for example `ping -t localhost` if you allow `ping`) and run it as a task: the task ends with the "output limit" note within seconds, not after 30 s of memory growth.
6. Open a window titled "PayPal"/"Binance"/"OTP" and try a type/click task: refused as sensitive.
7. Antivirus: confirm the agent exe is not quarantined (no real result known yet).

## E15. Run the real-device suite (Phase 14) - this is what turns "NOT RUN" into evidence
On the Windows PC (desktop session, normal user, repository checked out, `pip install ./apps/pc-agent pytest`):
1. `set JARVIS_REAL_DEVICE=1` then `python -m pytest apps\pc-agent\tests\real -v` (do not touch mouse/keyboard for ~20 s while the mouse test runs).
2. After installing the installer: also `set JARVIS_INSTALLED_AGENT=<path to the installed jarvis-agent.exe>` and re-run `tests\real\test_real_install.py`; add `set JARVIS_EXPECT_SIGNED=1` once you have a signing certificate.
3. Live model: see the command in `apps/ai-engine/tests/live/test_live_planner.py` (costs a few cents).
4. Copy `%USERPROFILE%\.jarvis-agent\real-results\*.json` and `validation-*.json` to one folder and run `python scripts/test-report.py --real <folder>`: the Real device column then shows PASS/FAIL with date and platform.
5. Areas 16 (microphone) and 18 (phone + PC + server): do E5-E12 by hand and write what you saw in your own notes; the script will keep saying MANUAL.
