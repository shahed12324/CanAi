@echo off
cd /d "%~dp0"
if not exist .venv\Scripts\jarvis-agent.exe (
  echo Run 1-INSTALL.bat first.
  pause
  exit /b 1
)
echo Opening CAN AI Desktop in your browser. Keep this window open; close it to stop the agent.
.venv\Scripts\jarvis-agent gui
pause
