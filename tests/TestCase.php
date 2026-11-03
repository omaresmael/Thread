<?php

namespace Omaresmael\Thread\Tests;

use Omaresmael\Thread\ThreadServiceProvider;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The key is set with the configuration, before providers register, so the
 * parent signs serialized closures (as a real app does) and workers booting
 * the fixture app, which has the same key, accept them.
 */
#[WithConfig('app.key', TestCase::APP_KEY)]
abstract class TestCase extends Orchestra
{
    /**
     * Same key as tests/Fixtures/app/config/app.php, so workers booting the
     * fixture app can decrypt what the parent encrypts.
     */
    public const APP_KEY = 'base64:xFO4JmZD6TOmA2nqltScwCxGU/NUWGAohm8ekdqjVC0=';

    protected function getPackageProviders($app): array
    {
        return [ThreadServiceProvider::class];
    }
}
