<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Illuminate\Foundation\Application;

/**
 * Once a connection has written, reads can be pinned to the write connection ("sticky");
 * that must not carry over to the next task.
 */
final class FlushDatabaseRecordModificationState implements Resetter
{
    public function __invoke(Application $sandbox, Application $base): void
    {
        if (! $sandbox->resolved('db')) {
            return;
        }

        foreach ($sandbox->make('db')->getConnections() as $connection) {
            $connection->forgetRecordModificationState();
        }
    }
}
