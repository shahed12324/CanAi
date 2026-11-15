#!/usr/bin/env bash
# Prepare a local Ollama (+ check FFmpeg) on your own computer and print what to enter in JARVIS (/admin -> AI Provider).
#   ./setup.sh [model]        default model: llama3.2
set -Eeuo pipefail
MODEL="${1:-llama3.2}"
[[ "$MODEL" =~ ^[A-Za-z0-9._:/-]{1,80}$ ]] || { echo "invalid model name" >&2; exit 2; }
command -v ollama >/dev/null || { echo "Ollama is not installed. Get it from https://ollama.com/download (or use: docker compose up -d in this folder)." >&2; exit 1; }
if command -v ffmpeg >/dev/null; then echo "[ OK ] ffmpeg: $(ffmpeg -version | head -1)"; else echo "[WARN] ffmpeg not found (only needed for local video experiments; the server has its own)."; fi
if ! curl -fsS --max-time 3 http://127.0.0.1:11434/api/tags >/dev/null 2>&1; then
  echo "Ollama is not answering on 127.0.0.1:11434 — start it (ollama serve) and re-run." >&2; exit 1
fi
ollama pull "$MODEL"
cat <<DONE

Local model ready: $MODEL

In JARVIS (/admin -> AI Provider) add a provider:
  type      : ollama
  base URL  : http://<address your server can reach>:11434/v1      (see README.md: Tailscale is the easiest)
  allow private/local address: ON
then add a model named "$MODEL" with a price (can be 0) and enable it.
Keep Ollama bound to localhost/Tailscale only — it has no login of its own.
DONE
