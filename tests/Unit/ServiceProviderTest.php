<?php

namespace Omaresmael\Thread\Tests\Unit;

use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\ServiceProvider;
use Omaresmael\Thread\Facades\Thread;
use Omaresmael\Thread\Tests\TestCase;
use Omaresmael\Thread\ThreadRunner;
use Omaresmael\Thread\ThreadServiceProvider;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged_with_defaults(): void
    {
        $this->assertSame(4, config('thread.max_workers'));
        $this->assertSame(500, (int) config('thread.max_tasks'));
        $this->assertSame(128, (int) config('thread.max_memory'));
    }

    public function test_runner_is_a_singleton_behind_the_facade(): void
    {
        $this->assertSame(app(ThreadRunner::class), app('thread'));
        $this->assertSame(app(ThreadRunner::class), Thread::getFacadeRoot());
    }

    public function test_registers_the_thread_concurrency_driver(): void
    {
        $this->assertSame(app(ThreadRunner::class), Concurrency::driver('thread'));

        config(['concurrency.default' => 'thread']);
        $this->assertSame(app(ThreadRunner::class), Concurrency::driver());
    }

    public function test_config_is_publishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(ThreadServiceProvider::class, 'thread-config');
        $this->assertSame(realpath(__DIR__.'/../../config/thread.php'), realpath(array_key_first($paths)));
    }
}
