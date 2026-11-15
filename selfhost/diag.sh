#!/usr/bin/env bash
# Shows the real error behind a 500:  ./diag.sh [email]
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
docker compose --env-file .env -f docker-compose.yml cp diag.php web:/tmp/diag.php
exec docker compose --env-file .env -f docker-compose.yml exec web sh -c 'cd /var/www/html && php /tmp/diag.php "$@"' _ "$@"
