<?php

namespace Omaresmael\Thread\Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Omaresmael\Thread\Exceptions\TaskTimedOutException;
use Omaresmael\Thread\Exceptions\WorkerCrashedException;
use Omaresmael\Thread\Facades\Thread;
use Omaresmael\Thread\Pool\PoolRegistry;
use Omaresmael\Thread\Tests\Fixtures\Exceptions\CarriesUnwakeable;
use Omaresmael\Thread\Tests\Fixtures\Exceptions\OrderFailed;
use Omaresmael\Thread\Tests\Fixtures\Unwakeable;
use Omaresmael\Thread\ThreadRunner;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class CrashTest extends FeatureTestCase
{
    /** @var list<Throwable> what the parent's exception handler was asked to report */
    private array $reported = [];

    public function test_fatal_oom_reports_the_real_message_and_the_pool_recovers(): void
    {
        try {
            Thread::run([
                function () {
                    ini_set('memory_limit', '32M');

                    return strlen(str_repeat('x', 64 << 20));
                },
            ]);
            $this->fail('no exception');
        } catch (WorkerCrashedException $e) {
            $this->assertStringContainsString('Allowed memory size', $e->getMessage());
        }
        $this->assertSame([1], Thread::run([fn () => 1]));
    }

    public function test_a_crash_is_reported_once_by_the_parent(): void
    {
        $this->spyOnReports();
        try {
            Thread::run([
                function () {
                    ini_set('memory_limit', '32M');

                    return strlen(str_repeat('x', 64 << 20));
                },
            ]);
            $this->fail('no exception');
        } catch (WorkerCrashedException $e) {
            $this->assertSame([$e], $this->reported);
        }
    }

    public function test_a_fatal_in_a_thread_prints_nothing_and_is_left_to_the_parent_to_report(): void
    {
        $log = sys_get_temp_dir().'/thread-reporting-app/logs/laravel.log';
        if (is_file($log)) {
            unlink($log);
        }
        // A real app's handler would log the fatal and render it to the console when the thread shuts down.
        $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/oom-subprocess.php']);
        $process->mustRun();
        $this->assertMatchesRegularExpression('/^Crash: Allowed memory size of \d+ bytes exhausted \(tried to allocate \d+ bytes\)$/', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertFileDoesNotExist($log);
    }

    public function test_a_crash_that_is_not_thrown_is_still_reported(): void
    {
        $this->spyOnReports();
        try {
            Thread::run([
                'a' => fn () => throw new OrderFailed('A1', 1),
                'b' => function () {
                    ini_set('memory_limit', '32M');

                    return strlen(str_repeat('x', 64 << 20));
                },
            ]);
            $this->fail('no exception');
        } catch (OrderFailed) {
        }
        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(WorkerCrashedException::class, $this->reported[0]);
        $this->assertSame('b', $this->reported[0]->key);
    }

    public function test_every_crash_of_a_call_is_reported_and_the_first_is_thrown(): void
    {
        $this->spyOnReports();
        try {
            Thread::run([
                function () {
                    ini_set('memory_limit', '32M');

                    return strlen(str_repeat('x', 64 << 20));
                },
                function () {
                    ini_set('memory_limit', '32M');

                    return strlen(str_repeat('x', 64 << 20));
                },
            ]);
            $this->fail('no exception');
        } catch (WorkerCrashedException $e) {
            $this->assertSame(0, $e->key);
            $this->assertSame([0, 1], array_map(fn (WorkerCrashedException $reported) => $reported->key, $this->reported));
            $this->assertSame($e, $this->reported[0]);
        }
    }

    public function test_a_boot_failure_shared_by_queued_tasks_is_reported_once(): void
    {
        config(['thread.max_workers' => 1]);
        ThreadRunner::useWorkerApp(__DIR__.'/../Fixtures/broken-app.php');
        $this->spyOnReports();
        try {
            Thread::run([
                fn () => 1,
                fn () => 2,
                fn () => 3,
            ]);
            $this->fail('no exception');
        } catch (WorkerCrashedException $e) {
            $this->assertStringContainsString('boot exploded', $e->getMessage());
            $this->assertSame([$e], $this->reported);
        }
    }

    public function test_exit_in_a_task_crashes_only_that_worker(): void
    {
        try {
            Thread::run([
                'ok' => fn () => 1,
                'bad' => fn () => exit(3),
            ]);
            $this->fail('no exception');
        } catch (WorkerCrashedException $e) {
            $this->assertSame('bad', $e->key);
            $this->assertStringContainsString('exit()', $e->getMessage());
        }
        $this->assertSame([2], Thread::run([fn () => 2]));
    }

    public function test_threads_do_not_display_errors(): void
    {
        $this->assertSame(['0'], Thread::run([fn () => ini_get('display_errors')]));
    }

    public function test_using_the_std_constants_in_a_task_fails_the_task(): void
    {
        // Threads have no STDIN/STDOUT/STDERR: a task that uses them fails with PHP's own error.
        $this->expectExceptionObject(new \Error('Undefined constant "STDOUT"'));

        Thread::run([fn () => fwrite(STDOUT, 'x')]);
    }

    public function test_the_worker_time_limit_fatal_is_a_timeout_and_retires_the_worker(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The worker-side time limit only exists on Linux.');
        }
        config(['thread.max_workers' => 1]);
        $this->bootWorkers(1);   // a new worker's boot would count against the 0.25 s grace and let the parent abandon it first
        $this->spyOnReports();
        try {
            Thread::run([fn () => sleep(3)], timeout: 1);
            $this->fail('no exception');
        } catch (TaskTimedOutException $e) {
            $this->assertSame(0, $e->key);
        }
        $this->assertSame([], $this->reported);   // a timeout is not an error to report
        $this->assertSame(['started' => 1, 'live' => 0, 'abandoned' => 0], PoolRegistry::for($this->options())->stats());
    }

    public function test_a_crashed_worker_is_retired_not_reused(): void
    {
        config(['thread.max_workers' => 1]);
        try {
            Thread::run([fn () => exit(1)]);
            $this->fail('no exception');
        } catch (WorkerCrashedException) {
        }
        $this->assertSame(['started' => 1, 'live' => 0, 'abandoned' => 0], PoolRegistry::for($this->options())->stats());
        $this->assertSame([2], Thread::run([fn () => 2]));
        $this->assertSame(['started' => 2, 'live' => 1, 'abandoned' => 0], PoolRegistry::for($this->options())->stats());
    }

    public function test_a_crashed_temporary_worker_is_replaced_only_while_tasks_are_queued(): void
    {
        config(['thread.max_workers' => 1]);
        $this->bootWorkers(1);
        $pool = PoolRegistry::for($this->options());
        try {
            // Key 0 keeps the pool's worker busy; key 1 crashes the temporary one with nothing left to run.
            Thread::run([
                fn () => usleep(500000),
                fn () => exit(1),
            ], concurrent: 2);
            $this->fail('no exception');
        } catch (WorkerCrashedException $e) {
            $this->assertSame(1, $e->key);
        }
        $this->assertSame(['started' => 2, 'live' => 1, 'abandoned' => 0], $pool->stats());   // not replaced
        try {
            // Same, but key 2 is still queued when key 1's temporary worker crashes: a replacement runs it.
            Thread::run([
                fn () => usleep(500000),
                fn () => exit(1),
                fn () => 3,
            ], concurrent: 2);
            $this->fail('no exception');
        } catch (WorkerCrashedException $e) {
            $this->assertSame(1, $e->key);
        }
        $this->assertSame(['started' => 4, 'live' => 1, 'abandoned' => 0], $pool->stats());   // one replacement, closed with its call
    }

    public function test_a_failure_the_parent_cannot_unserialize_is_thrown_and_the_worker_stays_usable(): void
    {
        config(['thread.max_workers' => 1]);
        try {
            Thread::run([fn () => throw new CarriesUnwakeable(new Unwakeable)]);
            $this->fail('no exception');
        } catch (RuntimeException $e) {
            $this->assertSame('Unwakeable cannot be unserialized.', $e->getMessage());
        }
        try {
            Thread::run([fn () => new Unwakeable]);
            $this->fail('no exception');
        } catch (RuntimeException $e) {
            $this->assertSame('Unwakeable cannot be unserialized.', $e->getMessage());
        }
        $this->assertSame([2], Thread::run([fn () => 2]));
        $this->assertSame(['started' => 1, 'live' => 1, 'abandoned' => 0], PoolRegistry::for($this->options())->stats());
    }

    /**
     * Collect what the parent reports, instead of logging it.
     */
    private function spyOnReports(): void
    {
        app(ExceptionHandler::class)->reportable(function (Throwable $e) {
            $this->reported[] = $e;

            return false;
        });
    }
}
