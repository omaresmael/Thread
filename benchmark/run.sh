#!/bin/sh
# Runs the benchmark: process driver vs threads, for each task set (small, sqlite), at 10, 100 and 500 tasks:
# WARMUP rounds (default 1) that are run but left out of every statistic, then ROUNDS measured rounds (default 5),
# with COOLDOWN seconds (default 5) between measurements. Every measurement is a fresh PHP process and writes exactly
# one JSON line to results/<set>.jsonl, success or failure (warm-ups marked "warmup": true); then a summary per set.
#   ROUNDS=3 WARMUP=0 COOLDOWN=1 TASKS="10 100" SETS="small" sh run.sh
set -e
ROUNDS=${ROUNDS:-5}
WARMUP=${WARMUP:-1}
COOLDOWN=${COOLDOWN:-5}
TASKS=${TASKS:-"10 100 500"}
SETS=${SETS:-"small sqlite"}
cd /package/benchmark
[ -d app/vendor ] || { echo "Run setup.sh first."; exit 1; }
mkdir -p results

# Copy the app off the bind mount: on macOS, file reads through it would slow every PHP boot. The harness files
# are copied over it, so changes to them need no new setup.
rm -rf /tmp/app && cp -r app /tmp/app && cp tasks.php bench.php /tmp/app/

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

measure() {   # <set> <round> <driver> <tasks> <opcache.enable_cli>
    set +e
    echo "opcache.enable_cli=$5" > /usr/local/etc/php/conf.d/zzz-benchmark.ini
    rm -f /tmp/stop /tmp/peak /tmp/out /tmp/err
    OOM_BEFORE=$(oom_kills)
    php sampler.php & SAMPLER=$!
    sleep 0.3
    START=$(date +%s.%N)
    # oom_score_adj 1000 (inherited by the driver's child processes): if the container runs out of memory, the kernel
    # kills the measured PHP processes, not this script, tee or the sampler.
    (echo 1000 > /proc/self/oom_score_adj; cd /tmp/app && exec timeout -k 10 900 php bench.php "$3" "$4" "$1" >/tmp/out 2>/tmp/err)
    CODE=$?
    WALL=$(echo "$(date +%s.%N) $START" | awk '{printf "%.2f", $1 - $2}')
    touch /tmp/stop; wait $SAMPLER 2>/dev/null
    kill_php; sleep 1
    PEAK=$(cat /tmp/peak 2>/dev/null); case "$PEAK" in ''|*[!0-9]*) PEAK=0 ;; esac
    php record.php /tmp/out /tmp/err "$CODE" "$(( $(oom_kills) - OOM_BEFORE ))" "$(( PEAK / 1024 ))" "$5" "$3" "$4" "$1" "$2" "$WALL"
}

# One measurement = exactly one JSON line in results/<set>.jsonl. If the measurement itself got killed before it
# wrote its line, a failure line is written for it here.
run_one() {   # <set> <round> <driver> <tasks> <opcache.enable_cli>
    FILE="results/$1.jsonl"
    LINES=$(wc -l < "$FILE"); OOM_START=$(oom_kills)
    (measure "$@") >> "$FILE" || true
    if [ "$(wc -l < "$FILE")" -le "$LINES" ]; then
        kill_php
        : > /tmp/empty
        php record.php /tmp/empty /tmp/empty -1 "$(( $(oom_kills) - OOM_START ))" 0 "$5" "$3" "$4" "$1" "$2" >> "$FILE"
    fi
    tail -n 1 "$FILE"
    kill_php; sleep "$COOLDOWN"   # let the machine settle before the next measurement
}

echo "container OOM kills so far: $(oom_kills)"
for set in $SETS; do
    : > "results/$set.jsonl"
    for round in $(seq $(( 1 - WARMUP )) "$ROUNDS"); do
        # Warm-up rounds are numbered 0, -1, ...: kept in the file for inspection, left out of every statistic.
        if [ "$round" -le 0 ]; then export BENCH_WARMUP=1; echo "$set: warm-up round (discarded)"; else export BENCH_WARMUP=0; echo "$set: round $round/$ROUNDS"; fi
        # Threads with opcache.enable_cli=1 (recommended); the process driver with PHP's default 0, which boots its children faster.
        for n in $TASKS; do run_one "$set" "$round" thread "$n" 1; done
        for n in $TASKS; do run_one "$set" "$round" process "$n" 0; done
    done
done
echo "container OOM kills at the end: $(oom_kills)"

for set in $SETS; do
    php summarize.php "results/$set.jsonl" "$ROUNDS"
done
