FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-libs libzip libcurl unzip git \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev curl-dev \
    && docker-php-ext-install -j"$(nproc)" curl intl pdo_mysql opcache pcntl zip \
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
COPY app/migrations/ /var/www/html/app/migrations/
COPY app/Interfaces/Web/View/ /var/www/html/app/Interfaces/Web/View/
COPY public/build/ /var/www/html/symfony/public/build/
COPY bin/spatial-convert.py /var/www/html/bin/spatial-convert.py

RUN printf 'upload_max_filesize=210M\npost_max_size=220M\nmax_file_uploads=20\n' > /usr/local/etc/php/conf.d/spatial-upload.ini

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var/cache var/log var/storage var/sessions/prod public/assets assets/vendor \
    && chown -R www-data:www-data var public/assets assets

USER www-data

RUN APP_ENV=prod APP_DEBUG=0 APP_SECRET=build-only-secret COS_ORGANIZATION_ID=default \
    DATABASE_URL="mysql://cos:build-only-password@127.0.0.1:3306/cos?serverVersion=8.4.0&charset=utf8mb4" \
    REDIS_URL="redis://127.0.0.1:6379" \
    MESSENGER_TRANSPORT_DSN="redis://127.0.0.1:6379/cos_async" \
    MESSENGER_FAILED_TRANSPORT_DSN="redis://127.0.0.1:6379/cos_failed" \
    MESSENGER_CONSUMER_NAME=build \
    COS_SALES_AUTOMATION_SCHEDULER_ENABLED=1 \
    COS_AGENT_LLM_MODE=fixture \
    LLM_ENDPOINT="" \
    LLM_TOKEN="" \
    LLM_MODEL="wave4-build" \
    LLM_PROVIDER="fixture" \
    SPATIAL_MAX_UPLOAD_BYTES=209715200 \
    SPATIAL_JWT_SECRET=build-only-spatial-secret \
    SPATIAL_JWT_TTL=28800 \
    SPATIAL_BLENDER_BINARY="" \
    SPATIAL_GLTF_TRANSFORM_BINARY="" \
    APP_URL="http://127.0.0.1:8081" \
    TELEGRAM_BOT_TOKEN="" \
    TELEGRAM_BOT_NAME="" \
    sh -c 'php bin/console cache:warmup && php bin/console importmap:install && php bin/console asset-map:compile'

EXPOSE 9000

HEALTHCHECK --interval=15s --timeout=5s --retries=5 \
  CMD php-fpm -t >/dev/null 2>&1 || exit 1

CMD ["php-fpm"]
