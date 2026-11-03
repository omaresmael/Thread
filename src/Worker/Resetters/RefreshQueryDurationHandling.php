<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Illuminate\Foundation\Application;

/**
 * `DB::whenQueryingForLongerThan()` sums query time per connection and runs each handler once;
 * both would otherwise span every task the connection serves.
 */
final class RefreshQueryDurationHandling implements Resetter
{
    public function __invoke(Application $sandbox, Application $base): void
    {
        if (! $sandbox->resolved('db')) {
            return;
        }

        foreach ($sandbox->make('db')->getConnections() as $connection) {
            if (
                method_exists($connection, 'resetTotalQueryDuration')
                && method_exists($connection, 'allowQueryDurationHandlersToRunAgain')
            ) {
                $connection->resetTotalQueryDuration();
                $connection->allowQueryDurationHandlersToRunAgain();
            }
        }
    }
}
