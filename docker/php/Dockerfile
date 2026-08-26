FROM phalconphp/cphalcon:v5.19.0-php8.3

USER root

RUN install-php-extensions bcmath pcntl sockets

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-cos.ini

WORKDIR /var/www/html

COPY --chown=phalcon:phalcon composer.json composer.lock ./

USER phalcon

RUN composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative

COPY --chown=phalcon:phalcon . .

