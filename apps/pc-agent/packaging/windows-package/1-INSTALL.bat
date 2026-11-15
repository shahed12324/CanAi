@echo off
setlocal
cd /d "%~dp0"
title CAN AI Desktop - Install
where python >nul 2>nul
if errorlevel 1 (
  echo Python is not installed. Install Python 3.10+ from https://www.python.org/downloads/ and tick "Add python.exe to PATH", then run this again.
  pause
  exit /b 1
)
echo Installing CAN AI Desktop agent...
python -m venv .venv
if errorlevel 1 goto fail
.venv\Scripts\python -m pip install --quiet --upgrade pip
.venv\Scripts\python -m pip install --quiet ".[metrics]" mss pyautogui
if errorlevel 1 goto fail
.venv\Scripts\jarvis-agent --help >nul
if errorlevel 1 goto fail
echo.
echo Installed. Now double-click  2-START.bat
pause
exit /b 0
:fail
echo Install failed - read the messages above and send them to support.
pause
exit /b 1
