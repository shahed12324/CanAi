#!/usr/bin/env bash
# Run an artisan command inside the running app container:  ./artisan.sh admin:promote you@example.com
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
exec docker compose --env-file .env -f docker-compose.yml exec web php artisan "$@"
