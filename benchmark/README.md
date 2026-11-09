# Benchmark

The thread driver against Laravel's process driver, on the same Laravel app and the same tasks, in three places:

| Flow | Where `run()` is called | Tasks per call | Load |
|---|---|---|---|
| **Command line** | an artisan command, a fresh PHP process per measurement | 10, 50, 100 | one call |
| **Web, classic mode** | a route, FrankenPHP `php_server` (no worker) | 2, 5, 10 | 1, 10, 50 concurrent clients |
| **Web, worker mode** | a route, Laravel Octane on FrankenPHP | 2, 5, 10 | 1, 10, 50 concurrent clients |

- **Drivers:** `Concurrency::driver($driver)->run($tasks)`, the same call for both. `thread` is this package (default pool: up to
  4 workers per PHP process or worker thread); `process` is Laravel's driver, one `php artisan invoke-serialized-closure`
  child process per task, all at once.
- **Tasks** ([`overlay/app/Benchmark/Tasks.php`](overlay/app/Benchmark/Tasks.php)), about 34 ms each when run inline:
  - **wait (I/O-bound):** one query to MySQL, in its own container:
    `select sleep(0.03), (select count(*) from orders where customer_id = 1)`. PHP waits 30 ms for the server and uses no
    CPU meanwhile; it stands for what most web tasks do: wait on a database server or an API. 34.7 ms inline.
  - **report (CPU-bound):** a monthly revenue report over all 50,000 orders, grouped by customer and month with the query
    builder (count, sum, average, max, refunded total and distinct statuses per group; a full scan, 1,200 rows back).
    SQLite runs inside PHP, so the whole task is CPU work. 34.4 ms inline.

  Every task in a call does the same work, and every result is checked (a wrong result is a failure). The orders come
  from [`data/orders.sql`](data/orders.sql) (100 customers × 500 orders), imported into SQLite and into MySQL.
- **Which ran where:** every flow ran both tasks.
- **Environment:** one image for every flow ([`docker/Dockerfile`](docker/Dockerfile)): the official FrankenPHP image
  (FrankenPHP 1.13, PHP 8.4 ZTS, stock php.ini settings) plus `ext-parallel`, `pcntl` and `pdo_mysql`. The app is a
  fresh Laravel 13 app with this package (from this repository) and Octane, `APP_ENV=production`, `APP_DEBUG=false`,
  sessions and cache in memory, and `php artisan optimize` run before every flow, as a deploy would. MySQL 8.0 runs in
  its own container with `max_connections=1000` (the process driver opens one connection per task; MySQL's default of
  151 would fail it before the drivers are compared), performance_schema off and a small buffer pool, data in memory.
  The results below are from Docker Desktop with 5 CPUs on an Apple Silicon Mac.

## Set up (once)

From the repository root, with Docker running:

```bash
docker build -t thread-benchmark:frankenphp benchmark/docker
docker compose -f benchmark/docker-compose.yml run --rm cli sh setup.sh
```

(`docker compose -f benchmark/docker-compose.yml build` builds the same image; on Docker Desktop it was seen to hang,
`docker build` did not.)

`setup.sh` creates `benchmark/app` (git-ignored): `composer create-project laravel/laravel`, this package through a
Composer path repository, `laravel/octane` with `octane:install --server=frankenphp`, the `.env` settings above, and the
orders imported into SQLite. MySQL loads the orders itself at every start (`docker/mysql-init.sh`). Every flow copies
the app into its container first (served from the macOS bind mount, every PHP boot is about 10× slower) and lays
[`overlay/`](overlay) over it: the tasks, the `/bench` route and its controller, the `benchmark:run` command and the
MySQL connection. Editing those needs no new setup.

## Command line

```bash
docker compose -f benchmark/docker-compose.yml run --rm cli sh run-cli.sh
# fewer rounds, no warm-up, a shorter pause, other task counts or drivers:
docker compose -f benchmark/docker-compose.yml run --rm -e ROUNDS=1 -e WARMUP=0 -e COOLDOWN=1 -e TASKS="10" -e DRIVERS="thread" cli sh run-cli.sh
# the wait task (I/O-bound): start MySQL first
docker compose -f benchmark/docker-compose.yml up -d mysql
docker compose -f benchmark/docker-compose.yml run --rm -e TASK=wait cli sh run-cli.sh
```

- One measurement is `php artisan benchmark:run <driver> <tasks>` in a fresh PHP process: the app boots (not timed),
  then one `run()` call is timed. For threads that includes starting and booting the workers; a second call on the
  booted pool is timed too ("warm").
- **Memory:** the peak combined PSS of every PHP process in the container (the command and the process driver's
  children), sampled every 10 ms plus the time a sweep takes (`memory_samples` and `memory_seconds` are recorded).
- **opcache:** threads run with `opcache.enable_cli=1`, as the package recommends; the process driver with PHP's default
  `opcache.enable_cli=0`, which boots its child processes faster.
- Raw results: `results/cli.jsonl` (report task) and `results/cli-wait.jsonl` (wait task).

## Web (classic and worker mode)

Run from the host (the script drives the containers):

```bash
bash benchmark/run-web.sh classic               # report task (CPU-bound)
bash benchmark/run-web.sh worker
TASK=wait bash benchmark/run-web.sh classic     # wait task (I/O-bound): starts the mysql container too
TASK=wait bash benchmark/run-web.sh worker
# smaller: one round, no warm-up, a shorter pause, fewer cells, fewer requests
ROUNDS=1 WARMUP=0 COOLDOWN=1 TASKS=2 CLIENTS="1 10" DRIVERS=thread REQUESTS_10=20 bash benchmark/run-web.sh worker
```

- **Servers**, with their defaults and the same settings for both drivers:
  - classic: the image's own Caddyfile (`php_server`, no worker); FrankenPHP's default `num_threads` (2 × CPUs: 10 PHP
    threads with 5 CPUs). php.ini's `max_execution_time` (30 s) applies.
  - worker: `php artisan octane:frankenphp` with Octane's defaults: `--workers=auto` (FrankenPHP's default, 2 × CPUs:
    10 worker threads with 5 CPUs) and `--max-requests=500`. Each worker thread keeps its own thread pool between
    requests; in classic mode every request starts its own pool, which ends with the request.
  - opcache is on for the web SAPI (the image's default). Thread workers share it. The process driver's children are
    `php` CLI processes with PHP's default `opcache.enable_cli=0`.
  - `results/web-<mode>[-wait]-server.jsonl` records each server start: PHP, SAPI, opcache, limits, FrankenPHP's
    thread list.
- **The route:** `GET /bench?driver=<thread|process>&tasks=<n>&task=<report|wait>` runs the tasks with one `run()` call
  and answers JSON with `ok` (all results checked) and the call's time. An exception answers 500 with the reason.
- **Load client** ([`load.php`](load.php)): its own container (`load`, 512 MB) on the same Docker network, curl_multi
  with a fixed number of requests in flight. Per measurement: 1 client → 30 sequential requests, 10 clients → 100
  requests, 50 clients → 200 requests. A request fails when it times out (120 s), cannot connect, gets a status other
  than 200, or the body is not `"ok": true`. Every request's latency, status and body go to `results/raw/`.
- **Per measurement:** p50 latency (the median request), requests per second (requests ÷ the measurement's wall time), failed
  requests and their reasons, and the server's peak memory: the combined PSS of every `frankenphp` and `php` process in
  the server container (the server, its PHP threads and thread workers, Octane's parent process, the process driver's
  children), sampled every 10 ms plus the sweep time.
- **Order:** for each driver, a freshly started server (container stopped and started: the app is copied anew), then
  1 warm-up round (discarded) and 5 rounds; a round runs every task count at every client count. The drivers are not
  interleaved, so one driver's resident thread pools can't inflate the other's memory.
- Raw results: `results/web-<mode>.jsonl` (report task) and `results/web-<mode>-wait.jsonl` (wait task).

## Rules for every flow

- 1 warm-up round, run but left out of every statistic (kept in the raw file as `"warmup": true`), then 5 rounds
  (`WARMUP`, `ROUNDS`), with a 5 s pause after every measurement (`COOLDOWN`). Leftover PHP processes are killed between
  measurements.
- Every measurement writes exactly one JSON line, success or failure, never dropped. A failed measurement has `"ok":
  false` and a `"reason"`: out of memory (the container's kernel OOM counter went up), a crashed server, failed
  requests (with the count and the reasons), Laravel's 60 s child-process timeout, or the exception.
- `summarize.php` prints the Markdown tables below: per cell the median of the successful runs, the fastest–slowest
  range and how many runs succeeded (`1.1 s (1.0–1.2 s, 5/5)`), and the thread driver against the process driver:
  **typically** = medians, **at least** = the process driver's best run against the thread driver's worst (for req/s
  the other way round), a minimum that held in every run. A cell where any run failed is marked `!` and gets no ratio;
  every failure is listed.

  ```bash
  docker compose -f benchmark/docker-compose.yml run --rm --no-deps load php summarize.php results/web-worker-wait.jsonl
  ```

- **Memory caps:** the server and CLI containers are capped at 5 GB (`mem_limit` and `memswap_limit`), MySQL at 1 GB,
  the load client at 512 MB, so a driver that runs out of memory takes down only its own container's processes, and it
  is recorded.

**Run it on a quiet machine.** Docker Desktop borrows the Mac's CPUs and memory; anything else heavy running at the same
time makes the results jump around, the process driver's most. These results were taken on a developer machine that
was not idle; the fastest–slowest ranges show how much each cell moved.

## Results

420 measured runs (60 on the command line, 360 on the web server); 419 succeeded.

Each cell is the median of the runs that succeeded, with the fastest–slowest run and how many succeeded in brackets
(`1.1 s (1.0–1.2 s, 5/5)`). Latency is the median request of a run. The last column compares the thread driver with the
process driver: **typically** divides their medians; **at least** divides the process driver's best run by the thread
driver's worst (for requests per second, the other way round), a gap that held in every run.

### Command line

#### Wait task (I/O-bound) — 5 rounds (median, fastest–slowest, runs that succeeded), 6 warm-up measurements discarded

| Tasks | thread | process | thread vs process |
|:---|:---|:---|:---|
| 10 | 134 ms (132–141 ms, 5/5) | 501 ms (455–553 ms, 5/5) | at least 3.2× faster (typically 3.8×) |
| 50 | 443 ms (440–451 ms, 5/5) | 1.93 s (1.78–1.99 s, 5/5) | at least 4.0× faster (typically 4.4×) |
| 100 | 827 ms (819–835 ms, 5/5) | 4.40 s (3.98–4.97 s, 5/5) | at least 4.8× faster (typically 5.3×) |

**Peak memory, all PHP processes (median, lowest–highest)**

| Tasks | thread | process | thread vs process |
|:---|:---|:---|:---|
| 10 | 56 MB (56–57 MB, 5/5) | 348 MB (336–367 MB, 5/5) | at least 5.9× less (typically 6.2×) |
| 50 | 56 MB (56 MB, 5/5) | 989 MB (893–1,119 MB, 5/5) | at least 16× less (typically 18×) |
| 100 | 57 MB (56–57 MB, 5/5) | 1,860 MB (1,716–2,048 MB, 5/5) | at least 30× less (typically 33×) |

Threads once warm (the same call again on the booted pool, median): 10 tasks 95 ms, 50 tasks 413 ms, 100 tasks 791 ms.

No failures.

#### Report task (CPU-bound) — 5 rounds (median, fastest–slowest, runs that succeeded), 6 warm-up measurements discarded

| Tasks | thread | process | thread vs process |
|:---|:---|:---|:---|
| 10 | 248 ms (224–297 ms, 5/5) | 625 ms (585–724 ms, 5/5) | at least 2.0× faster (typically 2.5×) |
| 50 | 659 ms (618–777 ms, 5/5) | 2.81 s (2.50–3.37 s, 5/5) | at least 3.2× faster (typically 4.3×) |
| 100 | 1.20 s (1.17–1.35 s, 5/5) | 7.44 s (6.77–8.65 s, 5/5) | at least 5.0× faster (typically 6.2×) |

**Peak memory, all PHP processes (median, lowest–highest)**

| Tasks | thread | process | thread vs process |
|:---|:---|:---|:---|
| 10 | 79 MB (79 MB, 5/5) | 392 MB (385–442 MB, 5/5) | at least 4.9× less (typically 5.0×) |
| 50 | 79 MB (79 MB, 5/5) | 1,426 MB (1,330–1,566 MB, 5/5) | at least 17× less (typically 18×) |
| 100 | 79 MB (79 MB, 5/5) | 2,831 MB (2,728–3,017 MB, 5/5) | at least 35× less (typically 36×) |

Threads once warm (the same call again on the booted pool, median): 10 tasks 132 ms, 50 tasks 568 ms, 100 tasks 1.09 s.

No failures.

### Web, worker mode (Octane)

#### Wait task (I/O-bound) — 5 rounds (median, fastest–slowest, runs that succeeded), 18 warm-up measurements discarded

**p50 latency (median of the runs' p50)**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 35 ms (34–36 ms, 5/5) | 161 ms (158–178 ms, 5/5) | at least 4.4× faster (typically 4.6×) |
| 2 | 10 | 51 ms (48–57 ms, 5/5) | 605 ms (587–782 ms, 5/5) | at least 10× faster (typically 12×) |
| 2 | 50 | 177 ms (176–180 ms, 5/5) | 3.06 s (2.90–3.65 s, 5/5) | at least 16× faster (typically 17×) |
| 5 | 1 | 66 ms (66–68 ms, 5/5) | 205 ms (192–254 ms, 5/5) | at least 2.8× faster (typically 3.1×) |
| 5 | 10 | 79 ms (78–82 ms, 5/5) | 2.08 s (1.52–2.34 s, 5/5) | at least 18× faster (typically 26×) |
| 5 | 50 | 335 ms (330–380 ms, 5/5) | 9.60 s (7.91–11.1 s, 5/5) | at least 21× faster (typically 29×) |
| 10 | 1 | 99 ms (98–100 ms, 5/5) | 341 ms (329–381 ms, 5/5) | at least 3.3× faster (typically 3.5×) |
| 10 | 10 | 114 ms (111–115 ms, 5/5) | 5.26 s (4.79–5.66 s, 5/5) | at least 42× faster (typically 46×) |
| 10 | 50 | 496 ms (495–502 ms, 5/5) | 25.8 s (23.3–33.2 s, 5/5) | at least 46× faster (typically 52×) |

**Requests per second**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 26 (24–26, 5/5) | 5.9 (5.2–6.1, 5/5) | at least 4.0× faster (typically 4.4×) |
| 2 | 10 | 164 (157–183, 5/5) | 16 (9.7–16, 5/5) | at least 9.7× faster (typically 11×) |
| 2 | 50 | 248 (234–253, 5/5) | 15 (13–17, 5/5) | at least 14× faster (typically 16×) |
| 5 | 1 | 15 (14–15, 5/5) | 4.6 (3.7–5.0, 5/5) | at least 2.8× faster (typically 3.2×) |
| 5 | 10 | 111 (110–116, 5/5) | 4.6 (4.2–6.1, 5/5) | at least 18× faster (typically 24×) |
| 5 | 50 | 137 (116–145, 5/5) | 4.8 (4.2–5.7, 5/5) | at least 20× faster (typically 29×) |
| 10 | 1 | 9.7 (9.1–9.8, 5/5) | 2.8 (2.2–2.9, 5/5) | at least 3.1× faster (typically 3.4×) |
| 10 | 10 | 81 (79–82, 5/5) | 1.8 (1.6–2.0, 5/5) | at least 39× faster (typically 44×) |
| 10 | 50 | 94 (92–96, 5/5) | 1.9 (1.5–2.1, 5/5) | at least 44× faster (typically 51×) |


No failures.

#### Report task (CPU-bound) — 5 rounds (median, fastest–slowest, runs that succeeded), 18 warm-up measurements discarded

**p50 latency (median of the runs' p50)**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 45 ms (44–46 ms, 5/5) | 171 ms (168–172 ms, 5/5) | at least 3.7× faster (typically 3.8×) |
| 2 | 10 | 238 ms (228–239 ms, 5/5) | 779 ms (775–806 ms, 5/5) | at least 3.2× faster (typically 3.3×) |
| 2 | 50 | 1.12 s (1.04–1.13 s, 5/5) | 3.96 s (3.91–4.06 s, 5/5) | at least 3.4× faster (typically 3.5×) |
| 5 | 1 | 85 ms (85–86 ms, 5/5) | 224 ms (219–242 ms, 5/5) | at least 2.5× faster (typically 2.6×) |
| 5 | 10 | 550 ms (515–553 ms, 5/5) | 1.97 s (1.87–2.58 s, 5/5) | at least 3.4× faster (typically 3.6×) |
| 5 | 50 | 2.68 s (2.63–2.89 s, 5/5) | 9.83 s (9.50–12.4 s, 5/5) | at least 3.3× faster (typically 3.7×) |
| 10 | 1 | 130 ms (129–132 ms, 5/5) | 427 ms (408–473 ms, 5/5) | at least 3.1× faster (typically 3.3×) |
| 10 | 10 | 1.08 s (1.04–1.11 s, 5/5) | 6.87 s (6.26–7.54 s, 5/5) | at least 5.6× faster (typically 6.4×) |
| 10 | 50 | 5.29 s (5.22–6.16 s, 5/5) | 35.1 s (30.1–38.5 s, 5/5) | at least 4.9× faster (typically 6.6×) |

**Requests per second**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 21 (20–21, 5/5) | 5.6 (5.5–5.7, 5/5) | at least 3.5× faster (typically 3.7×) |
| 2 | 10 | 41 (40–42, 5/5) | 12 (12–13, 5/5) | at least 3.2× faster (typically 3.3×) |
| 2 | 50 | 43 (41–46, 5/5) | 12 (12–13, 5/5) | at least 3.3× faster (typically 3.6×) |
| 5 | 1 | 11 (11–12, 5/5) | 4.4 (3.8–4.4, 5/5) | at least 2.4× faster (typically 2.6×) |
| 5 | 10 | 18 (18, 5/5) | 4.8 (3.7–5.0, 5/5) | at least 3.6× faster (typically 3.8×) |
| 5 | 50 | 18 (17–18, 5/5) | 4.8 (4.0–5.1, 5/5) | at least 3.3× faster (typically 3.7×) |
| 10 | 1 | 7.5 (7.4–7.5, 5/5) | 2.3 (2.0–2.4, 5/5) | at least 3.0× faster (typically 3.3×) |
| 10 | 10 | 9.3 (9.0–9.6, 5/5) | 1.5 (1.3–1.6, 5/5) | at least 5.7× faster (typically 6.4×) |
| 10 | 50 | 9.2 (7.4–9.5, 5/5) | 1.4 (1.2–1.6, 5/5) | at least 4.6× faster (typically 6.7×) |


No failures.

### Web, classic mode

#### Wait task (I/O-bound) — 5 rounds (median, fastest–slowest, runs that succeeded), 18 warm-up measurements discarded

**p50 latency (median of the runs' p50)**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 45 ms (43–47 ms, 5/5) | 170 ms (165–185 ms, 5/5) | at least 3.5× faster (typically 3.8×) |
| 2 | 10 | 74 ms (70–82 ms, 5/5) | 712 ms (582–981 ms, 5/5) | at least 7.1× faster (typically 9.7×) |
| 2 | 50 | 440 ms (346–465 ms, 5/5) | 3.21 s (2.90–4.29 s, 5/5) | at least 6.2× faster (typically 7.3×) |
| 5 | 1 | 75 ms (74–79 ms, 5/5) | 205 ms (190–235 ms, 5/5) | at least 2.4× faster (typically 2.7×) |
| 5 | 10 | 164 ms (133–170 ms, 5/5) | 1.87 s (1.55–2.83 s, 5/5) | at least 9.1× faster (typically 11×) |
| 5 | 50 | 713 ms (682–846 ms, 5/5) | 9.98 s (7.45–15.8 s, 5/5) | at least 8.8× faster (typically 14×) |
| 10 | 1 | 109 ms (106–113 ms, 5/5) | 367 ms (335–432 ms, 5/5) | at least 3.0× faster (typically 3.4×) |
| 10 | 10 | 157 ms (151–187 ms, 5/5) | 6.09 s (4.94–7.63 s, 5/5) | at least 26× faster (typically 39×) |
| 10 | 50 | 829 ms (731 ms–1.02 s, 5/5) | 33.1 s (24.1–42.5 s, 5/5) | at least 24× faster (typically 40×) |

**Requests per second**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 19 (18–20, 5/5) | 5.4 (5.1–5.7, 5/5) | at least 3.1× faster (typically 3.6×) |
| 2 | 10 | 115 (101–117, 5/5) | 13 (9.6–16, 5/5) | at least 6.2× faster (typically 8.9×) |
| 2 | 50 | 94 (90–129, 5/5) | 15 (11–17, 5/5) | at least 5.4× faster (typically 6.3×) |
| 5 | 1 | 13 (11–13, 5/5) | 4.7 (3.8–5.1, 5/5) | at least 2.3× faster (typically 2.7×) |
| 5 | 10 | 55 (46–67, 5/5) | 5.1 (3.4–6.2, 5/5) | at least 7.4× faster (typically 11×) |
| 5 | 50 | 62 (57–67, 5/5) | 5.0 (3.0–6.3, 5/5) | at least 9.2× faster (typically 12×) |
| 10 | 1 | 8.7 (8.3–9.0, 5/5) | 2.4 (2.2–2.9, 5/5) | at least 2.8× faster (typically 3.6×) |
| 10 | 10 | 56 (47–59, 5/5) | 1.6 (1.3–1.9, 5/5) | at least 24× faster (typically 36×) |
| 10 | 50 | 54 (45–65, 5/5) | 1.5 (1.1–2.0, 5/5) | at least 23× faster (typically 37×) |


No failures.

#### Report task (CPU-bound) — 5 rounds (median, fastest–slowest, runs that succeeded), 18 warm-up measurements discarded

**p50 latency (median of the runs' p50)**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 50 ms (50–51 ms, 5/5) | 166 ms (166–215 ms, 5/5) | at least 3.3× faster (typically 3.3×) |
| 2 | 10 | 276 ms (272–284 ms, 5/5) | 779 ms (773 ms–1.27 s, 5/5) | at least 2.7× faster (typically 2.8×) |
| 2 | 50 | 1.39 s (1.37–1.43 s, 5/5) | 3.91 s (3.90–7.58 s, 5/5) | at least 2.7× faster (typically 2.8×) |
| 5 | 1 | 93 ms (91–96 ms, 5/5) | 212 ms (209–264 ms, 5/5) | at least 2.2× faster (typically 2.3×) |
| 5 | 10 | 629 ms (615–684 ms, 5/5) | 2.00 s (1.84–3.88 s, 5/5) | at least 2.7× faster (typically 3.2×) |
| 5 | 50 | 3.10 s (3.07–3.15 s, 5/5) | 9.64 s (9.22–19.5 s, 5/5) | at least 2.9× faster (typically 3.1×) |
| 10 | 1 | 136 ms (135–137 ms, 5/5) | 439 ms (398–770 ms, 5/5) | at least 2.9× faster (typically 3.2×) |
| 10 | 10 | 1.16 s (1.15–1.17 s, 5/5) | ! 7.88 s (6.09–11.3 s, 4/5) | – |
| 10 | 50 | 5.76 s (5.72–7.57 s, 5/5) | 33.1 s (31.3–51.1 s, 5/5) | at least 4.1× faster (typically 5.8×) |

**Requests per second**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 18 (18–19, 5/5) | 5.8 (4.4–5.8, 5/5) | at least 3.0× faster (typically 3.2×) |
| 2 | 10 | 35 (33–36, 5/5) | 12 (7.5–13, 5/5) | at least 2.6× faster (typically 2.8×) |
| 2 | 50 | 35 (33–35, 5/5) | 13 (7.1–13, 5/5) | at least 2.6× faster (typically 2.7×) |
| 5 | 1 | 10 (9.8–11, 5/5) | 4.6 (3.4–4.7, 5/5) | at least 2.1× faster (typically 2.2×) |
| 5 | 10 | 16 (14–16, 5/5) | 4.7 (2.7–5.1, 5/5) | at least 2.8× faster (typically 3.3×) |
| 5 | 50 | 16 (15–16, 5/5) | 4.8 (2.7–5.3, 5/5) | at least 2.9× faster (typically 3.3×) |
| 10 | 1 | 7.1 (7.0–7.2, 5/5) | 2.1 (1.3–2.5, 5/5) | at least 2.8× faster (typically 3.4×) |
| 10 | 10 | 8.5 (8.4–8.6, 5/5) | ! 1.2 (0.9–1.6, 4/5) | – |
| 10 | 50 | 8.5 (6.8–8.6, 5/5) | 1.4 (1.0–1.6, 5/5) | at least 4.3× faster (typically 6.1×) |

"!" = at least one of the cell's runs failed: its median is from the runs that succeeded, and it gets no ratio.

Failures (1):
- round 4: process, 10 tasks, 10 clients: 20 of 100 requests failed: HTTP 500 `{"message": "Server Error"}` (20×)
  (peak 5413 MB, at the server container's 5 GB cap)
