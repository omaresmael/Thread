<?php

namespace Omaresmael\Thread\Worker\Resetters;

use Carbon\Laravel\ServiceProvider as CarbonServiceProvider;
use Illuminate\Foundation\Application;
use Omaresmael\Thread\Worker\Bootstrap;

/**
 * The sandbox's config is a copy, but the translator and Carbon's locale are shared with the base.
 */
final class RestoreLocale implements Resetter
{
    public function __invoke(Application $sandbox, Application $base): void
    {
        if ($sandbox->resolved('translator')) {
            $sandbox->setLocale(Bootstrap::baseLocale());
            $sandbox->make('translator')->setFallback(Bootstrap::baseFallbackLocale());
        }

        $carbon = new CarbonServiceProvider($sandbox);
        $carbon->setLocaleGetter(fn () => Bootstrap::baseCarbonLocale());
        $carbon->updateLocale();
    }
}
