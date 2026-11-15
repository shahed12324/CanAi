CAN AI Desktop (agent + local window) - Windows
================================================
Needs: Windows 10/11 and Python 3.10 or newer (python.org, tick "Add to PATH").

1. Double-click  1-INSTALL.bat   (one time; needs internet)
2. Double-click  2-START.bat     (opens the local window in your browser at 127.0.0.1)
2b. Optional but important: double-click 3-VALIDATE.bat - it tests real typing/clicking in its OWN test window and saves a PASS/FAIL report. Install Tesseract OCR first (UB-Mannheim build) to include the screen-reading checks.
3. In the web app (https://YOUR-SERVER/app/computer) press "Pair", then in the local window enter the server address and the code.
4. In the local window, add the folders you allow ("Rules"). Nothing is allowed by default.

Be honest with yourself about what this is:
- This is the agent with its local window. It is NOT the Tauri tray app and NOT a signed installer. Windows may warn about the .bat files (More info -> Run anyway).
- It has never been run on Windows by its authors. If a step fails, copy the messages and send them back.
- Included: the 14-screen window, the animated character, voice (browser speech or cloud), pause/continue, token rotation.
- Voice/wake word/Bengali quality have never been tried on a real microphone. Chat/agents/workflows/memory/voice-cloud need the server updated to version 0.132+ (the new Laravel code has never been run live).
- Built but never run on Windows: screen reading (OCR, needs Tesseract), UI element clicking, browser automation (needs Chrome/Edge + playwright), planner, streaming chat.
- Full checklist of what to test: docs/DESKTOP_WINDOWS_CHECKLIST.md in the full project zip.
- Security: shells (cmd, powershell, python…) can never be allowed as commands; on Windows the device token is stored encrypted for your Windows user.
- If something crashes, a small local report is saved (never sent). See it with: jarvis-agent crash-report
- Safe to remove: delete this folder. Settings live in your user profile (see README.md).
