<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Illuminate\Foundation\Application;

/**
 * An authenticated user must not carry over to the next task. The gate resolves
 * its user through the auth manager's resolver, which `Auth::shouldUse()` replaces.
 * The `auth.driver` shortcut holds a guard of its own, so it is dropped too.
 */
final class ForgetAuthGuards implements Resetter
{
    public function __invoke(Application $sandbox, Application $base): void
    {
        foreach ([$sandbox, $base] as $app) {
            if ($app->resolved('auth.driver')) {
                $app->forgetInstance('auth.driver');
            }
        }

        if (! $sandbox->resolved('auth')) {
            return;
        }

        $auth = $sandbox->make('auth');

        $auth->forgetGuards();
        $auth->resolveUsersUsing(fn ($guard = null) => $auth->guard($guard)->user());
    }
}
