<?php

namespace Omaresmael\Thread\Tests\Unit;

use Illuminate\Support\Defer\DeferredCallback;
use InvalidArgumentException;
use Omaresmael\Thread\Exceptions\ThreadUnavailableException;
use Omaresmael\Thread\Facades\Thread;
use Omaresmael\Thread\Tests\TestCase;

class ThreadRunnerTest extends TestCase
{
    public function test_run_checks_requirements_before_anything_else(): void
    {
        if (PHP_ZTS && extension_loaded('parallel')) {
            $this->assertSame([], Thread::run([]));

            return;
        }
        $this->expectException(ThreadUnavailableException::class);
        Thread::run([]);   // even with no tasks: NTS PHP fails with a clear message
    }

    public function test_run_rejects_concurrent_below_one(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('The [concurrent] value must be at least 1.'));
        Thread::run([fn () => 1], concurrent: 0);
    }

    public function test_defer_checks_requirements_when_called_not_when_invoked(): void
    {
        if (PHP_ZTS && extension_loaded('parallel')) {
            $this->assertInstanceOf(DeferredCallback::class, Thread::defer([fn () => 1]));

            return;
        }
        $this->expectException(ThreadUnavailableException::class);
        Thread::defer([fn () => 1]);   // throws here, before anything is deferred
    }

    public function test_defer_rejects_concurrent_below_one_when_called(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('The [concurrent] value must be at least 1.'));
        Thread::defer([fn () => 1], concurrent: 0);
    }
}
