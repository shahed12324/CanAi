# Build context = repository root (see docker-compose.yml). Targets: app (php-fpm/horizon/scheduler/migrate) and nginx (static files + proxy).
FROM php:8.4-fpm-bookworm AS app
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_pgsql pgsql redis pcntl intl zip bcmath gd opcache sodium \
    && apt-get update && apt-get install -y --no-install-recommends git unzip curl ca-certificates procps \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# php-fpm must see the container environment (DB passwords, keys) — Laravel reads real env vars.
RUN printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-env.conf \
    && printf 'opcache.enable=1\nopcache.validate_timestamps=0\nmemory_limit=512M\nupload_max_filesize=2M\npost_max_size=4M\n' > /usr/local/etc/php/conf.d/zz-jarvis.ini
WORKDIR /srv/build
COPY scripts ./scripts
COPY apps/web-overlay ./apps/web-overlay
# Needs packagist.org + registry.npmjs.org at build time. Creates the Laravel app and applies the overlay.
RUN COMPOSER_ALLOW_SUPERUSER=1 bash scripts/bootstrap-web.sh \
    && cd apps/web && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction \
    && npm install --no-audit --no-fund && npm run build && rm -rf node_modules \
    && mkdir -p /var/www && rm -rf /var/www/html && mv /srv/build/apps/web /var/www/html \
    && rm -rf /srv/build \
    && mkdir -p /var/www/html/storage/app/public \
    && rm -rf /var/www/html/public/storage && ln -s /var/www/html/storage/app/public /var/www/html/public/storage \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
WORKDIR /var/www/html
USER www-data
EXPOSE 9000

FROM nginx:1.27-alpine AS nginx
COPY --from=app /var/www/html/public /var/www/html/public
COPY selfhost/docker/nginx.conf /etc/nginx/conf.d/default.conf
