<?php

namespace Omaresmael\Thread\Exceptions;

use RuntimeException;

class WorkerCrashedException extends RuntimeException
{
    public function __construct(
        public readonly int|string $key,
        public readonly string $reason,
    ) {
        parent::__construct("Thread worker crashed while running task [{$key}]: {$reason}");
    }

    public static function for(int|string $key, string $reason): self
    {
        return new self($key, $reason);
    }
}
