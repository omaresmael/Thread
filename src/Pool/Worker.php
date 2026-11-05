<?php

namespace Omaresmael\Thread\Pool;

use Closure;
use parallel\Future;
use parallel\Runtime;

/**
 * The parent's handle on one worker thread.
 */
final class Worker
{
    /** Started beyond `max_workers` for a single call. */
    public bool $temporary = false;

    public bool $ready = false;

    /** @var array{boot_ms: float, memory: int}|null what the boot task returned */
    public ?array $boot = null;

    /** Key of the task it is running, null when idle. */
    public int|string|null $key = null;

    public ?Future $future = null;

    /** The `run()` call its current task belongs to: a late result from an aborted call is not another call's outcome. */
    public ?int $call = null;

    public ?float $dispatchedAt = null;

    public ?float $firstDispatchedAt = null;

    public ?float $readyAt = null;

    public ?float $deadline = null;

    public int $tasks = 0;

    /** Heap size the worker reported with its last result, in bytes. */
    public ?int $memory = null;

    public function __construct(
        public readonly int $id,
        public readonly Runtime $runtime,
    ) {}

    /**
     * Take the thread's last error: after a fatal error (or a time limit) it is that error.
     * Only call this once the runtime is idle, as it is after a killed task.
     *
     * The error is cleared, so Laravel's shutdown handler finds nothing to log
     * or render into the parent's terminal when the thread closes: the parent reports it instead.
     *
     * The closure is unbound from this class: after a fatal error the thread's
     * autoloader can no longer run, and a closure scoped to a class the thread
     * has not loaded makes parallel autoload it — a fatal that ends the whole process.
     *
     * @return array{type: int, message: string, file: string, line: int}|null
     */
    public function postMortem(): ?array
    {
        $takeLastError = static function (): ?array {
            $error = error_get_last();
            error_clear_last();

            return $error;
        };

        return $this->runtime->run(Closure::bind($takeLastError, null, null))->value();
    }
}
