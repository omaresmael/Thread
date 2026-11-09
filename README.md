<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/logo-dark.svg">
    <img alt="Thread" src="art/logo-light.svg" width="320">
  </picture>
</p>

<p align="center">
<a href="https://github.com/omaresmael/Thread/actions/workflows/tests.yml"><img src="https://github.com/omaresmael/Thread/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
<a href="https://packagist.org/packages/omaresmael/thread"><img src="https://img.shields.io/packagist/v/omaresmael/thread" alt="Latest Stable Version"></a>
</p>

A thread-based concurrency driver for Laravel. Tasks run on a pool of PHP threads that boot your app once, so they’re far faster than the process driver (at least 46× on a busy web server) and use a fraction of its memory, without forking your app.

Laravel's process driver starts a new `php artisan` process for every task: a process spawn, PHP startup and a full Laravel boot before your code runs, and a whole PHP process in memory per task. This package keeps a small pool of threads inside your PHP process instead. Each thread boots Laravel once and then runs task after task, so a task costs under a millisecond of overhead instead of over a hundred milliseconds, and the pool's memory stays flat no matter how many tasks you run. On the [benchmark](#benchmark), a request running 10 database queries under 50 concurrent clients answers in **0.5 s** with the server using **265 MB**, against **26 s** and **2.9 GB** with the process driver.

Why threads instead of processes:

- **Lighter.** A thread is not a whole new PHP process: there is no process to spawn and no PHP to start, and a booted worker adds about 1.5 MB, against roughly 30 MB for every process the process driver starts.
- **Shares opcache.** All the threads live in one process, so they share its opcache: code PHP has compiled once is reused by every worker, which is why a worker boots in about 10 ms. Each process the process driver starts gets no shared opcache, so it compiles your app all over again.


## Installation

Requirements:

- **PHP 8.4 built thread-safe (ZTS)** with the **`parallel`** extension.
- **Laravel 13.**
- **`opcache.enable_cli=1`**, Optional, But strongly recommended

```bash
composer require omaresmael/thread
```

To publish the config file:

```bash
php artisan vendor:publish --tag=thread-config
```

## Usage

### Two ways to use it

Through the package's `Thread` facade:

```php
use Omaresmael\Thread\Facades\Thread;

Thread::run([
    fn () => User::count(),
    fn () => Order::count(),
]);
```

Or as a driver of Laravel's `Concurrency` facade. Make it the default in `.env`:

```dotenv
CONCURRENCY_DRIVER=thread
```

```php
use Illuminate\Support\Facades\Concurrency;

Concurrency::run([
    fn () => User::count(),
    fn () => Order::count(),
]);
```

### Running tasks

```php
Thread::run(Closure|array $tasks, CarbonInterval|int|null $timeout = null, ?int $concurrent = null): array
```

Results keep the keys and order of the tasks:

```php
Thread::run([
    'x' => fn () => 1,
    7 => fn () => 2,
]);
// ['x' => 1, 7 => 2]
```

Tasks are called through the container, so dependency injection works, and `Context` is passed to them:

```php
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Context;

Context::add('trace', 'abc');

Thread::run([
    fn (Repository $config) => [$config->get('app.name'), Context::get('trace')],
]);
```

Every task finishes before `run()` throws. If tasks failed, the first failure in key order is thrown:

- a task's exception is rebuilt with its original class and constructor arguments; one that can't be rebuilt that way (its constructor needs objects, like `ValidationException`) is thrown as a `RuntimeException` carrying its class and message;
- a task that runs past its timeout throws `TaskTimedOutException`;
- a task that kills its worker (a fatal error, out of memory, `exit()`) throws `WorkerCrashedException` with the real error message. The crash is also reported to your exception handler.

### Timeouts

`$timeout` is seconds or a `CarbonInterval`. `null`, `0` or a negative value means no timeout.

```php
use Carbon\CarbonInterval;
use Omaresmael\Thread\Exceptions\TaskTimedOutException;

try {
    Thread::run([fn () => sleep(10)], 1);
} catch (TaskTimedOutException $e) {
    $e->key; // which task timed out
}

Thread::run([fn () => sleep(5)], CarbonInterval::milliseconds(500));
```

The clock starts when a task is handed to a worker. A task stuck in network I/O (a socket read, an HTTP call without a timeout) cannot be interrupted: `run()` still throws on time, but the thread keeps running until that I/O returns, and the process cannot exit before it does. Set timeouts on HTTP and database calls inside tasks.

### Concurrency

The pool keeps up to `max_workers` threads (4 by default); extra tasks wait in a queue. `$concurrent` changes that for one call:

```php
Thread::run($tasks, concurrent: 8);
```

Threads started beyond `max_workers` are closed when the call ends.

### Deferring

Run the tasks after the response is sent or the command finishes.
```php
Thread::defer([fn () => Report::generate()]);
```

### Configuration

| Key | Env | Default | |
|---|---|---|---|
| `max_workers` | `THREAD_MAX_WORKERS` | `4` | Maximum number of long-lived threads |
| `max_tasks` | `THREAD_MAX_TASKS` | `500` | Replace a worker after this many tasks (`null` to disable) |
| `max_memory` | `THREAD_MAX_MEMORY` | `128` | Replace a worker when its memory exceeds this many MB (`null` to disable) |

### Things to know

- **Write tasks as you would for Laravel Octane.** Static properties and objects you mutate on shared singletons survive between tasks on the same worker. Set `max_tasks` to `1` if you need a fresh boot for every task.
- **Threads share the process.** A crash of the PHP engine itself (a segfault) takes down your whole process. `putenv()` and `umask()` in a task affect the whole process.
- **A timed-out task stuck in network I/O cannot be stopped.** `run()` throws on time, but the thread keeps running until the I/O returns, and closing the pool or exiting the process waits for it. Set timeouts on HTTP, database and socket calls inside tasks.
- `STDIN`, `STDOUT` and `STDERR` do not exist inside tasks, and what a task `echo`s is discarded.

### Differences from the process driver

Results and most task exceptions are the same: keys, order and rebuilt exceptions match Laravel's process driver. What differs:

| | Process driver | Threads |
|---|---|---|
| A task that times out | throws `ProcessTimedOutException` | throws `TaskTimedOutException`; a task stuck in network I/O keeps running in the background until that I/O returns |
| A task that crashes | a plain `Exception` with the exit code and the child's error output (empty for `exit()`) | `WorkerCrashedException` with the real error message, also for `exit()`, and reported to your exception handler |
| An exception that can't be rebuilt (e.g. `ValidationException`) | rebuilding it fails with an unrelated `Error` | `RuntimeException` carrying the original class and message |
| `defer()` | starts detached background processes and returns at once | runs the tasks on the pool after the HTTP response is sent, or after the command or queued job finishes; that PHP worker stays busy until they are done |

## Benchmark

Laravel's process driver against the thread driver, with two tasks of about 34 ms each:

- **I/O-bound:** a MySQL query that waits 30 ms on the server, like a slow query or an API call.
- **CPU-bound:** a revenue report over 50,000 orders in SQLite.

| Where | Task | Workload | Process driver | Thread driver | Faster | Memory (process → thread) |
|---|---|---|---:|---:|---:|---:|
| Artisan command | CPU-bound | 100 tasks in one call | 4.63 s | 1.11 s | at least 3.6× (typically 4.2×) | 2,299 → 79 MB |
| Artisan command | I/O-bound | 100 tasks in one call | 4.40 s | 827 ms | at least 4.8× (typically 5.3×) | 1,860 → 57 MB |
| FrankenPHP classic mode | CPU-bound | 10 tasks per request, 50 clients | 33.1 s | 5.76 s | at least 4.1× (typically 5.8×) | 3,613 → 443 MB |
| FrankenPHP classic mode | I/O-bound | 10 tasks per request, 10 clients | 6.09 s | 157 ms | at least **26×** (typically 39×) | 2,719 → 180 MB |
| FrankenPHP worker mode (Octane) | CPU-bound | 10 tasks per request, 10 clients | 6.87 s | 1.08 s | at least 5.6× (typically 6.4×) | 3,699 → 539 MB |
| FrankenPHP worker mode (Octane) | I/O-bound | 10 tasks per request, 50 clients | 25.8 s | 496 ms | at least **46×** (typically 52×) | 2,936 → 265 MB |

Times are the median of 5 runs: one `run()` call on the command line, one request on the web server. "At least" divides the process driver's fastest run by the threads' slowest; "typically" divides the medians. Both drivers ran on their defaults, in Docker with 5 CPUs.

Every result, the method, and how to run it yourself: [`benchmark/`](benchmark/README.md).
