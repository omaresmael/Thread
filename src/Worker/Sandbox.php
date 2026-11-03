<?php

namespace Omaresmael\Thread\Worker;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Contracts\Pipeline\Hub;
use Illuminate\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * A per-task octane-style app clone
 */
final class Sandbox
{
    /**
     * Services that hold a reference to the app, and the setter that swaps it
     */
    private const APP_AWARE_SERVICES = [
        'auth' => 'setApplication',
        Gate::class => 'setContainer',
        BroadcastFactory::class => 'setApplication',
        'cache' => 'setApplication',
        'db' => 'setApplication',
        'filesystem' => 'setApplication',
        'log' => 'setApplication',
        'mail.manager' => 'setApplication',
        ChannelManager::class => 'setContainer',
        Hub::class => 'setContainer',
        'queue' => 'setApplication',
        'validator' => 'setContainer',
        'view' => 'setContainer',
    ];

    /**
     * If anything fails part-way, the base is restored before the error propagates.
     */
    public static function enter(Application $base): Application
    {
        $sandbox = clone $base;

        try {
            $sandbox->instance('app', $sandbox);
            $sandbox->instance(Container::class, $sandbox);
            $sandbox->instance('config', clone $base->make('config'));

            // `URL::forceRootUrl()` and `forceScheme()` mutate the generator, which the clone shares.
            if ($base->resolved('url')) {
                $sandbox->instance('url', clone $base->make('url'));
            }

            // The clone shares the base's resolved instances; scoped ones must start fresh.
            $sandbox->forgetScopedInstances();

            self::pointServicesAt($base, $sandbox);

            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($sandbox);
            Container::setInstance($sandbox);
        } catch (Throwable $e) {
            try {
                self::leave($base);
            } catch (Throwable) {
                //
            }

            throw $e;
        }

        return $sandbox;
    }

    /**
     * Every step is attempted even when an earlier one fails, so the base is
     * restored as far as possible; the first failure is rethrown afterwards.
     */
    public static function leave(Application $base): void
    {
        $failure = null;

        foreach (self::APP_AWARE_SERVICES as $abstract => $setter) {
            try {
                self::point($base, $abstract, $setter, $base);
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($base);
        Container::setInstance($base);

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Only services already resolved on the base are touched: anything else is
     * resolved lazily by whichever app is current.
     */
    private static function pointServicesAt(Application $base, Application $app): void
    {
        foreach (self::APP_AWARE_SERVICES as $abstract => $setter) {
            self::point($base, $abstract, $setter, $app);
        }
    }

    private static function point(Application $base, string $abstract, string $setter, Application $app): void
    {
        if (! $base->resolved($abstract)) {
            return;
        }

        $service = $base->make($abstract);

        // An app may bind its own implementation: one without the setter must not fail every task.
        if (method_exists($service, $setter)) {
            $service->{$setter}($app);
        }
    }
}
