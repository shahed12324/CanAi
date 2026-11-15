#!/usr/bin/env bash
# Creates apps/web (Laravel 13) and applies our overlay. Run once, on a machine with PHP 8.4+,
# Composer 2 and Node. Needs access to packagist.org and registry.npmjs.org.
# Every patch to a third-party file is verified; if the upstream file changed shape the
# script stops with a clear message instead of leaving a half-working app.
set -euo pipefail
cd "$(dirname "$0")/.."

fail() { echo "ERROR: $*" >&2; exit 1; }
[ -e apps/web/artisan ] && fail "apps/web already exists; refusing to overwrite."
php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || fail "PHP 8.3+ required (8.4 recommended)."

composer create-project laravel/laravel apps/web "^13.0" --no-interaction
cd apps/web

composer require --no-interaction \
  livewire/livewire:^4.0 laravel/fortify laravel/sanctum \
  spatie/laravel-permission laravel/horizon
# Laravel Reverb (WebSocket server) is deliberately NOT installed: no code of ours uses broadcasting yet, and installing it made every
# `artisan` call fail (broadcaster needs REVERB_* keys) and conflicted with the skeleton's guzzlehttp/psr7 3.x.
# Developer tools only (tests/lint). The production image installs with --no-dev, so a version conflict here must not stop the build.
composer require --dev --no-interaction -W \
  pestphp/pest pestphp/pest-plugin-laravel larastan/larastan laravel/pint \
  || echo "WARN: dev tools (pest/larastan/pint) skipped (dependency conflict); production is not affected."

# Our files win over the skeleton's (config, routes, models, tests, seeders, migrations).
cp -R ../web-overlay/. .

# Register our providers (idempotent).
for P in AiEngineServiceProvider CreditServiceProvider ChatServiceProvider MemoryServiceProvider KnowledgeServiceProvider VisionServiceProvider ProductivityServiceProvider OfficeServiceProvider AssistServiceProvider WorkflowServiceProvider AgentsServiceProvider VoiceServiceProvider ConnectServiceProvider StudioServiceProvider DeveloperServiceProvider PluginServiceProvider ResellerServiceProvider BrandServiceProvider CharacterServiceProvider PcServiceProvider LicenseServiceProvider VideoServiceProvider SocialServiceProvider MessagingServiceProvider LeadServiceProvider ProposalServiceProvider AutomationServiceProvider SeoServiceProvider CatalogServiceProvider InboxServiceProvider OrderServiceProvider CourierServiceProvider AccountingServiceProvider OutreachServiceProvider BillingServiceProvider MobileServiceProvider TenancyServiceProvider FortifyServiceProvider WebServiceProvider AdminServiceProvider; do
  grep -q "$P" bootstrap/providers.php \
    || sed -i "s#\];#    App\\\\Providers\\\\$P::class,\n];#" bootstrap/providers.php
done

# Behind Caddy / nginx / Apache the app only ever sees plain http from a proxy; trust X-Forwarded-* so URLs, cookies and redirects
# stay https. Safe here because the web container is never published to the internet except through that proxy chain.
if ! grep -q "trustProxies" bootstrap/app.php; then
  sed -i -E 's#(->withMiddleware\(function \(Middleware \$middleware\)[^{]*\{)#\1\n        $middleware->trustProxies(at: "*");#' bootstrap/app.php
fi
grep -q 'trustProxies(at: "\*")' bootstrap/app.php || fail "could not patch bootstrap/app.php (add \$middleware->trustProxies(at: '*') inside ->withMiddleware by hand)."

# Publish package assets we need as files (we do NOT run fortify:install: our config/actions replace it).
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" --no-interaction
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --no-interaction

# --- Patch published migrations/config for ULID keys and teams mode -------------------------
SANCTUM_MIG=$(ls database/migrations/*create_personal_access_tokens_table.php 2>/dev/null | head -1)
[ -n "$SANCTUM_MIG" ] || fail "Sanctum migration was not published."
sed -i "s/->morphs('tokenable')/->ulidMorphs('tokenable')/" "$SANCTUM_MIG"
grep -q "ulidMorphs('tokenable')" "$SANCTUM_MIG" || fail "could not patch $SANCTUM_MIG (edit tokenable to ulidMorphs by hand)."

PERM_MIG=$(ls database/migrations/*create_permission_tables.php 2>/dev/null | head -1)
[ -n "$PERM_MIG" ] || fail "spatie permission migration was not published."
sed -i \
  -e "s/unsignedBigInteger(\$columnNames\['model_morph_key'\])/char(\$columnNames['model_morph_key'], 26)/g" \
  -e "s/unsignedBigInteger(\$columnNames\['team_foreign_key'\])/char(\$columnNames['team_foreign_key'], 26)/g" \
  "$PERM_MIG"
grep -q "char(\$columnNames\['model_morph_key'\], 26)" "$PERM_MIG" || fail "could not patch model_id in $PERM_MIG."
grep -q "char(\$columnNames\['team_foreign_key'\], 26)" "$PERM_MIG" || fail "could not patch team_id in $PERM_MIG."

# vendor:publish stamps the migrations with the BUILD time, so every image rebuild would add a "new" copy that tries to create the same
# tables again and blocks every migration after it. Make the published ones skip when their table already exists.
guard_migration() { # file table
  T="$2" perl -0pi -e 's/(public function up\(\)[^\{]*\{)/$1 . "\n        if (\\Illuminate\\Support\\Facades\\Schema::hasTable(\x27" . $ENV{T} . "\x27)) {\n            return;\n        }\n"/e' "$1"
  grep -q "hasTable('$2')" "$1" || fail "could not add the already-exists guard to $1."
}
guard_migration "$SANCTUM_MIG" personal_access_tokens
guard_migration "$PERM_MIG" permissions

sed -i "s/'teams' => false/'teams' => true/" config/permission.php
grep -q "'teams' => true" config/permission.php || fail "could not enable teams in config/permission.php."

php artisan horizon:install --no-interaction || echo "WARN: horizon:install needs a manual run"
[ -x vendor/bin/pest ] && ./vendor/bin/pest --init || true

# --- .env: sensible local defaults; secrets come from the root .env --------------------------
[ -f .env ] || cp .env.example .env
setenv() { grep -q "^$1=" .env && sed -i "s#^$1=.*#$1=$2#" .env || echo "$1=$2" >> .env; }
ROOT_ENV=../../.env
getroot() { [ -f "$ROOT_ENV" ] && grep -E "^$1=" "$ROOT_ENV" | head -1 | cut -d= -f2- || true; }
setenv DB_CONNECTION pgsql
setenv DB_HOST 127.0.0.1
setenv DB_PORT 5432
setenv DB_DATABASE "$(getroot DB_DATABASE)"
setenv DB_USERNAME jarvis_app
setenv DB_PASSWORD "$(getroot DB_APP_PASSWORD)"
setenv DB_ADMIN_USERNAME jarvis_admin_login
setenv DB_ADMIN_PASSWORD "$(getroot DB_ADMIN_PASSWORD)"
setenv BROADCAST_CONNECTION log
setenv SESSION_DRIVER database
setenv QUEUE_CONNECTION redis
setenv REDIS_PASSWORD "$(getroot REDIS_PASSWORD)"
setenv AI_ENGINE_URL http://127.0.0.1:8000
setenv AI_ENGINE_HMAC_SECRET "$(getroot AI_ENGINE_HMAC_SECRET)"
setenv AI_ENGINE_JWT_SECRET "$(getroot AI_ENGINE_JWT_SECRET)"
php artisan key:generate --no-interaction

# Vite 8 builds with Rolldown, a native binary that needs newer CPU instructions (AVX2/BMI). Many VPS plans expose an older virtual CPU
# (e.g. Ivy Bridge) and the build then dies with "Illegal instruction". Vite 7 (Rollup) builds fine there — verified under an emulated Ivy Bridge CPU.
npm pkg set devDependencies.vite="^7.0.0" devDependencies.laravel-vite-plugin="^2.0.0"
# The Laravel 13 skeleton's vite.config.js imports `laravel-vite-plugin/fonts`, which only exists in plugin 3.x (needs Vite 8). Use a plain config.
cat > vite.config.js <<'VITECONF'
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.js'], refresh: true }),
        tailwindcss(),
    ],
});
VITECONF
npm install
cat <<MSG

Done. Next steps (see ENVIRONMENT_SETUP.md):
  1. make up && make db-provision
  2. Migrate with the OWNER role:  DB_USERNAME=jarvis_owner DB_PASSWORD=<DB_OWNER_PASSWORD> php artisan migrate --seed
  3. php artisan tenancy:check          (runs as jarvis_app; must pass)
  4. make web-test
MSG
