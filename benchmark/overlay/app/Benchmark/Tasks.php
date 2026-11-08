<?php

namespace App\Benchmark;

use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The benchmark's tasks, the same for every driver and every flow. Two kinds:
 *
 * - report (CPU-bound): a monthly revenue report over all 50,000 orders, grouped by customer and month with the query
 *   builder (one SQLite query, a full scan, ~1,200 result rows). SQLite runs inside PHP: the task is pure CPU work.
 * - wait (I/O-bound): one query to MySQL, in its own container, that waits 30 ms on the server (SLEEP) and returns a
 *   customer's order count. It stands for what most web tasks do: wait on a database server or an API.
 */
class Tasks
{
    public const KINDS = ['report', 'wait'];

    /** @return list<Closure> $count identical tasks */
    public static function make(int $count, string $kind = 'report'): array
    {
        $tasks = [];

        for ($i = 0; $i < $count; $i++) {
            $tasks[] = self::task($kind);
        }

        return $tasks;
    }

    /** What one task returns when it worked: rows in a report, or a customer's orders. */
    public static function expected(string $kind): int
    {
        return match ($kind) {
            'report' => 1200,   // 100 customers × 12 months
            'wait' => 500,
            default => throw new InvalidArgumentException("Unknown task [{$kind}]."),
        };
    }

    /** True when every task returned the expected result. */
    public static function verify(array $results, int $count, string $kind = 'report'): bool
    {
        return count($results) === $count && array_sum($results) === $count * self::expected($kind);
    }

    public static function task(string $kind): Closure
    {
        return match ($kind) {
            'report' => self::report(),
            'wait' => self::wait(),
            default => throw new InvalidArgumentException("Unknown task [{$kind}]."),
        };
    }

    public static function report(): Closure
    {
        return fn () => DB::table('orders')
            ->selectRaw('customer_id, substr(created_at, 1, 7) as month, count(*) as orders')
            ->selectRaw('sum(total_cents) as revenue, avg(total_cents) as average, max(total_cents) as largest')
            ->selectRaw("sum(case when status = 'refunded' then total_cents else 0 end) as refunded")
            ->selectRaw("count(distinct status) as statuses")
            ->groupBy('customer_id', 'month')
            ->orderBy('customer_id')
            ->orderBy('month')
            ->get()
            ->count();
    }

    public static function wait(): Closure
    {
        return fn () => (int) DB::connection('bench_mysql')->selectOne(
            'select sleep(0.03) as waited, (select count(*) from orders where customer_id = 1) as orders'
        )->orders;
    }
}
