<?php

namespace Omaresmael\Thread\Tests\Feature;

use ArrayObject;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Context;
use Omaresmael\Thread\Exceptions\WorkerCrashedException;
use Omaresmael\Thread\Facades\Thread;
use Omaresmael\Thread\Payload;
use Omaresmael\Thread\Pool\PoolRegistry;
use Omaresmael\Thread\Pool\Worker;
use Omaresmael\Thread\Tests\Fixtures\Exceptions\OrderFailed;
use Omaresmael\Thread\ThreadRunner;
use parallel\Runtime\Error\IllegalParameter;
use PDO;
use Throwable;

class RunTest extends FeatureTestCase
{
    public function test_preserves_keys_and_order(): void
    {
        $this->assertSame([0 => 'a', 1 => 'b'], Thread::run([
            fn () => 'a',
            fn () => 'b',
        ]));
        $this->assertSame(['x' => 1, 7 => 2, 'y' => 3], Thread::run([
            'x' => fn () => 1,
            7 => fn () => 2,
            'y' => fn () => 3,
        ]));
    }

    public function test_runs_through_laravels_concurrency_facade(): void
    {
        $this->assertSame([1, 2], Concurrency::driver('thread')->run([
            fn () => 1,
            fn () => 2,
        ]));

        config(['concurrency.default' => 'thread']);
        $this->assertSame([[getmypid(), true]], Concurrency::run([
            fn () => [getmypid(), app()->bound('thread.worker')],
        ]));
    }

    public function test_single_closure_and_empty_list(): void
    {
        // The empty-list check runs first: the single closure below does start a worker.
        $this->assertSame([], Thread::run([]));
        $this->assertSame(0, PoolRegistry::for($this->options())->stats()['started']);
        $this->assertSame([42], Thread::run(fn () => 42));
    }

    public function test_runs_on_threads_of_this_process_with_di(): void
    {
        $out = Thread::run([fn (Application $app) => [getmypid(), $app->bound('thread.worker')]]);
        $this->assertSame([getmypid(), true], $out[0]);
    }

    public function test_workers_are_reused_across_calls_and_capped_at_max_workers(): void
    {
        config(['thread.max_workers' => 2]);
        Thread::run(array_fill(0, 6, fn () => usleep(20000)));
        Thread::run(array_fill(0, 6, fn () => 1));
        $this->assertSame(['started' => 2, 'live' => 2, 'abandoned' => 0], PoolRegistry::for($this->options())->stats());
    }

    public function test_drains_all_tasks_then_throws_first_failure_by_key_order(): void
    {
        $marker = sys_get_temp_dir().'/thread-drain-'.uniqid();
        try {
            Thread::run([
                'b' => fn () => throw new OrderFailed('B', 2),
                'a' => fn () => throw new OrderFailed('A', 1),
                'c' => fn () => touch($marker),
            ]);
            $this->fail('no exception');
        } catch (OrderFailed $e) {
            $this->assertSame('B', $e->orderId);
        }
        $this->assertFileExists($marker);
        unlink($marker);
    }

    public function test_context_propagates(): void
    {
        Context::add('trace', 'abc');
        $this->assertSame(['abc'], Thread::run([fn () => Context::get('trace')]));
    }

    public function test_nested_run_inside_a_task_runs_inline(): void
    {
        $this->assertSame([[1, 2]], Thread::run([
            function () {
                return Thread::run([
                    fn () => 1,
                    fn () => 2,
                ]);
            },
        ]));
    }

    public function test_nested_run_ignores_its_timeout(): void
    {
        $this->assertSame([['slow']], Thread::run([
            function () {
                return Thread::run([
                    fn () => usleep(1_500_000) ?: 'slow',
                ], 1);
            },
        ]));
    }

    public function test_non_serializable_capture_fails_before_dispatch(): void   // Review Focus 2
    {
        $pdo = new PDO('sqlite::memory:');   // serialize(PDO) throws; serialize(resource) would silently succeed
        try {
            Thread::run([
                fn () => 1,
                fn () => $pdo,
            ]);
            $this->fail('no exception');
        } catch (Throwable) {
            $this->assertSame(0, PoolRegistry::for($this->options())->stats()['started']);
        }
    }

    public function test_non_serializable_result_throws_like_process_driver(): void   // Review Focus 1
    {
        $this->expectExceptionMessage("Serialization of 'Closure' is not allowed");
        Thread::run([
            function () {
                return fn () => 1;
            },
        ]);
    }

    public function test_boot_failure_fails_tasks_promptly(): void   // Review Focus 3
    {
        ThreadRunner::useWorkerApp(__DIR__.'/../Fixtures/broken-app.php');
        $start = microtime(true);
        try {
            Thread::run([
                fn () => 1,
                fn () => 2,
            ]);
            $this->fail('no exception');
        } catch (WorkerCrashedException $e) {
            $this->assertStringContainsString('boot exploded', $e->getMessage());
        }
        $this->assertLessThan(5, microtime(true) - $start);
    }

    public function test_large_payloads_and_results(): void
    {
        $blob = random_bytes(5 * 1024 * 1024);
        $this->assertSame(strlen($blob) * 2, Thread::run([fn () => strlen($blob.$blob)])[0]);
        $this->assertSame(8 * 1024 * 1024, strlen(Thread::run([fn () => str_repeat('x', 8 * 1024 * 1024)])[0]));
    }

    public function test_defer_runs_tasks_after_the_callback_is_invoked(): void
    {
        $file = sys_get_temp_dir().'/thread-defer-'.uniqid();
        Thread::defer([fn () => file_put_contents($file, 'done')]);
        $this->assertFileDoesNotExist($file);
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();
        $this->assertStringEqualsFile($file, 'done');
        unlink($file);
    }

    public function test_a_call_that_throws_midway_does_not_leak_results_into_the_next_call(): void
    {
        config(['thread.max_workers' => 2]);
        $this->bootWorkers(2);
        $pool = PoolRegistry::for($this->options());
        try {
            // Key 0 starts on the first worker; parallel rejects key 1's payload, so run() throws while key 0 is in flight.
            $pool->run([0 => Payload::task(fn () => usleep(100000) ?: 'old'), 1 => new ArrayObject], null);
            $this->fail('no exception');
        } catch (IllegalParameter) {
        }
        $out = $pool->run([0 => Payload::task(fn () => usleep(600000) ?: 'new')]);   // the stale 'old' result arrives meanwhile
        $this->assertSame([0], array_keys($out));
        $this->assertSame('new', Payload::value(Payload::decode($out[0]->result)));
        $this->assertSame([1, 2], Thread::run([
            fn () => 1,
            fn () => 2,
        ]));
        // The worker left running by the aborted call was abandoned and closed once its task ended: a third replaced it.
        $this->assertSame(['started' => 3, 'live' => 2, 'abandoned' => 0], $pool->stats());
    }

    public function test_the_pool_can_boot_workers_up_front(): void
    {
        $this->bootWorkers(3);
        $pool = PoolRegistry::for($this->options());
        $this->assertSame(3, $pool->stats()['live']);
        $this->assertTrue(collect($pool->workers())->every(fn (Worker $w) => $w->ready));
    }

    public function test_settings_are_read_once_when_the_pool_is_created(): void
    {
        config(['thread.max_workers' => 1]);
        $this->bootWorkers();
        config(['thread.max_workers' => 3]);

        $this->bootWorkers();
        $this->assertSame(1, PoolRegistry::for($this->options())->stats()['live']);
    }
}
