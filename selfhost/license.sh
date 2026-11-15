#!/usr/bin/env bash
# Activate / show the licence:  ./license.sh JVSH1.xxxx.yyyy [--tenant=<workspace id>]   |   ./license.sh   (status)
# The vendor public key must be in .env as JARVIS_LICENSE_PUBKEY (64 hex chars); then: docker compose up -d web
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
exec docker compose --env-file .env -f docker-compose.yml exec web php artisan selfhost:license "$@"
