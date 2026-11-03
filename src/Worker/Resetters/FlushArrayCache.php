<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Illuminate\Foundation\Application;

/**
 * The array store lives in the shared cache manager, so what a task put in it would outlive the task.
 */
final class FlushArrayCache implements Resetter
{
    public function __invoke(Application $sandbox, Application $base): void
    {
        if (! $sandbox->resolved('cache') || ! $sandbox->make('config')->get('cache.stores.array')) {
            return;
        }

        $sandbox->make('cache')->store('array')->flush();
    }
}
