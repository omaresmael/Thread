<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Illuminate\Foundation\Application;

/**
 * A transaction a task left open would be inherited by the next task on the same connection.
 */
final class RollbackTransactions implements Resetter
{
    public function __invoke(Application $sandbox, Application $base): void
    {
        if (! $sandbox->resolved('db')) {
            return;
        }

        foreach ($sandbox->make('db')->getConnections() as $connection) {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
        }
    }
}
