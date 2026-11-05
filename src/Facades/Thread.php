<?php

namespace Omaresmael\Thread\Facades;

use Illuminate\Support\Facades\Facade;
use Omaresmael\Thread\ThreadRunner;

class Thread extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ThreadRunner::class;
    }
}
