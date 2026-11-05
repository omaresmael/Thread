#!/bin/sh
# Creates benchmark/app: a fresh Laravel 13 app with this package installed (from this repository, not Packagist)
# and the orders from data/orders.sql imported into its SQLite database.
set -e

# A clean copy of the package: without vendor/, this folder or git metadata, so Composer copies only the package.
rm -rf /tmp/package && mkdir -p /tmp/package
(cd /package && tar --exclude=./vendor --exclude=./benchmark --exclude=./.git --exclude=./.context \
    --exclude=./.superpowers --exclude=./docs --exclude=./composer.lock -cf - .) | tar -xf - -C /tmp/package

cd /package/benchmark
rm -rf app
composer create-project laravel/laravel app "^13.0" --no-interaction --prefer-dist --quiet
cd app
composer config repositories.thread '{"type": "path", "url": "/tmp/package", "options": {"symlink": false, "versions": {"omaresmael/thread": "dev-main"}}}'
composer require "omaresmael/thread:dev-main" --no-interaction --quiet
cp ../tasks.php ../bench.php .
php ../import.php database/database.sqlite ../data/orders.sql
echo "Setup done: $(php -r 'echo (new PDO("sqlite:database/database.sqlite"))->query("select count(*) from orders")->fetchColumn();') orders imported."
