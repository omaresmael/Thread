#!/bin/sh
# Creates benchmark/app: a fresh Laravel 13 app with this package (from this repository, not Packagist) and Laravel
# Octane installed, and the orders from data/orders.sql imported into its SQLite database. Runs in the cli container:
#   docker compose -f benchmark/docker-compose.yml run --rm cli sh setup.sh
set -e

# A clean copy of the package: without vendor/, this folder or git metadata, so Composer copies only the package.
rm -rf /tmp/package && mkdir -p /tmp/package
(cd /package && tar --exclude=./vendor --exclude=./benchmark --exclude=./.git --exclude=./.context \
    --exclude=./.superpowers --exclude=./docs --exclude=./art --exclude=./composer.lock -cf - .) | tar -xf - -C /tmp/package

# Built in the container (fast), then copied to the bind mount in one go.
rm -rf /tmp/app
composer create-project laravel/laravel /tmp/app "^13.0" --no-interaction --prefer-dist --quiet
cd /tmp/app
composer config repositories.thread '{"type": "path", "url": "/tmp/package", "options": {"symlink": false, "versions": {"omaresmael/thread": "dev-main"}}}'
composer require "omaresmael/thread:dev-main" "laravel/octane:^2.23" --no-interaction --quiet
php artisan octane:install --server=frankenphp --no-interaction

# Production settings: no debug mode, sessions and cache in memory (the benchmark measures the tasks, not them).
sed -i -e 's/^APP_ENV=.*/APP_ENV=production/' -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' \
    -e 's/^SESSION_DRIVER=.*/SESSION_DRIVER=array/' -e 's/^CACHE_STORE=.*/CACHE_STORE=array/' \
    -e 's/^LOG_LEVEL=.*/LOG_LEVEL=warning/' .env

php /package/benchmark/import.php database/database.sqlite /package/benchmark/data/orders.sql
echo "$(php -r 'echo (new PDO("sqlite:database/database.sqlite"))->query("select count(*) from orders")->fetchColumn();') orders imported."

rm -rf /package/benchmark/app && cp -a /tmp/app /package/benchmark/app
echo "Setup done: benchmark/app ($(php artisan --version), Octane $(composer show laravel/octane | awk '/^versions/{print $NF}'))."
