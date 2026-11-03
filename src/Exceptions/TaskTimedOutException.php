<?php

namespace Omaresmael\Thread\Exceptions;

use RuntimeException;

class TaskTimedOutException extends RuntimeException
{
    public function __construct(
        public readonly int|string $key,
        public readonly float $seconds,
    ) {
        $formatted = self::formatSeconds($seconds);

        parent::__construct("Task [{$key}] exceeded the timeout of {$formatted} seconds.");
    }

    /**
     * Seconds to at most three decimals, without trailing zeros (`1`, `0.5`).
     */
    public static function formatSeconds(float $seconds): string
    {
        return rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');
    }

    public static function for(int|string $key, float $seconds): self
    {
        return new self($key, $seconds);
    }
}
