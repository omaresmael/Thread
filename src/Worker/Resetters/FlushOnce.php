<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Illuminate\Foundation\Application;
use Illuminate\Support\Once;

/**
 * Values memoized with `once()` live in a static that every task on the worker shares.
 */
final class FlushOnce implements Resetter
{
    public function __invoke(Application $sandbox, Application $base): void
    {
        if (class_exists(Once::class)) {
            Once::flush();
        }
    }
}
