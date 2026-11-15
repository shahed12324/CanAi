#!/usr/bin/env bash
# Builds ONE self-contained executable of the agent with PyInstaller for the OS this runs on (Linux/macOS: verified on Linux only; for Windows use build.ps1).
set -euo pipefail
cd "$(dirname "$0")/.."
python -m pip install --quiet pyinstaller . 
python -m PyInstaller --noconfirm --onefile --name jarvis-agent --collect-submodules jarvis_agent --collect-data jarvis_agent packaging/entry.py
./dist/jarvis-agent --help >/dev/null && echo "OK: dist/jarvis-agent starts"
