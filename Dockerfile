# syntax=docker/dockerfile:1.7

# ─────────────────────────────────────────────────────────────────────────────
# Base — PHP 8.4 CLI with every extension the Core needs.
# PHP 8.4 is the baseline (ADR-0017): active support until 2026-12-31, security
# fixes until 2028-12-31, and the floor required by the quality toolchain.
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.4-cli-bookworm AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# bcmath + gmp: exact integer arithmetic for the ledger (never floating point).
# pdo_pgsql: PostgreSQL. redis: Valkey client. intl: currency/locale formatting.
# pcntl: Horizon worker signal handling.
RUN install-php-extensions \
        bcmath \
        gmp \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        redis \
        sodium \
        zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip postgresql-client \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# ─────────────────────────────────────────────────────────────────────────────
# Dev — adds coverage, and runs as the host user so files written inside the
# container stay editable outside it.
# ─────────────────────────────────────────────────────────────────────────────
FROM base AS dev

ARG UID=1000
ARG GID=1000

RUN install-php-extensions pcov

RUN groupadd --force --gid ${GID} app \
    && useradd --uid ${UID} --gid ${GID} --create-home --shell /bin/bash app \
    && chown -R ${UID}:${GID} /var/www/html \
    # Pre-create the cache path so the named volume inherits app ownership
    # rather than being created root-owned at first mount.
    && mkdir -p /home/app/.composer/cache \
    && chown -R ${UID}:${GID} /home/app

COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/99-core.ini

USER app

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# ─────────────────────────────────────────────────────────────────────────────
# Vendor — production dependency resolution, cached on the lock file alone.
# ─────────────────────────────────────────────────────────────────────────────
FROM base AS vendor

COPY composer.json composer.lock ./
COPY packages/ ./packages/

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# ─────────────────────────────────────────────────────────────────────────────
# Production — FrankenPHP (Caddy core), classic per-request mode (ADR-0053).
# `php artisan serve` is Laravel's own dev server, single-threaded and
# explicitly documented as unfit for production — this replaces it, not a
# variant of it. Classic mode (not FrankenPHP's worker mode): one request =
# one PHP execution context, the same lifecycle Laravel already assumes
# everywhere else in this Core. Worker mode's persistent-process model is a
# deliberate non-goal here — it would demand auditing every service for
# request-scoped state that would otherwise leak across requests, a cost not
# worth taking on in the same change as the first production deployment.
# TLS is terminated upstream (the shared nginx-proxy + this project's own
# nginx sidecar, ADR-0053) — FrankenPHP serves plain HTTP only.
# ─────────────────────────────────────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4-bookworm AS production

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# Same extension list as `base` (this stage doesn't build FROM base — the
# FrankenPHP image is its own PHP build, not php:8.4-cli-bookworm).
RUN install-php-extensions \
        bcmath \
        gmp \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        redis \
        sodium \
        zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/99-core.ini
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

RUN groupadd --system --gid 1000 app \
    && useradd --system --uid 1000 --gid app --create-home app \
    # Caddy (FrankenPHP's core) keeps its own state (autosave config) under
    # XDG dirs even with auto_https/admin off — give the app user a writable
    # home for it instead of running any of this as root.
    && mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data/caddy /config/caddy

COPY --from=vendor --chown=app:app /var/www/html/vendor ./vendor
COPY --chown=app:app . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && chmod -R ug+rw storage bootstrap/cache

USER app

EXPOSE 8000

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
