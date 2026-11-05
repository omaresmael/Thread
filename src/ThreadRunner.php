<?php

namespace Omaresmael\Thread;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Arr;
use Illuminate\Support\Defer\DeferredCallback;
use InvalidArgumentException;
use Omaresmael\Thread\Exceptions\TaskTimedOutException;
use Omaresmael\Thread\Exceptions\WorkerCrashedException;
use Omaresmael\Thread\Pool\Outcome;
use Omaresmael\Thread\Pool\OutcomeType;
use Omaresmael\Thread\Pool\PoolOptions;
use Omaresmael\Thread\Pool\PoolRegistry;
use Omaresmael\Thread\Pool\ThreadPool;
use Throwable;

use function Illuminate\Support\defer;

/**
 * The public API: same signatures and behaviour as Laravel's process concurrency driver.
 *
 * The current app is always taken from the container, never stored: in a
 * worker each task runs in its own sandbox app (and under Octane each request
 * in its own app), while this singleton may outlive them.
 */
class ThreadRunner implements Driver
{
    private static ?string $workerAppFile = null;

    /**
     * Run the tasks on the thread pool and return their results, keyed and
     * ordered like `$tasks`. Every task finishes before the first failure
     * (in key order) is thrown. `$concurrent` replaces `max_workers` for this call only.
     *
     * @throws Throwable
     */
    public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null, ?int $concurrent = null): array
    {
        $this->ensureValidConcurrency($concurrent);
        Requirements::ensure();

        $tasks = Arr::wrap($tasks);

        if ($tasks === []) {
            return [];
        }

        if (app()->bound('thread.worker')) {
            return $this->runInline($tasks);
        }

        $payloads = array_map(Payload::task(...), $tasks);
        $seconds = $this->seconds($timeout);

        $outcomes = $this->pool()->run($payloads, $seconds, $concurrent);
        $crashes = self::reportCrashes($outcomes);
        $results = [];

        foreach ($outcomes as $key => $outcome) {
            $results[$key] = match ($outcome->type) {
                OutcomeType::Result => Payload::value(Payload::decode($outcome->result)),
                OutcomeType::Timeout => throw TaskTimedOutException::for($key, (float) $seconds),
                OutcomeType::Crash => throw $crashes[$key],
            };
        }

        return $results;
    }

    /**
     * Run the tasks on the thread pool after the current request or command has finished.
     */
    public function defer(Closure|array $tasks, ?int $concurrent = null): DeferredCallback
    {
        $this->ensureValidConcurrency($concurrent);
        Requirements::ensure();

        return defer(fn () => $this->run($tasks, null, $concurrent));
    }

    /**
     * Point workers at a different app than the parent's own (tests use a fixture app).
     *
     * @internal
     */
    public static function useWorkerApp(?string $appFile): void
    {
        self::$workerAppFile = $appFile;
    }

    /**
     * A task that calls `Thread::run()` is already on a worker: its tasks run
     * right here, one after another, with the same results and exceptions.
     *
     * @param  array<array-key, mixed>  $tasks
     * @return array<array-key, mixed>
     *
     * @throws Throwable
     */
    private function runInline(array $tasks): array
    {
        $results = [];
        $failures = [];

        foreach ($tasks as $key => $task) {
            try {
                $results[$key] = app()->call($task);
            } catch (Throwable $e) {
                $failures[$key] = $e;
            }
        }

        if ($failures !== []) {
            throw reset($failures);
        }

        return $results;
    }

    /**
     * Report every crash of the call, not only the one thrown: the worker's own
     * handler never sees a fatal (the pool clears it so the thread prints nothing
     * when it closes). A failed boot shared by several tasks is reported once.
     *
     * @param  array<array-key, Outcome>  $outcomes
     * @return array<array-key, WorkerCrashedException> by task key, the reported instances included
     */
    private static function reportCrashes(array $outcomes): array
    {
        $crashes = [];
        $bootFailures = [];

        foreach ($outcomes as $key => $outcome) {
            if ($outcome->type !== OutcomeType::Crash) {
                continue;
            }

            $reason = (string) $outcome->reason;
            $crashes[$key] = WorkerCrashedException::for($key, $reason);

            if (str_starts_with($reason, ThreadPool::BOOT_FAILURE)) {
                if (isset($bootFailures[$reason])) {
                    continue;
                }

                $bootFailures[$reason] = true;
            }

            report($crashes[$key]);
        }

        return $crashes;
    }

    private function ensureValidConcurrency(?int $concurrent): void
    {
        if ($concurrent !== null && $concurrent < 1) {
            throw new InvalidArgumentException('The [concurrent] value must be at least 1.');
        }
    }

    /**
     * The timeout in seconds; null, zero and negative values mean none.
     */
    private function seconds(CarbonInterval|int|null $timeout): ?float
    {
        $seconds = $timeout instanceof CarbonInterval ? $timeout->totalSeconds : $timeout;

        return $seconds === null || $seconds <= 0 ? null : (float) $seconds;
    }

    private function pool(): ThreadPool
    {
        return PoolRegistry::for(
            PoolOptions::fromConfig(
                config(),
                self::$workerAppFile ?? base_path('bootstrap/app.php'),
            ),
            app('log'),
        );
    }
}
