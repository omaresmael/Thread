<?php

namespace App\Http\Controllers;

use App\Benchmark\Tasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;
use ReflectionClass;
use Throwable;

class BenchmarkController
{
    /** GET /bench?driver=sync|thread|process&tasks=N&task=report|wait: one Concurrency run() call per request. */
    public function run(Request $request): JsonResponse
    {
        $driver = (string) $request->query('driver', 'sync');
        $count = (int) $request->query('tasks', 2);
        $kind = (string) $request->query('task', 'report');
        abort_unless(in_array($driver, ['sync', 'thread', 'process'], true) && in_array($kind, Tasks::KINDS, true) && $count > 0 && $count <= 100, 400);

        $start = hrtime(true);

        try {
            $results = Concurrency::driver($driver)->run(Tasks::make($count, $kind));
            $ok = Tasks::verify($results, $count, $kind);

            return response()->json([
                'ok' => $ok, 'driver' => $driver, 'tasks' => $count, 'ms' => round((hrtime(true) - $start) / 1e6, 2),
            ] + ($ok ? [] : ['reason' => 'wrong results']), $ok ? 200 : 500);
        } catch (Throwable $e) {
            $message = trim(strtok(trim($e->getMessage()), "\n") ?: '');

            return response()->json([
                'ok' => false, 'driver' => $driver, 'tasks' => $count, 'ms' => round((hrtime(true) - $start) / 1e6, 2),
                'reason' => (new ReflectionClass($e))->getShortName().': '.mb_strimwidth($message, 0, 200, '…'),
            ], 500);
        }
    }

    /** GET /info: the server's PHP environment, recorded once per server start. */
    public function info(): JsonResponse
    {
        $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;

        return response()->json([
            'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'zts' => PHP_ZTS,
            'parallel' => phpversion('parallel'),
            'opcache_enabled' => is_array($opcache) && $opcache['opcache_enabled'],
            'jit' => is_array($opcache) ? ($opcache['jit']['on'] ?? false) : false,
            'opcache_enable_cli' => ini_get('opcache.enable_cli'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'octane' => isset($_SERVER['LARAVEL_OCTANE']),
            'thread_max_workers' => config('thread.max_workers'),
            'laravel' => app()->version(),
        ]);
    }
}
