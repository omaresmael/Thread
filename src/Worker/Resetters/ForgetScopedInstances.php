<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Illuminate\Foundation\Application;

/**
 * Scoped bindings must start fresh for every task. The sandbox clears its own, but services
 * created at boot still point at the base app: scoped instances they create during a task
 * are stored in the base and would leak into the next task. Clear them from the base too.
 */
final class ForgetScopedInstances implements Resetter
{
    public function __invoke(Application $sandbox, Application $base): void
    {
        $base->forgetScopedInstances();
    }
}
