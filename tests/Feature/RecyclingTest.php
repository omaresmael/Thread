<?php

namespace Omaresmael\Thread\Tests\Feature;

use Omaresmael\Thread\Facades\Thread;
use Omaresmael\Thread\Pool\PoolRegistry;

class RecyclingTest extends FeatureTestCase
{
    public function test_worker_is_replaced_after_max_tasks(): void
    {
        config(['thread.max_workers' => 1, 'thread.max_tasks' => 3]);
        Thread::run(array_fill(0, 7, fn () => 1));
        $this->assertSame(3, PoolRegistry::for($this->options())->stats()['started']);   // 3 + 3 + 1
    }

    public function test_worker_is_replaced_when_memory_exceeds_max_memory(): void
    {
        config(['thread.max_workers' => 1, 'thread.max_memory' => 32]);
        Thread::run([
            function () {
                $GLOBALS['thread_ballast'] = str_repeat('x', 40 << 20);   // survives the task: heap stays > 32 MB

                return 1;
            },
        ]);
        Thread::run([fn () => 1]);
        $this->assertSame(2, PoolRegistry::for($this->options())->stats()['started']);
    }

    public function test_null_limits_disable_recycling(): void
    {
        config(['thread.max_workers' => 1, 'thread.max_tasks' => null, 'thread.max_memory' => null]);
        Thread::run(array_fill(0, 20, fn () => 1));
        $this->assertSame(1, PoolRegistry::for($this->options())->stats()['started']);
    }

    public function test_worker_heap_stays_flat_over_many_tasks(): void
    {
        config(['thread.max_workers' => 1, 'thread.max_tasks' => null]);
        Thread::run([fn () => 1]);
        $pool = PoolRegistry::for($this->options());
        $before = $pool->workers()[array_key_first($pool->workers())]->memory;
        Thread::run(array_fill(0, 2000, fn () => 1));
        $after = $pool->workers()[array_key_first($pool->workers())]->memory;
        $this->assertLessThan(2 * 1024 * 1024, $after - $before);   // spike: +0.3 KB/task with GC, +10.8 KB without
    }
}
