<?php

namespace Omaresmael\Thread\Exceptions;

use RuntimeException;

class ThreadUnavailableException extends RuntimeException
{
    /**
     * @param  array<int, string>  $missing
     */
    public static function missing(array $missing): self
    {
        return new self('omaresmael/thread requires ZTS PHP with ext-parallel: '.implode('; ', $missing).'.');
    }
}
