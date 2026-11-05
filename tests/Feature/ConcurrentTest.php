<?php

namespace Omaresmael\Thread\Tests\Feature;

use ArrayObject;
use InvalidArgumentException;
use Omaresmael\Thread\Facades\Thread;
use Omaresmael\Thread\Payload;
use Omaresmael\Thread\Pool\PoolRegistry;
use parallel\Runtime\Error\IllegalParameter;

class ConcurrentTest extends FeatureTestCase
{
    public function test_above_max_workers_starts_extras_and_closes_them_after_the_call(): void
    {
        config(['thread.max_workers' => 2]);
        $out = Thread::run(array_fill(0, 6, fn () => usleep(50000)), concurrent: 6);
        $this->assertCount(6, $out);
        $stats = PoolRegistry::for($this->options())->stats();
        $this->assertSame(6, $stats['started']);
        $this->assertSame(2, $stats['live']);
    }

    public function test_six_sleeping_tasks_with_concurrent_six_take_about_one_sleep(): void
    {
        config(['thread.max_workers' => 2]);
        $this->bootWorkers(2);
        $start = microtime(true);
        Thread::run(array_fill(0, 6, fn () => usleep(300000)), concurrent: 6);
        $this->assertLessThan(0.9, microtime(true) - $start);   // 3 rounds of 0.3 s would be ≥ 0.9 s
    }

    public function test_below_live_workers_uses_only_n_and_starts_nothing(): void
    {
        $this->bootWorkers(4);
        $start = microtime(true);
        Thread::run(array_fill(0, 4, fn () => usleep(200000)), concurrent: 2);
        $this->assertGreaterThanOrEqual(0.4, microtime(true) - $start);   // 2 rounds
        $this->assertSame(4, PoolRegistry::for($this->options())->stats()['started']);
    }

    public function test_is_capped_at_the_number_of_tasks(): void
    {
        Thread::run([
            fn () => 1,
            fn () => 2,
        ], concurrent: 6);
        $this->assertSame(2, PoolRegistry::for($this->options())->stats()['started']);
    }

    public function test_new_workers_get_their_task_before_boot_finishes(): void
    {
        config(['thread.max_workers' => 1]);
        $pool = PoolRegistry::for($this->options());
        $payload = Payload::task(fn () => 1);
        $pool->run([$payload, $payload, $payload], null, 3);
        $this->assertCount(3, $pool->lastRunWorkers());
        foreach ($pool->lastRunWorkers() as $w) {
            $this->assertLessThanOrEqual($w->readyAt, $w->firstDispatchedAt);
        }
    }

    public function test_rejects_values_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Thread::run([fn () => 1], concurrent: 0);
    }

    public function test_defer_accepts_concurrent(): void
    {
        config(['thread.max_workers' => 1]);
        Thread::defer(array_fill(0, 3, fn () => usleep(300000)), concurrent: 3);
        $start = microtime(true);
        app(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();
        $this->assertLessThan(0.9, microtime(true) - $start);
    }

    public function test_workers_left_running_by_an_aborted_call_are_abandoned_and_closed_once_their_task_ends(): void
    {
        config(['thread.max_workers' => 1]);
        $this->bootWorkers(1);
        $pool = PoolRegistry::for($this->options());
        try {
            // Keys 0 and 1 start (key 1 on a temporary worker); parallel rejects key 2's payload, so run() throws mid-call.
            $pool->run([
                Payload::task(fn () => usleep(200000) ?: 'old'),
                Payload::task(fn () => usleep(200000) ?: 'old'),
                new ArrayObject,
            ], null, 3);
            $this->fail('no exception');
        } catch (IllegalParameter) {
        }
        // The busy ones are abandoned and no longer count as live; the idle temporary one is closed.
        $this->assertSame(['started' => 3, 'live' => 0, 'abandoned' => 2], $pool->stats());
        // Abandoned workers are not reused, and their late results are no outcome of this call: new workers run these.
        $this->assertSame([1, 2], Thread::run([
            fn () => usleep(400000) ?: 1,
            fn () => 2,
        ], concurrent: 2));
        $this->assertSame(['started' => 5, 'live' => 1, 'abandoned' => 0], $pool->stats());
    }
}
