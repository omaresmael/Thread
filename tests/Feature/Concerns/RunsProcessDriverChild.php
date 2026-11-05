<?php

namespace Omaresmael\Thread\Tests\Feature\Concerns;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\PreCondition;

/**
 * For tests that run Laravel's process driver, whose child runs `php artisan
 * invoke-serialized-closure` in Testbench's skeleton: its autoloader lives in
 * the package (TESTBENCH_WORKING_PATH) and its app key comes from APP_KEY,
 * which must match the key the parent signs closures with. They go in $_ENV
 * because Symfony Process only hands its child the getenv() variables that
 * are also in $_SERVER, which putenv() does not set.
 *
 * Used by classes that define APP_KEY (Tests\TestCase).
 */
trait RunsProcessDriverChild
{
    /** @var array<string, string> */
    private array $childEnv = [];

    /** @var array<string, string> what $_ENV held before, for the variables set */
    private array $previousEnv = [];

    /** After setUp (Testbench's own setUp may touch $_ENV), and not at all when setUp skips the test. */
    #[PreCondition]
    protected function setUpProcessDriverChildEnv(): void
    {
        $this->childEnv = [
            'TESTBENCH_WORKING_PATH' => dirname(__DIR__, 3),
            'APP_KEY' => self::APP_KEY,
        ];

        foreach ($this->childEnv as $name => $value) {
            if (array_key_exists($name, $_ENV)) {
                $this->previousEnv[$name] = $_ENV[$name];
            }

            $_ENV[$name] = $value;
        }
    }

    #[After]
    protected function tearDownProcessDriverChildEnv(): void
    {
        foreach ($this->childEnv as $name => $value) {
            if (array_key_exists($name, $this->previousEnv)) {
                $_ENV[$name] = $this->previousEnv[$name];
            } else {
                unset($_ENV[$name]);
            }
        }

        $this->childEnv = [];
        $this->previousEnv = [];
    }
}
