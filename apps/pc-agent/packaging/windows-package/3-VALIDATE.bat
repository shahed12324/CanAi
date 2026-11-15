@echo off
cd /d "%~dp0"
if not exist .venv\Scripts\jarvis-agent.exe (
  echo Run 1-INSTALL.bat first.
  pause
  exit /b 1
)
echo === Step 1: read-only self check ===
.venv\Scripts\jarvis-agent selfcheck
echo.
echo === Step 2: real mouse/keyboard validation in its OWN small test window ===
echo Do not touch the mouse or keyboard for about 20 seconds after you press a key.
echo (Moving the mouse into a screen corner aborts it.)
pause
.venv\Scripts\jarvis-agent validate-windows --yes
echo.
echo The report (validation-*.json) is saved in your profile folder  %USERPROFILE%\.jarvis-agent
echo Send the text above and that file back so the results can be recorded.
pause
