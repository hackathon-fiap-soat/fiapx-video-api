# syntax=docker/dockerfile:1.7
# fiapx-video-api: mesma imagem para web (php-fpm, com nginx como sidecar no pod), outbox-relay,
# saga-consumer, cron e migrations (spec §8.4). Alvos:
#   dev   ambiente de desenvolvimento e CI (composer, pcov)   docker build --target dev .
#   prod  imagem final, sem dependências de desenvolvimento    docker build --platform linux/amd64 .

ARG PHP_VERSION=8.4.27

# --- base: PHP + extensões exigidas -------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base
RUN apk add --no-cache icu-libs libzip \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache intl pcntl zip \
 && apk del .build-deps
WORKDIR /var/www/html

# --- dev: ferramentas de desenvolvimento e teste ----------------------------------------------------
FROM base AS dev
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
 && pecl install pcov && docker-php-ext-enable pcov \
 && apk del .build-deps \
 && apk add --no-cache git unzip
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# --- vendor: dependências de produção --------------------------------------------------------------
FROM dev AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && rm -rf tests features docs .github infra k8s

# --- prod ---------------------------------------------------------------------------------------------
FROM base AS prod
# Agente New Relic PHP (ADR-011). Só há build para Alpine em x86_64: a imagem de produção é linux/amd64.
# Fica inativo sem NEW_RELIC_LICENSE_KEY (ambiente local).
ARG NEWRELIC_VERSION=12.12.0.41
RUN set -eux; \
    apk add --no-cache --virtual .nr curl; \
    curl -fsSL "https://download.newrelic.com/php_agent/archive/${NEWRELIC_VERSION}/newrelic-php5-${NEWRELIC_VERSION}-linux-musl.tar.gz" -o /tmp/nr.tgz; \
    tar -xzf /tmp/nr.tgz -C /tmp; \
    NR_INSTALL_SILENT=1 NR_INSTALL_USE_CP_NOT_LN=1 /tmp/newrelic-php5-*/newrelic-install install; \
    rm -rf /tmp/nr.tgz /tmp/newrelic-php5-*; apk del .nr; \
    # O agente sobe o daemon na primeira requisição/mensagem e ambos gravam log em /var/log/newrelic; o
    # container roda como 82:82 (não-root), então o diretório precisa ser dele, senão o daemon não sobe.
    mkdir -p /var/log/newrelic; chown 82:82 /var/log/newrelic; \
    { echo 'extension = "newrelic.so"'; \
      echo 'newrelic.enabled = ${NEW_RELIC_ENABLED}'; \
      echo 'newrelic.license = "${NEW_RELIC_LICENSE_KEY}"'; \
      echo 'newrelic.appname = "${NEW_RELIC_APP_NAME}"'; \
      echo 'newrelic.distributed_tracing_enabled = true'; \
      echo 'newrelic.application_logging.forwarding.enabled = true'; \
      echo 'newrelic.daemon.address = "${NEW_RELIC_DAEMON_ADDRESS}"'; \
      echo 'newrelic.daemon.app_connect_timeout = "${NEW_RELIC_APP_CONNECT_TIMEOUT}"'; } > "$PHP_INI_DIR/conf.d/newrelic.ini"
# Daemon local (@newrelic) nos processos longos; o CronJob aponta para o daemon compartilhado
# (newrelic-php-daemon:31339) e espera a conexão do app (NEW_RELIC_APP_CONNECT_TIMEOUT=15s; 0 = não espera).
ENV NEW_RELIC_ENABLED=false NEW_RELIC_APP_NAME=fiapx-video-api NEW_RELIC_DAEMON_ADDRESS=@newrelic NEW_RELIC_LICENSE_KEY="" \
    NEW_RELIC_APP_CONNECT_TIMEOUT=0

COPY docker/php/prod.ini "$PHP_INI_DIR/conf.d/zz-fiapx.ini"
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-fiapx.conf
COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
RUN mkdir -p storage/framework/cache storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
# Numérico: com runAsNonRoot, o Kubernetes só aceita a imagem se puder confirmar que o UID não é 0.
USER 82:82
EXPOSE 9000
# Liveness do php-fpm (o probe HTTP do Kubernetes passa pelo nginx em /api/health/live).
HEALTHCHECK --interval=30s --timeout=3s CMD php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'
CMD ["php-fpm"]
