<?php

namespace App\Console\Commands;

use App\Benchmark\Tasks;
use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Concurrency;
use ReflectionClass;
use Throwable;

/**
 * One command-line measurement, printed as exactly one JSON line, success or failure. The app has booted by the time
 * this runs (not timed); then one run() call is timed. For threads that includes starting and booting the pool, and a
 * second call on the already booted pool is timed too ("warm").
 *
 *   php artisan benchmark:run <sync|thread|process> <tasks> [--task=report|wait]
 *   php artisan benchmark:run sync 1 --calibrate=30     # one task inline, 30 times: per-task times in ms
 */
class BenchmarkRun extends Command
{
    protected $signature = 'benchmark:run {driver} {tasks} {--calibrate=0} {--task=report}';

    protected $description = 'One benchmark measurement: time one Concurrency run() call.';

    public function handle(): int
    {
        [$driver, $count, $kind] = [$this->argument('driver'), (int) $this->argument('tasks'), (string) $this->option('task')];

        if (($repeat = (int) $this->option('calibrate')) > 0) {
            return $this->calibrate($repeat, $kind);
        }

        $row = ['driver' => $driver, 'tasks' => $count];
        $start = microtime(true);

        try {
            $tasks = Tasks::make($count, $kind);

            $start = microtime(true);
            $results = Concurrency::driver($driver)->run($tasks);
            $seconds = microtime(true) - $start;

            $warm = null;
            if ($driver === 'thread') {
                $start = microtime(true);
                Concurrency::driver('thread')->run($tasks);
                $warm = microtime(true) - $start;
            }

            $ok = Tasks::verify($results, $count, $kind);
            $row += ['ok' => $ok, 'seconds' => round($seconds, 4), 'warm_seconds' => $warm === null ? null : round($warm, 4)];
            if (! $ok) {
                $row['reason'] = 'wrong results: expected '.($count * Tasks::expected($kind)).', got '.array_sum($results);
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

        $this->line(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /** Runs one task inline $repeat times (after one untimed run) and prints the times. */
    private function calibrate(int $repeat, string $kind): int
    {
        $task = Tasks::task($kind);
        $rows = $task();
        $times = [];
        for ($i = 0; $i < $repeat; $i++) {
            $start = hrtime(true);
            $task();
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);
        $this->line(json_encode([
            'rows' => $rows, 'runs' => $repeat,
            'median_ms' => round($times[intdiv($repeat, 2)], 1), 'min_ms' => round($times[0], 1), 'max_ms' => round(end($times), 1),
        ]));

        return self::SUCCESS;
    }
}
