# Windows build of the agent: one .exe (PyInstaller).  NOT RUN by the authors (no Windows machine) - first run on Windows is the real test.
# Optional extras for the real screen/keyboard/mouse kinds:  pip install mss pyautogui
$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..')
python -m pip install --quiet pyinstaller mss pyautogui .
python -m PyInstaller --noconfirm --onefile --name jarvis-agent --hidden-import tkinter --collect-submodules jarvis_agent --collect-data jarvis_agent packaging\entry.py
& .\dist\jarvis-agent.exe --help | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'the built exe did not start' }
Write-Host 'OK: dist\jarvis-agent.exe starts. Next: run the manual checklist in docs/WINDOWS_VERIFICATION.md'
