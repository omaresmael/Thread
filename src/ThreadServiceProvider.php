<?php

namespace Omaresmael\Thread;

use Illuminate\Concurrency\ConcurrencyManager;
use Illuminate\Support\ServiceProvider;

class ThreadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/thread.php', 'thread');

        $this->app->singleton(ThreadRunner::class);
        $this->app->alias(ThreadRunner::class, 'thread');

        // Also usable as a Laravel Concurrency driver: Concurrency::driver('thread'), or CONCURRENCY_DRIVER=thread.
        $this->callAfterResolving(ConcurrencyManager::class, function (ConcurrencyManager $manager) {
            $manager->extend('thread', function ($app) {
                return $app->make(ThreadRunner::class);
            });
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/thread.php' => config_path('thread.php'),
            ], 'thread-config');
        }
    }
}
