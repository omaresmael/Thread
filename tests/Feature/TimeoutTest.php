<?php

namespace Omaresmael\Thread\Tests\Feature;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Omaresmael\Thread\Exceptions\TaskTimedOutException;
use Omaresmael\Thread\Facades\Thread;
use Omaresmael\Thread\Pool\PoolRegistry;

class TimeoutTest extends FeatureTestCase
{
    public function test_cpu_loop_times_out_on_time(): void
    {
        $this->warmUp();
        $start = microtime(true);
        try {
            Thread::run([
                function () {
                    while (true) {
                    }
                },
            ], 1);
            $this->fail('no exception');
        } catch (TaskTimedOutException $e) {
            $this->assertSame(0, $e->key);
        }
        $this->assertLessThan(1.6, microtime(true) - $start);
        $this->assertSame([1], Thread::run([fn () => 1]));
    }

    public function test_sleep_times_out_on_time(): void
    {
        $this->warmUp();
        $start = microtime(true);
        $this->expectException(TaskTimedOutException::class);
        try {
            Thread::run([fn () => sleep(10)], 1);
        } finally {
            $this->assertLessThan(1.6, microtime(true) - $start);
        }
    }

    public function test_sub_second_and_disabled_timeouts(): void
    {
        $this->warmUp();
        $start = microtime(true);
        try {
            Thread::run([fn () => sleep(5)], CarbonInterval::milliseconds(500));
            $this->fail('no timeout');
        } catch (TaskTimedOutException) {
        }
        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertSame([1], Thread::run([fn () => usleep(1200000) ?: 1], 0));
        $this->assertSame([1], Thread::run([fn () => usleep(1200000) ?: 1], -5));
    }

    public function test_blocking_socket_read_is_abandoned_without_blocking_run(): void
    {
        Log::spy();   // before the first run: the pool takes app('log') when it is created
        $this->warmUp();
        $start = microtime(true);
        try {
            Thread::run([
                function () {
                    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                    stream_set_timeout($a, 4);

                    return fread($a, 1);   // blocks 4 s; set_time_limit can't interrupt it
                },
            ], 1);
            $this->fail('no exception');
        } catch (TaskTimedOutException) {
        }
        $this->assertLessThan(1.6, microtime(true) - $start);
        $this->assertSame(1, PoolRegistry::for($this->options())->stats()['abandoned']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'abandoned thread'));
        usleep(4_500_000);
        Thread::run([fn () => 1]);   // triggers reaping
        $this->assertSame(0, PoolRegistry::for($this->options())->stats()['abandoned']);
    }

    public function test_the_abandon_warning_names_the_task_and_the_timeout(): void
    {
        Log::spy();
        try {
            Thread::run(['slow' => fn () => sleep(3)], CarbonInterval::milliseconds(500));
            $this->fail('no timeout');
        } catch (TaskTimedOutException) {
        }
        Log::shouldHaveReceived('warning')->once()->with('[thread] Task [slow] timed out after 0.5s and is still running in an abandoned thread; process exit will wait for it.');
    }

    public function test_booting_workers_up_front_does_not_count_abandoned_threads(): void
    {
        config(['thread.max_workers' => 1]);
        try {
            Thread::run([fn () => sleep(3)], CarbonInterval::milliseconds(500));
            $this->fail('no timeout');
        } catch (TaskTimedOutException) {
        }
        $this->bootWorkers();   // the abandoned worker is still sleeping (until its 1 s time limit) when the pool counts live workers
        $stats = PoolRegistry::for($this->options())->stats();
        $this->assertSame([2, 1], [$stats['started'], $stats['live']]);
    }

    public function test_timeout_counts_from_dispatch_not_from_queueing(): void
    {
        config(['thread.max_workers' => 1]);
        $this->assertSame([1, 1, 1], Thread::run(array_fill(0, 3, fn () => usleep(700000) ?: 1), 1));   // 2.1 s total, 0.7 s each
    }

    public function test_a_time_limit_fatal_on_an_abandoned_thread_prints_nothing_and_is_not_reported(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The worker-side time limit only exists on Linux.');
        }
        $log = sys_get_temp_dir().'/thread-reporting-app/logs/laravel.log';
        if (is_file($log)) {
            unlink($log);
        }
        // Timeout 1.2 s: the backstop abandons the task at 1.45 s, then the worker's 2 s time limit ends it with a fatal.
        $process = Process::run([PHP_BINARY, __DIR__.'/../Fixtures/abandoned-fatal-subprocess.php']);
        $this->assertSame(0, $process->exitCode());
        $this->assertSame('reaped by run: Timeout 1 Result 0; reaped by shutdown: Timeout 1 0', $process->output());
        $this->assertSame('', $process->errorOutput());
        $this->assertFileDoesNotExist($log);
    }

    public function test_process_exit_waits_for_stuck_thread_but_run_does_not(): void
    {
        // tests/Fixtures/stuck-exit.php: requires vendor/autoload.php, boots the fixture app as the parent (same app the workers boot),
        // calls Thread::run() with a task blocked 3 s in a socket read and timeout 1, prints the seconds until run() threw, then exits.
        $start = microtime(true);
        $process = Process::run([PHP_BINARY, __DIR__.'/../Fixtures/stuck-exit.php']);
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?$/', trim($process->output()));
        $this->assertLessThan(1.6, (float) trim($process->output()));
        $this->assertGreaterThanOrEqual(3.0, microtime(true) - $start);   // documents spec §7.8.3
        $this->assertStringContainsString('abandoned thread; process exit will wait for it.', $process->errorOutput());
    }

    public function test_no_timeout_means_no_time_limit_whatever_max_execution_time_is(): void
    {
        // tests/Fixtures/max-execution-time-subprocess.php: a fresh worker runs a 1.5 s task with no timeout under max_execution_time=1.
        $process = Process::run([PHP_BINARY, '-d', 'max_execution_time=1', __DIR__.'/../Fixtures/max-execution-time-subprocess.php']);
        $this->assertSame('done', $process->output());
    }

    /**
     * One call before a timing is measured, so the timing covers the timeout
     * path, not a cold process loading classes on its first call.
     */
    private function warmUp(): void
    {
        Thread::run([fn () => 1]);
    }
}
