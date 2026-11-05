<?php

use Illuminate\Support\Facades\DB;

/**
 * The benchmark's tasks, identical for both drivers. Returns [tasks, expected sum of their results].
 *
 *  - small:  a tiny CPU task, array_sum(range(1, 1000)) = 500500 per task.
 *  - sqlite: task $i loads every order of one customer (500 rows) from the SQLite database.
 *
 * One closure per source line: SerializableClosure finds a closure's code by its line, so two closures on
 * one line can silently run the wrong one.
 */
function benchmark_tasks(string $set, int $count): array
{
    $tasks = [];

    for ($i = 0; $i < $count; $i++) {
        $customer = $i % 100 + 1;

        $tasks[] = match ($set) {
            'small' => benchmark_small_task(),
            'sqlite' => benchmark_sqlite_task($customer),
            default => throw new InvalidArgumentException("Unknown task set [{$set}]: use small or sqlite."),
        };
    }

    return [$tasks, $count * ($set === 'small' ? 500500 : 500)];
}

function benchmark_small_task(): Closure
{
    return fn () => array_sum(range(1, 1000));
}

function benchmark_sqlite_task(int $customer): Closure
{
    return fn () => DB::table('orders')->where('customer_id', $customer)->get()->count();
}
