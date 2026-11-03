<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Illuminate\Foundation\Application;

/**
 * Context shared with, or added to, a channel must not show up in the next task's log entries.
 * The manager clears every channel it has resolved, which includes the default one.
 */
final class FlushLogContext implements Resetter
{
    public function __invoke(Application $sandbox, Application $base): void
    {
        if (! $sandbox->resolved('log')) {
            return;
        }

        $log = $sandbox->make('log');

        if (method_exists($log, 'flushSharedContext')) {
            $log->flushSharedContext();
        }

        if (method_exists($log, 'withoutContext')) {
            $log->withoutContext();
        }
    }
}
