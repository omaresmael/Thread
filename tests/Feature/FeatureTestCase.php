<?php

namespace Omaresmael\Thread\Tests\Feature;

use Omaresmael\Thread\Pool\PoolOptions;
use Omaresmael\Thread\Pool\PoolRegistry;
use Omaresmael\Thread\Tests\TestCase;
use Omaresmael\Thread\ThreadRunner;

abstract class FeatureTestCase extends TestCase
{
    protected const WORKER_APP_FILE = __DIR__.'/../Fixtures/app/bootstrap/app.php';

    protected function setUp(): void
    {
        if (! PHP_ZTS || ! extension_loaded('parallel')) {
            $this->markTestSkipped('Feature tests need ZTS PHP with ext-parallel: run them with `docker compose run --rm zts vendor/bin/phpunit`.');
        }

        parent::setUp();

        ThreadRunner::useWorkerApp(self::WORKER_APP_FILE);
    }

    protected function tearDown(): void
    {
        PoolRegistry::flush();
        ThreadRunner::useWorkerApp(null);

        parent::tearDown();
    }

    /**
     * Start the pool's workers up front (default: `max_workers`) and wait until they are booted.
     */
    protected function bootWorkers(?int $workers = null): void
    {
        $pool = PoolRegistry::for($this->options(), app('log'));

        $pool->warm($workers ?? $pool->options->maxWorkers);
    }

    /**
     * The pool options a test run uses. Called with a URI, this is still
     * Laravel's `OPTIONS` request helper, whose name it shares.
     *
     * @return PoolOptions|\Illuminate\Testing\TestResponse
     */
    public function options($uri = null, array $data = [], array $headers = [])
    {
        if ($uri !== null) {
            return parent::options($uri, $data, $headers);
        }

        return PoolOptions::fromConfig(config(), self::WORKER_APP_FILE);
    }
}
