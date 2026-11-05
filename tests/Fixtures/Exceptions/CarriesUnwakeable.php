<?php

namespace Omaresmael\Thread\Tests\Fixtures\Exceptions;

use Omaresmael\Thread\Tests\Fixtures\Unwakeable;
use RuntimeException;

/**
 * Its failure description holds an object the parent cannot unserialize.
 */
class CarriesUnwakeable extends RuntimeException
{
    public function __construct(public Unwakeable $thing)
    {
        parent::__construct('carries an unwakeable object');
    }
}
