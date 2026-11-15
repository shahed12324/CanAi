#!/usr/bin/env bash
# One-time database provisioning (run as a PostgreSQL superuser / CREATEROLE+CREATEDB role).
#   PGHOST/PGPORT/PGUSER/PGPASSWORD  connection of the provisioning role
#   DB_DATABASE                      database to create            (default: jarvis)
#   DB_OWNER_PASSWORD, DB_APP_PASSWORD, DB_ADMIN_PASSWORD  passwords for the three login roles (required)
# Creates: login roles jarvis_owner (migrations; owns tables), jarvis_app (web/queue; RLS applies) and
# jarvis_admin_login (admin panel only; may cross tenants when the app sets the bypass flag),
# the group roles from database/sql/roles.sql, and the database. Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")/.."
: "${DB_OWNER_PASSWORD:?set DB_OWNER_PASSWORD}" "${DB_APP_PASSWORD:?set DB_APP_PASSWORD}" "${DB_ADMIN_PASSWORD:?set DB_ADMIN_PASSWORD}"
DB="${DB_DATABASE:-jarvis}"
[[ "$DB" =~ ^[a-z_][a-z0-9_]*$ ]] || { echo "invalid DB_DATABASE"; exit 1; }
P="psql -v ON_ERROR_STOP=1 -X -q -d ${PGDATABASE:-postgres}"

$P -f apps/web-overlay/database/sql/roles.sql
$P -v owner_pw="$DB_OWNER_PASSWORD" -v app_pw="$DB_APP_PASSWORD" -v admin_pw="$DB_ADMIN_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE jarvis_owner LOGIN PASSWORD %L', :'owner_pw')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'jarvis_owner') \gexec
SELECT format('CREATE ROLE jarvis_app LOGIN PASSWORD %L NOSUPERUSER NOBYPASSRLS', :'app_pw')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'jarvis_app') \gexec
SELECT format('CREATE ROLE jarvis_admin_login LOGIN PASSWORD %L NOSUPERUSER NOBYPASSRLS', :'admin_pw')
 WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'jarvis_admin_login') \gexec
SELECT format('ALTER ROLE jarvis_owner PASSWORD %L', :'owner_pw') \gexec
SELECT format('ALTER ROLE jarvis_admin_login PASSWORD %L', :'admin_pw') \gexec
SELECT format('ALTER ROLE jarvis_app PASSWORD %L', :'app_pw') \gexec
GRANT jarvis_admin  TO jarvis_owner;
GRANT jarvis_app_rw TO jarvis_app;
GRANT jarvis_app_rw, jarvis_admin TO jarvis_admin_login;
SQL
$P -v db="$DB" <<'SQL'
SELECT format('CREATE DATABASE %I OWNER jarvis_owner', :'db')
 WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db') \gexec
-- The database may already exist (e.g. created by docker with another owner): hand it over.
SELECT format('ALTER DATABASE %I OWNER TO jarvis_owner', :'db') \gexec
SQL
# Schema ownership matters on PostgreSQL 15+: only the schema owner may create tables in it.
psql -v ON_ERROR_STOP=1 -X -q -d "$DB" -c "ALTER SCHEMA public OWNER TO jarvis_owner"
echo "Provisioned database '$DB'. Laravel: DB_USERNAME=jarvis_app for the app, jarvis_owner for 'php artisan migrate'."
