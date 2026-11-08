#!/bin/sh
# run-cli.sh: the command-line benchmark, in the cli container. For each driver (thread, process) and task count
# (TASKS, default "10 50 100"): WARMUP rounds (default 1) that are run but left out of every statistic, then ROUNDS
# measured rounds (default 5), with COOLDOWN seconds (default 5) between measurements. Every measurement is a fresh
# `php artisan benchmark:run <driver> <tasks>` and writes exactly one JSON line to results/cli[-wait].jsonl, success or
# failure (warm-ups marked "warmup": true); then a summary.
#   docker compose -f benchmark/docker-compose.yml run --rm cli sh run-cli.sh
#   ... run --rm -e ROUNDS=1 -e WARMUP=0 -e COOLDOWN=1 -e TASKS="10" -e DRIVERS="thread" cli sh run-cli.sh
# TASK picks the task: report (default, CPU-bound) or wait (I/O-bound, results in results/cli-wait.jsonl; start the
# mysql container first: docker compose -f benchmark/docker-compose.yml up -d mysql).
set -e
ROUNDS=${ROUNDS:-5}
WARMUP=${WARMUP:-1}
COOLDOWN=${COOLDOWN:-5}
TASKS=${TASKS:-"10 50 100"}
DRIVERS=${DRIVERS:-"thread process"}
TASK=${TASK:-report}
case "$TASK" in report) SUFFIX= ;; wait) SUFFIX=-wait ;; *) echo "TASK must be report or wait"; exit 1 ;; esac
cd /package/benchmark
mkdir -p results
OUT=results/cli$SUFFIX.jsonl

# The app, copied off the bind mount with the harness laid over it (see prepare.sh).
sh prepare.sh /package/benchmark/app /package/benchmark

# How many processes the kernel has killed in this container for running out of memory (cgroup v2, then v1).
oom_kills() {
    { grep -s '^oom_kill ' /sys/fs/cgroup/memory.events || grep -s '^oom_kill ' /sys/fs/cgroup/memory/memory.oom_control || echo 'oom_kill 0'; } | awk '{print $2}'
}

# Kills every PHP process left over from a measurement (child processes outlive a killed or timed-out parent).
kill_php() {
    for p in /proc/[0-9]*; do
        [ "$(cat "$p/comm" 2>/dev/null)" = php ] && kill -9 "${p#/proc/}" 2>/dev/null
    done
    return 0
}

# opcache.enable_cli per driver: on for threads (as the package recommends; the workers share the parent's opcache),
# PHP's default (off) for the process driver, which boots its child processes faster without it.
opcache_for() { [ "$1" = thread ] && echo 1 || echo 0; }

measure() {   # <round> <driver> <tasks> <opcache.enable_cli>
    set +e
    echo "opcache.enable_cli=$4" > /usr/local/etc/php/conf.d/zzz-benchmark.ini
    rm -f /tmp/mem.stop /tmp/mem.json /tmp/out /tmp/err
    OOM_BEFORE=$(oom_kills)
    php sampler.php /tmp/mem.json /tmp/mem.stop 10 php & SAMPLER=$!
    sleep 0.3
    START=$(date +%s.%N)
    # oom_score_adj 1000 (inherited by the driver's child processes): if the container runs out of memory, the kernel
    # kills the measured PHP processes, not this script or the sampler.
    (echo 1000 > /proc/self/oom_score_adj; cd /app && exec timeout -k 10 900 php artisan benchmark:run "$2" "$3" --task="$TASK" >/tmp/out 2>/tmp/err)
    CODE=$?
    WALL=$(echo "$(date +%s.%N) $START" | awk '{printf "%.2f", $1 - $2}')
    touch /tmp/mem.stop; wait $SAMPLER 2>/dev/null
    kill_php; sleep 1
    php record-cli.php /tmp/out /tmp/err "$CODE" "$(( $(oom_kills) - OOM_BEFORE ))" /tmp/mem.json "$4" "$2" "$3" "$1" "$WALL"
}

# One measurement = exactly one JSON line. If the measurement itself got killed before it wrote its line, a failure
# line is written for it here.
run_one() {   # <round> <driver> <tasks>
    OPC=$(opcache_for "$2")
    LINES=$(wc -l < "$OUT"); OOM_START=$(oom_kills)
    (measure "$1" "$2" "$3" "$OPC") >> "$OUT" || true
    if [ "$(wc -l < "$OUT")" -le "$LINES" ]; then
        kill_php
        : > /tmp/empty
        php record-cli.php /tmp/empty /tmp/empty -1 "$(( $(oom_kills) - OOM_START ))" /tmp/empty "$OPC" "$2" "$3" "$1" >> "$OUT"
    fi
    tail -n 1 "$OUT" | cut -c1-220
    kill_php; sleep "$COOLDOWN"   # let the machine settle before the next measurement
}

: > "$OUT"
echo "container OOM kills so far: $(oom_kills)"
for round in $(seq $(( 1 - WARMUP )) "$ROUNDS"); do
    # Warm-up rounds are numbered 0, -1, ...: kept in the file for inspection, left out of every statistic.
    if [ "$round" -le 0 ]; then export BENCH_WARMUP=1; echo "warm-up round (discarded)"; else export BENCH_WARMUP=0; echo "round $round/$ROUNDS"; fi
    for driver in $DRIVERS; do
        for n in $TASKS; do run_one "$round" "$driver" "$n"; done
    done
done
rm -f /usr/local/etc/php/conf.d/zzz-benchmark.ini
echo "container OOM kills at the end: $(oom_kills)"
php summarize.php "$OUT" "$ROUNDS"
