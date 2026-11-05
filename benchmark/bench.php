<?php
// php bench.php <process|thread> <tasks> <small|sqlite>: one measurement, printed as exactly one JSON line,
// whether it succeeds or fails. The app boots first (not timed); then one run() call is timed. For threads, a
// second call on the already booted pool is timed too ("warm").

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Concurrency;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/tasks.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $driver, $count, $set] = $argv + [3 => 'sqlite'];
$count = (int) $count;
$row = ['driver' => $driver, 'tasks' => $count, 'set' => $set];

$start = microtime(true);

try {
    [$tasks, $expected] = benchmark_tasks($set, $count);

    $start = microtime(true);
    $results = Concurrency::driver($driver)->run($tasks);
    $seconds = microtime(true) - $start;

    $warm = null;
    if ($driver === 'thread') {
        $start = microtime(true);
        Concurrency::driver('thread')->run($tasks);
        $warm = microtime(true) - $start;
    }

    $ok = array_sum($results) === $expected;
    $row += [
        'ok' => $ok,
        'seconds' => round($seconds, 4),
        'warm_seconds' => $warm === null ? null : round($warm, 4),
    ];
    if (! $ok) {
        $row['reason'] = 'wrong results: expected '.$expected.', got '.array_sum($results);
    }
} catch (Throwable $e) {
    // Laravel's process driver gives every child process a 60 s timeout (PendingProcess::$timeout); under load
    // children run over it and run() throws. That is the process driver's own behaviour: a failure, not a crash.
    $message = trim(strtok(trim($e->getMessage()), "\n") ?: '');
    $row += [
        'ok' => false,
        'reason' => $e instanceof ProcessTimedOutException
            ? "child process timed out (Laravel's 60 s limit)"
            : (new ReflectionClass($e))->getShortName().': '.mb_strimwidth($message, 0, 160, '…'),
        'exception' => get_class($e),
        'message' => mb_strimwidth($message, 0, 300, '…'),
        'failed_after_seconds' => round(microtime(true) - $start, 4),
    ];
}

echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
