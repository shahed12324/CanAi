# Windows verification checklist (nothing below has been run by the authors)

The sandbox that built this project has no Windows, no display, no microphone and no real phone. Everything marked "code + fake-device tests" in `CAN_AI_GAP_AUDIT.md`
is only proven against fakes. This list is how a person on a real Windows 10/11 PC turns each item into evidence. **Do not mark a row done without pasting the output.**

## 0. Build and install
1. `cd apps/pc-agent; powershell -ExecutionPolicy Bypass -File packaging\build.ps1` → expect `OK: dist\jarvis-agent.exe starts`.
2. Optional installer: compile `packaging\installer.iss` with Inno Setup 6 → install → "JARVIS ONE Agent" in the Start menu.
3. `jarvis-agent selfcheck` → paste the whole output. Expected on a normal desktop: every line PASS (library lines FAIL until `pip install mss pyautogui` / included by build.ps1).

## 1. Pairing and rules
`jarvis-agent pair` with a code from /app/computer → status shows the device online on the website. Add one allowed folder, `notepad` as an app, one host.

## 2. Screen, mouse, keyboard, browser (each needs the kind switched on in the local rules)
| # | Do | Expected | Result |
|---|----|----------|--------|
| 1 | Request `screenshot` to `<folder>\s.png`, approve on phone (PIN) | PNG saved, opens in an image viewer | |
| 2 | Open a window titled "Sign in" and request a screenshot | refused: sensitive window, no file | |
| 3 | Focus Notepad, `input_control type "hello"` | text appears in Notepad only | |
| 4 | Focus another app not on the list, same request | refused: not an allowed app | |
| 5 | `key ctrl+s` in Notepad; then `alt+f4`, `win+r` | save dialog opens; the other two refused | |
| 6 | `click` at a point outside the Notepad window | refused | |
| 7 | `browser_extract` of an allowed https page; of a page that redirects elsewhere | text returned marked "বাইরের তথ্য"; redirect refused | |
| 8 | Move the mouse into a screen corner during a click sequence | pyautogui fail-safe aborts | |

## 3. Emergency stop on a running job
1. Approve a plan containing `run_command` with a program that runs long (e.g. `ping -n 60 127.0.0.1`).
2. While it runs press the emergency stop on the website (and again, on the phone). Expected within ~2-3 s: the process is killed, the plan shows stopped, the agent log says the server stop arrived, `jarvis-agent status` shows the stop marker. Then `jarvis-agent resume`.

## 4. Mode
`jarvis-agent mode companion` → the website still shows the computer online, jobs are refused/never pulled. `mode agent` restores. Repeat from the agent window buttons.

## 5. Voice, wake word, avatar (browser, Chrome/Edge on https)
/app/voice → hands-free on → say the wake phrase → ask a question → the avatar's expression changes (listening/thinking/speaking) and you hear the answer; say "stop" while it speaks. Native (non-browser) microphone and an offline wake-word engine are **not implemented**.

## 6. Mobile
Pair the Expo app (`apps/mobile`, `npx expo start` or an EAS build) → Computer screen → switch mode; approve a plan step with PIN; approve a high-risk step (it must be refused on the website and work only here).

## 7. Memory sync
Tell the chat on the website a fact ("remember that I prefer short answers" and approve the suggestion), then ask the same in the mobile chat. Expect the same memory to apply (memory is stored on the server per workspace).
