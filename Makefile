.PHONY: mobile-test mobile-bundle db-provision db-test help up down logs engine-install engine-test engine-lint web-bootstrap web-test lint test

help: ## list targets
	@grep -E '^[a-z-]+:.*##' Makefile | sed 's/:.*##/\t/'

up: ## start postgres, redis, ai-engine
	docker compose -f infra/docker-compose.yml --env-file .env up -d --build
down: ## stop the stack
	docker compose -f infra/docker-compose.yml --env-file .env down
logs: ## follow logs
	docker compose -f infra/docker-compose.yml --env-file .env logs -f

engine-install: ## python deps (venv at apps/ai-engine/.venv)
	cd apps/ai-engine && python3 -m venv .venv && .venv/bin/pip install -e '.[dev]'
engine-test: ## AI engine tests
	cd apps/ai-engine && .venv/bin/python -m pytest -q
engine-lint: ## ruff + mypy
	cd apps/ai-engine && .venv/bin/ruff check . && .venv/bin/mypy app

db-provision: ## create jarvis_owner/jarvis_app roles on the compose DB (reads .env)
	set -a; . ./.env; set +a; PGHOST=127.0.0.1 PGUSER=$$DB_USERNAME PGPASSWORD=$$DB_PASSWORD PGDATABASE=$$DB_DATABASE ./scripts/db-provision.sh
web-bootstrap: ## create apps/web (Laravel 13) once
	./scripts/bootstrap-web.sh
web-test: ## Pest + Larastan + Pint
	cd apps/web && ./vendor/bin/pest && ./vendor/bin/phpstan analyse --memory-limit=512M && ./vendor/bin/pint --test

db-test: ## DB isolation tests (needs JARVIS_TEST_PG_ADMIN_DSN, a superuser DSN)
	python3 -m pytest -q tests/db
lint: engine-lint
mobile-test: ## Expo app: types + API client tests + route contract
	cd apps/mobile && npm run check && cd ../.. && python3 -m pytest -q tests/mobile

mobile-bundle: ## Prove the Android bundle builds
	cd apps/mobile && npx expo export --platform android --output-dir /tmp/jarvis-export

test: engine-test db-test mobile-test
