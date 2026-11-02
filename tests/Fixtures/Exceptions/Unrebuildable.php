<?php

namespace Omaresmael\Thread\Tests\Fixtures\Exceptions;

use RuntimeException;

/**
 * The required $code is not kept as a readable property, so it cannot be
 * recovered from the thrown instance and the exception cannot be rebuilt.
 */
class Unrebuildable extends RuntimeException
{
    public function __construct(string $message, int $code)
    {
        parent::__construct($message, $code);
    }
}
