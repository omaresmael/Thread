# The benchmark image: the official FrankenPHP image (FrankenPHP 1.13, PHP 8.4 ZTS; opcache on for the web SAPI,
# PHP's default opcache.enable_cli=0 for the CLI) plus ext-parallel, pcntl and pdo_mysql, procps for the scripts, and Composer.
# Used for every flow: the CLI benchmark, FrankenPHP classic mode and Octane worker mode.
FROM dunglas/frankenphp:php8.4
RUN install-php-extensions parallel pcntl \
 && apt-get update && apt-get install -y --no-install-recommends procps unzip git && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# pdo_mysql for the I/O-bound task's MySQL connection.
RUN install-php-extensions pdo_mysql
