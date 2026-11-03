<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Illuminate\Foundation\Application;

/**
 * Connections outlive tasks, and a query log left enabled grows without bound.
 */
final class FlushQueryLog implements Resetter
{
    public function __invoke(Application $sandbox, Application $base): void
    {
        if (! $sandbox->resolved('db')) {
            return;
        }

        foreach ($sandbox->make('db')->getConnections() as $connection) {
            $connection->flushQueryLog();
            $connection->disableQueryLog();
        }
    }
}
