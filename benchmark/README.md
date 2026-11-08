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
- **Per measurement:** p50 and p95 latency, requests per second (requests ÷ the measurement's wall time), failed
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
"!" = at least one run failed: not a clean result, no ratio. typically = medians; at least = the other driver's best run ÷ the thread driver's worst.

No failures.

#### Report task (CPU-bound) — 5 rounds (median, fastest–slowest, runs that succeeded), 6 warm-up measurements discarded

| Tasks | thread | process | thread vs process |
|:---|:---|:---|:---|
| 10 | 218 ms (207–228 ms, 5/5) | 537 ms (524–561 ms, 5/5) | at least 2.3× faster (typically 2.5×) |
| 50 | 614 ms (605–656 ms, 5/5) | 2.36 s (2.30–2.42 s, 5/5) | at least 3.5× faster (typically 3.8×) |
| 100 | 1.11 s (1.10–1.20 s, 5/5) | 4.63 s (4.32–5.41 s, 5/5) | at least 3.6× faster (typically 4.2×) |

**Peak memory, all PHP processes (median, lowest–highest)**

| Tasks | thread | process | thread vs process |
|:---|:---|:---|:---|
| 10 | 79 MB (79 MB, 5/5) | 395 MB (378–402 MB, 5/5) | at least 4.8× less (typically 5.0×) |
| 50 | 79 MB (79 MB, 5/5) | 1,263 MB (1,224–1,338 MB, 5/5) | at least 15× less (typically 16×) |
| 100 | 79 MB (79 MB, 5/5) | 2,299 MB (1,983–2,645 MB, 5/5) | at least 25× less (typically 29×) |

Threads once warm (the same call again on the booted pool, median): 10 tasks 125 ms, 50 tasks 539 ms, 100 tasks 1.04 s.
"!" = at least one run failed: not a clean result, no ratio. typically = medians; at least = the other driver's best run ÷ the thread driver's worst.

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

**p95 latency (median of the runs' p95)**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 38 ms (37–68 ms, 5/5) | 191 ms (182–252 ms, 5/5) | at least 2.7× faster (typically 5.1×) |
| 2 | 10 | 91 ms (85–117 ms, 5/5) | 911 ms (853 ms–1.98 s, 5/5) | at least 7.3× faster (typically 10×) |
| 2 | 50 | 242 ms (225–242 ms, 5/5) | 3.47 s (3.11–4.34 s, 5/5) | at least 13× faster (typically 14×) |
| 5 | 1 | 71 ms (70–73 ms, 5/5) | 281 ms (225–370 ms, 5/5) | at least 3.1× faster (typically 4.0×) |
| 5 | 10 | 127 ms (122–134 ms, 5/5) | 2.97 s (2.35–3.57 s, 5/5) | at least 17× faster (typically 23×) |
| 5 | 50 | 376 ms (352–498 ms, 5/5) | 12.5 s (9.50–13.7 s, 5/5) | at least 19× faster (typically 33×) |
| 10 | 1 | 123 ms (103–156 ms, 5/5) | 436 ms (361–801 ms, 5/5) | at least 2.3× faster (typically 3.5×) |
| 10 | 10 | 161 ms (156–167 ms, 5/5) | 7.26 s (6.55–8.89 s, 5/5) | at least 39× faster (typically 45×) |
| 10 | 50 | 529 ms (521–577 ms, 5/5) | 27.6 s (25.7–39.5 s, 5/5) | at least 44× faster (typically 52×) |

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

**Errors (failed requests, all measured runs) and peak server memory (median, lowest–highest)**

| Tasks | Clients | thread errors | process errors | thread memory | process memory | thread vs process |
|:---|:---|:---|:---|:---|:---|:---|
| 2 | 1 | 0 | 0 | 265 MB (259–275 MB, 5/5) | 259 MB (258–260 MB, 5/5) | typically 1.0× more, not in every run |
| 2 | 10 | 0 | 0 | 265 MB (259–275 MB, 5/5) | 737 MB (682–790 MB, 5/5) | at least 2.5× less (typically 2.8×) |
| 2 | 50 | 0 | 0 | 266 MB (259–276 MB, 5/5) | 717 MB (686–749 MB, 5/5) | at least 2.5× less (typically 2.7×) |
| 5 | 1 | 0 | 0 | 266 MB (259–276 MB, 5/5) | 370 MB (368–399 MB, 5/5) | at least 1.3× less (typically 1.4×) |
| 5 | 10 | 0 | 0 | 267 MB (260–276 MB, 5/5) | 1,608 MB (1,569–1,633 MB, 5/5) | at least 5.7× less (typically 6.0×) |
| 5 | 50 | 0 | 0 | 266 MB (261–273 MB, 5/5) | 1,602 MB (1,587–1,605 MB, 5/5) | at least 5.8× less (typically 6.0×) |
| 10 | 1 | 0 | 0 | 266 MB (250–272 MB, 5/5) | 531 MB (511–548 MB, 5/5) | at least 1.9× less (typically 2.0×) |
| 10 | 10 | 0 | 0 | 264 MB (250–273 MB, 5/5) | 2,986 MB (2,882–3,036 MB, 5/5) | at least 11× less (typically 11×) |
| 10 | 50 | 0 | 0 | 265 MB (247–275 MB, 5/5) | 2,936 MB (2,914–3,030 MB, 5/5) | at least 11× less (typically 11×) |

"!" = at least one run failed: not a clean result, no ratio. typically = medians; at least = the other driver's best run ÷ the thread driver's worst.

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

**p95 latency (median of the runs' p95)**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 52 ms (51–57 ms, 5/5) | 200 ms (180–219 ms, 5/5) | at least 3.1× faster (typically 3.8×) |
| 2 | 10 | 339 ms (328–353 ms, 5/5) | 1.07 s (1.04–1.12 s, 5/5) | at least 2.9× faster (typically 3.2×) |
| 2 | 50 | 1.21 s (1.13–1.32 s, 5/5) | 4.24 s (4.09–4.36 s, 5/5) | at least 3.1× faster (typically 3.5×) |
| 5 | 1 | 91 ms (89–119 ms, 5/5) | 256 ms (241–367 ms, 5/5) | at least 2.0× faster (typically 2.8×) |
| 5 | 10 | 761 ms (687–813 ms, 5/5) | 2.89 s (2.60–3.71 s, 5/5) | at least 3.2× faster (typically 3.8×) |
| 5 | 50 | 3.00 s (2.92–3.32 s, 5/5) | 11.0 s (10.2–14.1 s, 5/5) | at least 3.1× faster (typically 3.7×) |
| 10 | 1 | 145 ms (135–148 ms, 5/5) | 497 ms (426–751 ms, 5/5) | at least 2.9× faster (typically 3.4×) |
| 10 | 10 | 1.31 s (1.25–1.34 s, 5/5) | 7.92 s (6.76–12.6 s, 5/5) | at least 5.1× faster (typically 6.0×) |
| 10 | 50 | 5.80 s (5.55–8.49 s, 5/5) | 40.9 s (33.4–54.0 s, 5/5) | at least 3.9× faster (typically 7.1×) |

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

**Errors (failed requests, all measured runs) and peak server memory (median, lowest–highest)**

| Tasks | Clients | thread errors | process errors | thread memory | process memory | thread vs process |
|:---|:---|:---|:---|:---|:---|:---|
| 2 | 1 | 0 | 0 | 507 MB (474–565 MB, 5/5) | 268 MB (267–269 MB, 5/5) | at least 1.8× more (typically 1.9×) |
| 2 | 10 | 0 | 0 | 507 MB (476–565 MB, 5/5) | 857 MB (817–869 MB, 5/5) | at least 1.4× less (typically 1.7×) |
| 2 | 50 | 0 | 0 | 506 MB (477–566 MB, 5/5) | 888 MB (803–902 MB, 5/5) | at least 1.4× less (typically 1.8×) |
| 5 | 1 | 0 | 0 | 506 MB (477–567 MB, 5/5) | 400 MB (399–401 MB, 5/5) | at least 1.2× more (typically 1.3×) |
| 5 | 10 | 0 | 0 | 506 MB (482–567 MB, 5/5) | 1,960 MB (1,958–1,960 MB, 5/5) | at least 3.5× less (typically 3.9×) |
| 5 | 50 | 0 | 0 | 526 MB (479–560 MB, 5/5) | 1,959 MB (1,941–2,043 MB, 5/5) | at least 3.5× less (typically 3.7×) |
| 10 | 1 | 0 | 0 | 527 MB (477–538 MB, 5/5) | 570 MB (557–573 MB, 5/5) | at least 1.0× less (typically 1.1×) |
| 10 | 10 | 0 | 0 | 539 MB (490–557 MB, 5/5) | 3,699 MB (3,584–3,711 MB, 5/5) | at least 6.4× less (typically 6.9×) |
| 10 | 50 | 0 | 0 | 544 MB (491–565 MB, 5/5) | 3,688 MB (3,664–3,702 MB, 5/5) | at least 6.5× less (typically 6.8×) |

"!" = at least one run failed: not a clean result, no ratio. typically = medians; at least = the other driver's best run ÷ the thread driver's worst.

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

**p95 latency (median of the runs' p95)**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 55 ms (52–130 ms, 5/5) | 209 ms (193–300 ms, 5/5) | at least 1.5× faster (typically 3.8×) |
| 2 | 10 | 173 ms (152–176 ms, 5/5) | 1.16 s (884 ms–1.45 s, 5/5) | at least 5.0× faster (typically 6.7×) |
| 2 | 50 | 655 ms (438–719 ms, 5/5) | 3.54 s (3.18–4.87 s, 5/5) | at least 4.4× faster (typically 5.4×) |
| 5 | 1 | 82 ms (79–96 ms, 5/5) | 252 ms (230–352 ms, 5/5) | at least 2.4× faster (typically 3.1×) |
| 5 | 10 | 236 ms (207–357 ms, 5/5) | 2.61 s (2.24–3.49 s, 5/5) | at least 6.3× faster (typically 11×) |
| 5 | 50 | 871 ms (796 ms–1.02 s, 5/5) | 11.7 s (8.76–19.0 s, 5/5) | at least 8.6× faster (typically 13×) |
| 10 | 1 | 123 ms (114–146 ms, 5/5) | 477 ms (356–648 ms, 5/5) | at least 2.4× faster (typically 3.9×) |
| 10 | 10 | 267 ms (248–343 ms, 5/5) | 8.58 s (5.75–9.87 s, 5/5) | at least 17× faster (typically 32×) |
| 10 | 50 | 1.01 s (785 ms–1.80 s, 5/5) | 39.6 s (26.5–53.5 s, 5/5) | at least 15× faster (typically 39×) |

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

**Errors (failed requests, all measured runs) and peak server memory (median, lowest–highest)**

| Tasks | Clients | thread errors | process errors | thread memory | process memory | thread vs process |
|:---|:---|:---|:---|:---|:---|:---|
| 2 | 1 | 0 | 0 | 145 MB (144–148 MB, 5/5) | 177 MB (175–177 MB, 5/5) | at least 1.2× less (typically 1.2×) |
| 2 | 10 | 0 | 0 | 160 MB (158–164 MB, 5/5) | 678 MB (647–683 MB, 5/5) | at least 3.9× less (typically 4.2×) |
| 2 | 50 | 0 | 0 | 160 MB (158–164 MB, 5/5) | 644 MB (610–655 MB, 5/5) | at least 3.7× less (typically 4.0×) |
| 5 | 1 | 0 | 0 | 147 MB (146–151 MB, 5/5) | 289 MB (287–292 MB, 5/5) | at least 1.9× less (typically 2.0×) |
| 5 | 10 | 0 | 0 | 177 MB (177–180 MB, 5/5) | 1,495 MB (1,441–1,541 MB, 5/5) | at least 8.0× less (typically 8.4×) |
| 5 | 50 | 0 | 0 | 180 MB (175–183 MB, 5/5) | 1,507 MB (1,467–1,523 MB, 5/5) | at least 8.0× less (typically 8.4×) |
| 10 | 1 | 0 | 0 | 147 MB (145–151 MB, 5/5) | 453 MB (449–463 MB, 5/5) | at least 3.0× less (typically 3.1×) |
| 10 | 10 | 0 | 0 | 180 MB (179–183 MB, 5/5) | 2,719 MB (2,573–2,868 MB, 5/5) | at least 14× less (typically 15×) |
| 10 | 50 | 0 | 0 | 181 MB (180–184 MB, 5/5) | 2,766 MB (2,563–2,879 MB, 5/5) | at least 14× less (typically 15×) |

"!" = at least one run failed: not a clean result, no ratio. typically = medians; at least = the other driver's best run ÷ the thread driver's worst.

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

**p95 latency (median of the runs' p95)**

| Tasks | Clients | thread | process | thread vs process |
|:---|:---|:---|:---|:---|
| 2 | 1 | 55 ms (54–59 ms, 5/5) | 189 ms (172–258 ms, 5/5) | at least 2.9× faster (typically 3.4×) |
| 2 | 10 | 390 ms (347–438 ms, 5/5) | 1.11 s (976 ms–1.97 s, 5/5) | at least 2.2× faster (typically 2.8×) |
| 2 | 50 | 1.54 s (1.51–1.59 s, 5/5) | 4.16 s (4.08–8.47 s, 5/5) | at least 2.6× faster (typically 2.7×) |
| 5 | 1 | 104 ms (97–122 ms, 5/5) | 243 ms (227–401 ms, 5/5) | at least 1.9× faster (typically 2.3×) |
| 5 | 10 | 823 ms (778–892 ms, 5/5) | 2.80 s (2.67–4.35 s, 5/5) | at least 3.0× faster (typically 3.4×) |
| 5 | 50 | 3.36 s (3.31–3.42 s, 5/5) | 10.9 s (9.64–20.5 s, 5/5) | at least 2.8× faster (typically 3.2×) |
| 10 | 1 | 152 ms (147–162 ms, 5/5) | 677 ms (427 ms–1.00 s, 5/5) | at least 2.6× faster (typically 4.5×) |
| 10 | 10 | 1.34 s (1.30–1.42 s, 5/5) | ! 11.3 s (7.05–15.9 s, 4/5) | – |
| 10 | 50 | 6.16 s (6.03–8.24 s, 5/5) | 38.4 s (32.2–53.5 s, 5/5) | at least 3.9× faster (typically 6.2×) |

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

**Errors (failed requests, all measured runs) and peak server memory (median, lowest–highest)**

| Tasks | Clients | thread errors | process errors | thread memory | process memory | thread vs process |
|:---|:---|:---|:---|:---|:---|:---|
| 2 | 1 | 0 | 0 | 296 MB (293–301 MB, 5/5) | 190 MB (187–193 MB, 5/5) | at least 1.5× more (typically 1.6×) |
| 2 | 10 | 0 | 0 | 328 MB (316–329 MB, 5/5) | 822 MB (738–830 MB, 5/5) | at least 2.2× less (typically 2.5×) |
| 2 | 50 | 0 | 0 | 330 MB (321–339 MB, 5/5) | 800 MB (750–821 MB, 5/5) | at least 2.2× less (typically 2.4×) |
| 5 | 1 | 0 | 0 | 305 MB (300–317 MB, 5/5) | 321 MB (320–326 MB, 5/5) | at least 1.0× less (typically 1.1×) |
| 5 | 10 | 0 | 0 | 421 MB (408–429 MB, 5/5) | 1,878 MB (1,874–1,880 MB, 5/5) | at least 4.4× less (typically 4.5×) |
| 5 | 50 | 0 | 0 | 414 MB (408–427 MB, 5/5) | 1,878 MB (1,848–1,885 MB, 5/5) | at least 4.3× less (typically 4.5×) |
| 10 | 1 | 0 | 0 | 307 MB (299–313 MB, 5/5) | 507 MB (499–513 MB, 5/5) | at least 1.6× less (typically 1.7×) |
| 10 | 10 | 0 | 20 | 439 MB (435–443 MB, 5/5) | ! 3,611 MB (3,597–3,622 MB, 4/5) | – |
| 10 | 50 | 0 | 0 | 443 MB (435–448 MB, 5/5) | 3,613 MB (3,579–3,627 MB, 5/5) | at least 8.0× less (typically 8.2×) |

"!" = at least one run failed: not a clean result, no ratio. typically = medians; at least = the other driver's best run ÷ the thread driver's worst.

Failures (1):
- round 4: process, 10 tasks, 10 clients: 20 of 100 requests failed: HTTP 500 `{"message": "Server Error"}` (20×)
  (peak 5413 MB, at the server container's 5 GB cap)
