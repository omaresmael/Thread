<?php

namespace Omaresmael\Thread\Tests\Fixtures\Exceptions;

use Closure;
use RuntimeException;

/**
 * Its failure description holds a Closure, which cannot be serialized.
 */
class ClosureHolder extends RuntimeException
{
    public function __construct(public Closure $callback)
    {
        parent::__construct('holds a closure');
    }
}
