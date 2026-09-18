FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-libs libzip unzip git \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql opcache pcntl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html/symfony

COPY symfony/composer.json symfony/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts

COPY symfony/ ./
COPY app/Kernel/ /var/www/html/app/Kernel/
COPY app/Platform/ /var/www/html/app/Platform/
COPY app/Domains/ /var/www/html/app/Domains/
COPY app/Infrastructure/ /var/www/html/app/Infrastructure/

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var/cache var/log var/storage \
    && chown -R www-data:www-data var

USER www-data

RUN APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-only-secret COS_ORGANIZATION_ID=default \
    DATABASE_URL="mysql://cos:build-only-password@127.0.0.1:3306/cos_symfony?serverVersion=8.4.0&charset=utf8mb4" \
    REDIS_URL="redis://127.0.0.1:6379" \
    MESSENGER_TRANSPORT_DSN="redis://127.0.0.1:6379/cos_async" \
    MESSENGER_FAILED_TRANSPORT_DSN="redis://127.0.0.1:6379/cos_failed" \
    MESSENGER_CONSUMER_NAME=build \
    LEGACY_DB_HOST=127.0.0.1 LEGACY_DB_PORT=3306 LEGACY_DB_NAME=cos \
    LEGACY_DB_USER=cos_symfony_ro LEGACY_DB_PASSWORD=build-only-password \
    php bin/console cache:warmup

EXPOSE 9000

HEALTHCHECK --interval=15s --timeout=5s --retries=5 \
  CMD php-fpm -t >/dev/null 2>&1 || exit 1

CMD ["php-fpm"]
