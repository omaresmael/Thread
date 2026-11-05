<?php

namespace Omaresmael\Thread\Tests\Fixtures;

use RuntimeException;

/**
 * Serializes in the worker but cannot be unserialized: only the parent ever unserializes it.
 */
class Unwakeable
{
    public function __wakeup(): void
    {
        throw new RuntimeException('Unwakeable cannot be unserialized.');
    }
}
