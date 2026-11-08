#!/bin/bash
# run-web.sh <classic|worker>: the web benchmark for one FrankenPHP mode, run from the host (it drives the containers).
# For each driver (DRIVERS, default thread and process): a freshly started server, then WARMUP rounds (default 1, recorded but left
# out of every statistic) and ROUNDS measured rounds (default 5). A round measures every task count (TASKS, default
# "2 5 10") at every load (CLIENTS, default "1 10 50"; requests per measurement from REQUESTS, default 30, 100, 200).
# Every measurement writes exactly one JSON line to results/web-<mode>.jsonl, success or failure, with COOLDOWN
# seconds (default 5) after it; per-request latencies go to results/raw/web-<mode>/. Then a summary.
# TASK picks the task (default report, CPU-bound; wait, I/O-bound: it starts the mysql container); results of the wait
# task go to results/web-<mode>-wait.jsonl.
#   ROUNDS=1 WARMUP=0 COOLDOWN=1 TASKS=2 CLIENTS="1 10" DRIVERS=thread bash benchmark/run-web.sh classic
#   TASK=wait bash benchmark/run-web.sh worker
set -u
MODE=${1:?usage: run-web.sh classic|worker}
case "$MODE" in classic|worker) ;; *) echo "usage: run-web.sh classic|worker"; exit 1 ;; esac
ROUNDS=${ROUNDS:-5}
WARMUP=${WARMUP:-1}
COOLDOWN=${COOLDOWN:-5}
DRIVERS=${DRIVERS:-"thread process"}
TASKS=${TASKS:-"2 5 10"}
CLIENTS=${CLIENTS:-"1 10 50"}
TASK=${TASK:-report}
case "$TASK" in report) SUFFIX= ;; wait) SUFFIX=-wait ;; *) echo "TASK must be report or wait"; exit 1 ;; esac
cd "$(dirname "$0")"
[ -d app/vendor ] || { echo "Run setup.sh first."; exit 1; }
OUT=results/web-$MODE$SUFFIX.jsonl
RAW=results/raw/web-$MODE$SUFFIX
SERVER_INFO=results/web-$MODE$SUFFIX-server.jsonl
mkdir -p "$RAW"
: > "$OUT"
: > "$SERVER_INFO"

# Every docker call is bounded: bounded <seconds> <command...> kills the command if it runs longer and returns 124.
bounded() {
    local limit=$1; shift
    "$@" & local pid=$!
    # The guard sleeps in the background and waits on it, so a TERM ends it at once (a foreground sleep would delay it).
    ( trap 'exit 0' TERM; sleep "$limit" & wait $!; kill -9 "$pid" 2>/dev/null ) >/dev/null 2>&1 & local guard=$!
    wait "$pid"; local code=$?
    kill "$guard" 2>/dev/null
    return $code
}
dc() { bounded 120 docker compose "$@"; }
requests() {   # requests per measurement for a client count
    case "$1" in 1) echo "${REQUESTS_1:-30}" ;; 10) echo "${REQUESTS_10:-100}" ;; 50) echo "${REQUESTS_50:-200}" ;; *) echo $(( $1 * 4 )) ;; esac
}
state() { bounded 30 docker inspect -f '{{.State.Status}}' "thread-benchmark-$MODE-1" 2>/dev/null || echo missing; }
oom_kills() {   # processes the kernel killed in the server container for going over its memory cap (cgroup v2)
    dc exec -T "$MODE" sh -c "grep -s '^oom_kill ' /sys/fs/cgroup/memory.events | awk '{print \$2}'" 2>/dev/null | tr -dc 0-9
}
# The process driver's child processes outlive a request that failed or timed out: kill them between measurements.
# (pkill -x php, by process name: the server itself is "frankenphp", and in worker mode Octane's parent "php artisan
# octane:frankenphp" is matched out by its full command line.)
kill_children() { dc exec -T "$MODE" sh -c 'for p in $(pgrep -x php); do grep -q octane:frankenphp /proc/$p/cmdline 2>/dev/null || grep -q sampler.php /proc/$p/cmdline 2>/dev/null || kill -9 $p; done; true' >/dev/null 2>&1; }
# A fresh server: stop the container and start it again (its command copies the app anew and starts the server).
# Never recreated here: on Docker Desktop, recreating a container was observed to take over 15 minutes.
start_server() {
    dc stop -t 10 classic worker >/dev/null 2>&1
    dc up -d --no-recreate "$MODE" >/dev/null 2>&1
    bounded 330 sh wait.sh "$MODE" || return 1
    dc exec -T "$MODE" php /bench/server-info.php "$MODE" >> "$SERVER_INFO"
}

measure() {   # <driver> <round> <tasks> <clients> <warmup>
    local driver=$1 round=$2 tasks=$3 clients=$4 warmup=$5 n before after server
    n=$(requests "$clients")
    [ "$(state)" = running ] || { echo "  server was down: restarting"; start_server; }
    kill_children
    before=$(oom_kills)
    dc exec -T "$MODE" rm -f /tmp/mem.json /tmp/mem.stop
    dc exec -d "$MODE" php /bench/sampler.php /tmp/mem.json /tmp/mem.stop 10
    sleep 0.5
    # The load client's own bound: 120 s per request, 30 minutes per measurement.
    bounded 1900 docker compose exec -T load timeout 1800 php load.php "http://$MODE/bench?driver=$driver&tasks=$tasks&task=$TASK" "$n" "$clients" \
        "/bench/$RAW/$driver-${tasks}t-${clients}c-r$round.jsonl" > /tmp/thread-bench-load.json 2>&1
    dc exec -T "$MODE" touch /tmp/mem.stop >/dev/null 2>&1
    sleep 0.3
    dc exec -T "$MODE" cat /tmp/mem.json > /tmp/thread-bench-mem.json 2>/dev/null
    after=$(oom_kills)
    server=$(state)
    [ "$server" = running ] || server="$server ($(docker inspect -f 'exit {{.State.ExitCode}}, OOMKilled {{.State.OOMKilled}}' "thread-benchmark-$MODE-1" 2>/dev/null))"
    cp /tmp/thread-bench-load.json results/.load.json; cp /tmp/thread-bench-mem.json results/.mem.json
    dc exec -T load php record-web.php results/.load.json results/.mem.json "$(( ${after:-0} - ${before:-0} ))" "$server" \
        "$MODE" "$driver" "$tasks" "$clients" "$round" "$warmup" | tee -a "$OUT" | cut -c1-260
    rm -f results/.load.json results/.mem.json
    kill_children
    sleep "$COOLDOWN"
}

dc up -d load >/dev/null 2>&1
if [ "$TASK" = wait ]; then
    # MySQL loads the orders at its first start: wait (up to 5 minutes) until all 50,000 are there.
    dc up -d mysql >/dev/null 2>&1
    for i in $(seq 1 150); do
        [ "$(dc exec -T mysql mysql -ubench -pbench -N -e 'select count(*) from bench.orders' 2>/dev/null | tr -dc 0-9)" = 50000 ] && break
        [ "$i" = 150 ] && { echo "mysql did not come up"; exit 1; }
        sleep 2
    done
fi
echo "web benchmark, $MODE mode, task $TASK: drivers $DRIVERS; tasks $TASKS; clients $CLIENTS; $WARMUP warm-up + $ROUNDS rounds"
for driver in $DRIVERS; do
    echo "$driver: starting a fresh $MODE server"
    start_server || { echo "server did not start"; exit 1; }
    for round in $(seq $(( 1 - WARMUP )) "$ROUNDS"); do
        if [ "$round" -le 0 ]; then w=1; echo "$driver: warm-up round (discarded)"; else w=0; echo "$driver: round $round/$ROUNDS"; fi
        for tasks in $TASKS; do
            for clients in $CLIENTS; do
                measure "$driver" "$round" "$tasks" "$clients" "$w"
            done
        done
    done
done
dc stop -t 10 "$MODE" >/dev/null 2>&1
dc exec -T load php summarize.php "$OUT" "$ROUNDS"
