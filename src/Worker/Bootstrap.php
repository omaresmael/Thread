<?php

namespace Omaresmael\Thread\Worker;

use Carbon\Carbon;
use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use LogicException;
use ReflectionClass;

/**
 * Boots Laravel once per thread and keeps the pristine app every task is cloned from.
 */
final class Bootstrap
{
    private static ?Application $base = null;

    private static string $baseLocale = 'en';

    private static string $baseFallbackLocale = 'en';

    private static string $baseCarbonLocale = 'en';

    /**
     * Boot the application in this thread.
     *
     * @return array{boot_ms: float, memory: int}
     */
    public static function boot(string $appFile): array
    {
        $start = hrtime(true);

        // A thread starts with php.ini's max_execution_time (30 s under a web server).
        // Only a task's own timeout may limit it.
        set_time_limit(0);

        // Fatals are recovered by the parent's post-mortem, not printed into its terminal.
        ini_set('display_errors', '0');

        $app = require $appFile;
        $app->make(Kernel::class)->bootstrap();
        $app->instance('thread.worker', true);

        self::adopt($app);

        return ['boot_ms' => (hrtime(true) - $start) / 1e6, 'memory' => memory_get_usage()];
    }

    /**
     * Use an already booted application as the base every task is cloned from.
     */
    public static function adopt(Application $app): void
    {
        self::$base = $app;
        self::$baseLocale = (string) $app->getLocale();
        self::$baseFallbackLocale = (string) $app->getFallbackLocale();
        self::$baseCarbonLocale = Carbon::getLocale();
    }

    public static function base(): Application
    {
        return self::$base ?? throw new LogicException('The worker has not been booted.');
    }

    /**
     * The locale the application booted with, which every task starts from.
     */
    public static function baseLocale(): string
    {
        return self::$baseLocale;
    }

    /**
     * The translator's fallback locale the application booted with.
     */
    public static function baseFallbackLocale(): string
    {
        return self::$baseFallbackLocale;
    }

    /**
     * Carbon's locale when the application booted.
     */
    public static function baseCarbonLocale(): string
    {
        return self::$baseCarbonLocale;
    }

    /**
     * The Composer autoloader a worker runtime must load before it can boot.
     */
    public static function autoloadPath(): string
    {
        return dirname((new ReflectionClass(ClassLoader::class))->getFileName(), 2).'/autoload.php';
    }
}
