<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Illuminate\Foundation\Application;

/**
 * Puts shared worker state back the way the next task expects to find it.
 *
 * Resolved services are shared between the pristine base app and each task's
 * sandbox, so state a task leaves on them survives into the next task.
 * A service that was never resolved has nothing to reset: do nothing for it.
 */
interface Resetter
{
    public function __invoke(Application $sandbox, Application $base): void;
}
