FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-libs libzip unzip git \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql opcache zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY symfony/composer.json ./composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts

COPY symfony/ ./
COPY app/Kernel/Operations/ ./legacy/Kernel/Operations/
COPY app/Infrastructure/Platform/ReadModel/MySql/MysqlOperationsReadModel.php ./legacy/Infrastructure/Platform/ReadModel/MySql/MysqlOperationsReadModel.php

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

USER www-data

RUN APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-only-secret COS_ORGANIZATION_ID=default \
    LEGACY_DB_HOST=127.0.0.1 LEGACY_DB_PORT=3306 LEGACY_DB_NAME=cos \
    LEGACY_DB_USER=cos_symfony_ro LEGACY_DB_PASSWORD=build-only-password \
    php bin/console cache:warmup

EXPOSE 9000

HEALTHCHECK --interval=15s --timeout=5s --retries=5 \
  CMD php-fpm -t >/dev/null 2>&1 || exit 1

CMD ["php-fpm"]
