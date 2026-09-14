FROM phalconphp/cphalcon:v5.19.0-php8.3

USER root

RUN install-php-extensions bcmath pcntl sockets

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-cos.ini

WORKDIR /var/www/html

# Runtime logs/uploads are intentionally excluded from the Docker build context,
# so create their writable directories in the image explicitly.
RUN mkdir -p /var/www/html/tmp/logs /var/www/html/public/uploads \
    && chown -R phalcon:phalcon /var/www/html/tmp /var/www/html/public

COPY --chown=phalcon:phalcon composer.json composer.lock ./

USER phalcon

# Install dependencies before copying the application to preserve the dependency
# cache layer. The authoritative root classmap is generated only after app/ exists.
RUN composer install --no-dev --no-interaction --prefer-dist --no-autoloader

COPY --chown=phalcon:phalcon . .

RUN composer dump-autoload --no-dev --classmap-authoritative
