<?php

namespace Omaresmael\Thread\Tests\Unit;

use Omaresmael\Thread\Exceptions\TaskTimedOutException;
use Omaresmael\Thread\Exceptions\ThreadUnavailableException;
use Omaresmael\Thread\Exceptions\WorkerCrashedException;
use Omaresmael\Thread\Tests\TestCase;
use RuntimeException;

class ExceptionsTest extends TestCase
{
    public function test_exception_messages(): void
    {
        $this->assertSame('Task [a] exceeded the timeout of 1.5 seconds.', TaskTimedOutException::for('a', 1.5)->getMessage());
        $this->assertSame('Thread worker crashed while running task [3]: boom', WorkerCrashedException::for(3, 'boom')->getMessage());
    }

    public function test_timeout_seconds_are_trimmed(): void
    {
        $this->assertSame('Task [k] exceeded the timeout of 1 seconds.', TaskTimedOutException::for('k', 1.0)->getMessage());
        $this->assertSame('Task [k] exceeded the timeout of 0.25 seconds.', TaskTimedOutException::for('k', 0.25)->getMessage());
        $this->assertSame('Task [k] exceeded the timeout of 2.333 seconds.', TaskTimedOutException::for('k', 2.3333)->getMessage());
    }

    public function test_exceptions_expose_their_context(): void
    {
        $timedOut = TaskTimedOutException::for('a', 1.5);
        $this->assertSame('a', $timedOut->key);
        $this->assertSame(1.5, $timedOut->seconds);

        $crashed = WorkerCrashedException::for(3, 'boom');
        $this->assertSame(3, $crashed->key);
        $this->assertSame('boom', $crashed->reason);
    }

    public function test_thread_unavailable_message_lists_missing_requirements(): void
    {
        $exception = ThreadUnavailableException::missing(['PHP is not thread-safe (ZTS)', 'ext-parallel is not loaded']);

        $this->assertSame(
            'omaresmael/thread requires ZTS PHP with ext-parallel: PHP is not thread-safe (ZTS); ext-parallel is not loaded.',
            $exception->getMessage(),
        );
    }

    public function test_all_exceptions_extend_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, TaskTimedOutException::for('a', 1.0));
        $this->assertInstanceOf(RuntimeException::class, WorkerCrashedException::for('a', 'x'));
        $this->assertInstanceOf(RuntimeException::class, ThreadUnavailableException::missing(['x']));
    }
}
