<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The benchmark's MySQL connection, used by the I/O-bound task (the app's default connection stays SQLite).
     * Set here rather than in .env so it survives `php artisan optimize` and reaches every worker and child process.
     */
    public function register(): void
    {
        config(['database.connections.bench_mysql' => [
            'driver' => 'mysql',
            'host' => 'mysql',
            'port' => 3306,
            'database' => 'bench',
            'username' => 'bench',
            'password' => 'bench',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]]);
    }
}
