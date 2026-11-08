#!/bin/sh
# prepare.sh [app source] [benchmark dir]: copies the app into the container at /app (served from the macOS bind mount,
# every PHP boot is ~10x slower), lays the harness files from overlay/ over it (tasks, route, controller, command),
# and caches config, routes and events as a production deploy would (`php artisan optimize`).
set -e
SRC=${1:-/src}
BENCH=${2:-/bench}
[ -d "$SRC/vendor" ] || { echo "No app in $SRC: run setup.sh first."; exit 1; }
mkdir -p /app && find /app -mindepth 1 -maxdepth 1 -exec rm -rf {} +
cp -a "$SRC/." /app/
cp -a "$BENCH/overlay/." /app/
cd /app
php artisan optimize --quiet
