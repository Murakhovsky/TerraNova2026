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

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

USER www-data

RUN APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-only-secret php bin/console cache:warmup

EXPOSE 9000

HEALTHCHECK --interval=15s --timeout=5s --retries=5 \
  CMD php-fpm -t >/dev/null 2>&1 || exit 1

CMD ["php-fpm"]
