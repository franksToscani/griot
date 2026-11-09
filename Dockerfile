# The production image of Griot (docs/production.md): one container with the web server
# (FrankenPHP, classic mode), the queue worker and the scheduler, under supervisord.
# PHP 8.5, the same major version as the CI (.github/workflows). Built and started by the
# docker-build job of production-checks.yml; on Render by render.yaml.

ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.13.3-php8.5-trixie
ARG NODE_IMAGE=node:22-trixie-slim
ARG COMPOSER_IMAGE=composer:2

FROM ${COMPOSER_IMAGE} AS composer
FROM ${NODE_IMAGE} AS node

# ---------------------------------------------------------------------------------------------
# a) vendor: the PHP packages for production, without the development ones.
# ---------------------------------------------------------------------------------------------
FROM ${FRANKENPHP_IMAGE} AS vendor

# The same extensions as the runtime, so that Composer checks the platform it will run on; zip
# only to unpack the packages here.
RUN install-php-extensions pdo_pgsql gd exif zip

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist

COPY . .
# The autoloader and package:discover now that the code is here.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist

# ---------------------------------------------------------------------------------------------
# b) assets: the frontend. The Wayfinder plugin of Vite runs "php artisan wayfinder:generate"
#    during the build, so this stage has PHP and vendor/ too, besides Node 22.
# ---------------------------------------------------------------------------------------------
FROM vendor AS assets

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && node --version && npm --version

RUN npm ci --no-audit --no-fund && npm run build

# ---------------------------------------------------------------------------------------------
# c) runtime: FrankenPHP, the extensions the code uses, the code, vendor/ and public/build/.
# ---------------------------------------------------------------------------------------------
FROM ${FRANKENPHP_IMAGE} AS runtime

# pdo_pgsql for the database, gd (with WebP) and exif for the photos (composer.json). OPcache is
# part of PHP 8.5. The other extensions of the packages (mbstring, openssl, ctype, dom...) are in
# the base image. intl and bcmath are not used.
RUN install-php-extensions pdo_pgsql gd exif \
    && apt-get update \
    && apt-get install -y --no-install-recommends supervisor \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-griot.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/supervisord.conf /etc/supervisor/griot.conf
COPY docker/start.sh /usr/local/bin/griot-start
COPY docker/stop-on-web-exit.sh /usr/local/bin/griot-stop-on-web-exit

WORKDIR /app

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build

# Not root. Writable: storage/ and bootstrap/cache/ only, and Caddy's own folders (XDG_DATA_HOME
# and XDG_CONFIG_HOME of the base image, /data and /config). The port comes from Render ($PORT,
# above 1024): no capability to bind a low port.
RUN useradd --create-home --uid 10001 griot \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs bootstrap/cache \
    && chmod 0755 /usr/local/bin/griot-start /usr/local/bin/griot-stop-on-web-exit \
    && chown -R griot:griot /app/storage /app/bootstrap/cache /data /config

ENV XDG_DATA_HOME=/data \
    XDG_CONFIG_HOME=/config \
    PORT=8080

USER griot

EXPOSE 8080

CMD ["griot-start"]
