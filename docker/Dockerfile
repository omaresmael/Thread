# One image per PHP build: VARIANT=zts (thread-safe, with ext-parallel) or VARIANT=cli (normal NTS PHP).
ARG PHP_VERSION=8.4
ARG VARIANT=zts
FROM php:${PHP_VERSION}-${VARIANT}

ARG VARIANT

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip $PHPIZE_DEPS \
    && if [ "$VARIANT" = "zts" ]; then pecl install parallel && docker-php-ext-enable parallel; fi \
    && docker-php-ext-install pcntl \
    && (docker-php-ext-enable opcache || true) \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

RUN { \
        echo 'opcache.enable_cli=1'; \
        echo 'memory_limit=512M'; \
    } > /usr/local/etc/php/conf.d/zz-thread.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /package
