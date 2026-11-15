# CAN AI Desktop — dependencies, configuration, build and install

## What you get
| Piece | Where | Needs |
|---|---|---|
| Local agent + premium window | `apps/pc-agent` (`jarvis-agent gui`) | Python ≥ 3.10; optional `psutil` (metrics), `mss` (screenshots), `pyautogui` (mouse/keyboard) |
| Tauri shell (tray + window, spawns the agent) | `apps/desktop` | Rust ≥ 1.77, WebView2 (Windows), `cargo tauri` |
| Server side | `apps/web-overlay` (Laravel), migrations `phase33a`, `phase33b` | the normal self-host stack (`selfhost/`) |

## 1. Server (VPS, root)
```
cd /opt/jarvis/selfhost && ./update.sh /home/administrator/jarvis-one-full.zip 2>&1 | tee /root/update.log
```
`update.sh` rebuilds web + nginx, runs migrations as the owner role (includes `2027_01_03_100000_pc_window_kinds`, `2027_01_03_200000_pc_token_rotation`) and restarts. Nothing else to configure: the desktop uses the existing device token. Check afterwards: `docker compose logs web | tail` and call a desktop route once (Windows checklist B5).

## 2. Run the agent from Python (works today on any OS)
```
cd apps/pc-agent
python -m venv .venv && . .venv/bin/activate        # Windows: .venv\Scripts\activate
pip install ".[metrics,vision,uia,browser]" mss pyautogui      # vision = Pillow + numpy; uia = Windows accessibility tree (optional)
# Screen reading also needs the Tesseract program: https://github.com/UB-Mannheim/tesseract/wiki (default folder is found automatically; or set JARVIS_TESSERACT)
jarvis-agent pair --server https://YOUR-SERVER --code CODE
jarvis-agent gui                                      # opens the window on 127.0.0.1 only
jarvis-agent selfcheck                                # what works on THIS machine
```
Useful: `jarvis-agent pause|continue|stop|resume|rotate-token|mode`. State lives in `~/.jarvis-agent` (`JARVIS_AGENT_HOME` overrides): `config.json` (0600), `audit.jsonl`, `tasks/`, `STOP`/`PAUSE` flags.

## 3. Windows package without Python knowledge (unsigned)
`CANAI-Desktop-Windows.zip` → `1-INSTALL.bat`, `2-START.bat` (needs Python). See its `README-FIRST.txt`.

## 4. Real installer (.exe/.msi, no Python needed) — GitHub Actions
1. Put the repository on GitHub (private is fine).
2. Actions → **desktop-release** → Run workflow (or push a tag `vX.Y.Z` that equals the version in `apps/pc-agent/jarvis_agent/__init__.py`). Steps: release check → agent tests → PyInstaller agent → optional signing → Tauri NSIS + MSI → signature verify (only if signed) → **silent-install smoke test** (install, run the installed agent, uninstall) → `SHA256SUMS.txt` + `SIGNING-STATUS.txt` → artifact `windows-installer`. A tag push also creates a **draft** GitHub release; a person reads and publishes it.
3. **Signing is optional and needs YOUR certificate.** Add repository secrets `WINDOWS_CERT_PFX_BASE64` (the .pfx, base64-encoded) and `WINDOWS_CERT_PASSWORD`. Without them the installers are **unsigned** (Windows SmartScreen warns; the artifact says "UNSIGNED"). With them the workflow signs the agent, the app exe and both installers with SHA-256 + a timestamp, then runs `signtool verify /pa`. A normal code-signing certificate still shows a SmartScreen warning until the file builds reputation; an EV certificate avoids that. Nothing here has been tried with a real certificate.
4. This workflow **has never run**. Expect to read the log and fix one or two things (for example the exact installer folder/file names the smoke test looks for).
5. Before a release locally: `python3 scripts/release-check.py [--tag vX.Y.Z]` (also part of `scripts/desktop-verify.sh`). Raise the version in all five places together: agent `__init__.py`, `pyproject.toml`, `tauri.conf.json`, `apps/desktop/Cargo.toml`, `packaging/installer.iss`; the check fails if they differ.
Local alternative on Windows: `powershell -ExecutionPolicy Bypass -File apps\pc-agent\packaging\build.ps1`, then `cd apps\desktop && cargo tauri build --config tauri.release.conf.json`.

### Crash reports
If the agent or the app shell crashes, a small report is saved in `%USERPROFILE%\.jarvis-agent\crashes\` (newest 10 kept). It holds version, OS, where it happened and a redacted message; no config, tokens, task data, source code or variables. **Nothing is sent automatically** and there is no crash server. See/delete in the window (Settings → Crash reports) or `jarvis-agent crash-report [--show NAME | --bundle | --clear]`; `--bundle` prints one text you can paste into a support message if you choose to.

## 5. Environment examples
No new environment variables were added for the desktop. Server variables are described in `selfhost/README.md`. The agent reads `JARVIS_AGENT_HOME` (settings folder) and, for support only, `JARVIS_NO_DPAPI=1` (store the token as plain text instead of DPAPI on Windows).
