#!/bin/sh
# wait.sh <classic|worker>: waits (up to 5 minutes) until the server answers / with 200.
cd "$(dirname "$0")"
for i in $(seq 1 150); do
    [ "$(docker compose exec -T load curl -s -m 5 -o /dev/null -w '%{http_code}' "http://$1/" 2>/dev/null)" = 200 ] && exit 0
    sleep 2
done
echo "$1 did not come up"; exit 1
