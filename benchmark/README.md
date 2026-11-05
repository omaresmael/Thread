# Benchmark

Laravel's process driver against this package's threads, on the same Laravel app and the same tasks.

- **Drivers:** `Concurrency::driver('process')->run($tasks)` and `Concurrency::driver('thread')->run($tasks)` (default pool: 4 workers), the same call for both.
- **Task sets** (in [`tasks.php`](tasks.php)), each at 10, 100 and 500 tasks:
  - **small**: a tiny CPU task, `array_sum(range(1, 1000))` (500500 per task).
  - **sqlite**: each task loads one customer's 500 orders with the query builder from a SQLite database, imported from [`data/orders.sql`](data/orders.sql) (100 customers × 500 orders).
- **Time:** one `run()` call in a fresh PHP process (the app boots first, untimed). For threads this includes starting and booting the workers; a second, "warm" call on the same pool is measured too.
- **Memory:** the peak combined memory (PSS) of every PHP process involved, sampled every 10 ms: the parent and its child processes for the process driver, the single process for threads.
- **opcache:** the process driver runs with PHP's default `opcache.enable_cli=0`, which boots its child processes faster; threads run with it on, as recommended.
- **Rounds:** first 1 warm-up round, run but discarded (`WARMUP`, kept in the raw results as `"warmup": true`), then 5 measured rounds (`ROUNDS`), with a 5 s pause between measurements (`COOLDOWN`). Every measurement is a fresh PHP process.
- **Summary:** per driver and task count, the median of the successful runs, the fastest–slowest range and how many runs succeeded, e.g. `19.6 s (15.7–53.4 s, 5/5)`; memory as median and max. Two ratios: **typical** = process median ÷ thread median, and **at least** = the process driver's fastest run ÷ the thread driver's slowest run, a minimum that held in every run (memory: the process driver's lowest peak ÷ the thread driver's highest).
- **Environment:** Linux, PHP 8.4 ZTS with `ext-parallel`, in Docker (the package's own test image). Results depend on the CPUs and memory you give Docker.

## Run it

From the repository root, with Docker running:

```bash
# Once: build the image, create a Laravel 13 app in benchmark/app with this package installed, import the orders.
docker compose -f benchmark/docker-compose.yml run --rm bench sh setup.sh

# The benchmark, both sets. Raw results: benchmark/results/small.jsonl and sqlite.jsonl; then a summary per set.
docker compose -f benchmark/docker-compose.yml run --rm bench sh run.sh

# Fewer rounds, no warm-up, a shorter pause, other task counts, one set:
docker compose -f benchmark/docker-compose.yml run --rm -e ROUNDS=1 -e WARMUP=0 -e COOLDOWN=1 -e TASKS="10 100" -e SETS=sqlite bench sh run.sh
```

**Run it on a quiet Mac.** Docker Desktop borrows the Mac's CPUs and memory, so anything else heavy running at the same time (builds, browsers, video calls, other containers) makes the results jump around, and the process driver, with its hundreds of PHP processes, the most. Close what you can, and keep the Mac plugged in.

`bench.php <driver> <tasks> <set>` is one measurement; `run.sh` runs each in a fresh PHP process and copies the current `tasks.php` and `bench.php` into the app, so editing them needs no new setup.

## Failures

Every measurement writes exactly one JSON line, success or failure (`"ok": false` with a `"reason"`). Reasons:

- **out of memory**: the kernel killed processes in the container for going over its memory cap. With 500 tasks the process driver starts 500 PHP processes at once, which can need more memory than Docker has, so the container is capped at **6 GB** (`mem_limit` in `docker-compose.yml`): over it, the kernel stops processes in this container (the measured ones first), not the Docker engine. To raise the cap, give Docker Desktop more memory first (Settings → Resources), then change `mem_limit` and `memswap_limit`.
- **child process timed out (Laravel's 60 s limit)**: Laravel's process driver gives every child process a 60 s timeout (`PendingProcess::$timeout`); under load children run over it and `run()` throws `ProcessTimedOutException`. That is how the process driver behaves, so it counts as a failure of that run.
- **stopped after 15 minutes**: our own limit per measurement (`timeout 900`).
- otherwise the exception's class and message, or the exit code and the first line PHP printed.

The summary shows each cell with how many runs succeeded, e.g. `51.2 s (51.2–51.2 s, 1/5)`. A cell where any run failed is marked `!` and gets no ratio, and every failure is listed with its round and reason (failed warm-ups are listed too, marked as discarded). In the GIF, a driver that failed in any round at a task count crashes: its bar breaks and says why and how often, and the median of the successful runs, if any, is only mentioned in small text.

## The GIFs (optional)

One per set, from `results/<set>.jsonl` (warm-ups left out): bars show the median, thin lines the fastest–slowest run, and the headline the "at least" speed-up with the typical one beside it to `art/benchmark-small.gif` and `art/benchmark-sqlite.gif` (and the last frame as `results/benchmark-<set>-last.png`, plus a mid-crash frame `results/benchmark-<set>-crash.png` when something crashed), using a stock Python image. From the repository root:

```bash
docker run --rm -v "$PWD":/repo -w /repo/benchmark python:3.12-slim \
  sh -c "pip install -q matplotlib pillow && python chart.py --cpus 5"
```

`--cpus` is the number of CPUs Docker had during the run, for the caption; `python chart.py sqlite` makes one set only.
