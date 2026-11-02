<?php

namespace Omaresmael\Thread\Tests\Fixtures\Exceptions;

use RuntimeException;

class BaseFixtureException extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
