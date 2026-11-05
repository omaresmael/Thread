<?php
// php summarize.php results/<set>.jsonl [rounds]: per driver and task count, the median of the successful runs, the
// fastest–slowest range and how many rounds succeeded ("19.6 s (15.7–53.4 s, 5/5)"); peak memory as median and max.
// Two ratios per task count: "typical" = process median ÷ thread median, and "at least" = the process driver's best
// run ÷ the thread driver's worst run (a guaranteed minimum). Then every failure, with its round and reason.
// Warm-up rounds ("warmup": true) are left out of everything. A cell where any round failed is marked "!" and gets no ratio.
[$file, $rounds] = [$argv[1], (int) ($argv[2] ?? 0)];
$rows = $warmups = [];
foreach (file($file) as $i => $line) {
    $row = json_decode($line, true);
    if (! is_array($row) || ! isset($row['driver'], $row['tasks'])) {   // run.sh never writes such lines; say so if one appears
        $rows[] = ['driver' => '?', 'tasks' => 0, 'ok' => false, 'round' => '?', 'reason' => 'unreadable line '.($i + 1).' in '.basename($file)];
    } elseif ($row['warmup'] ?? false) {
        $warmups[] = $row;
    } else {
        $rows[] = $row;
    }
}
$set = ($rows[0]['set'] ?? null) ?: basename($file, '.jsonl');
$sizes = array_values(array_unique(array_column(array_filter($rows, fn ($row) => $row['tasks'] > 0), 'tasks')));
sort($sizes);
$rounds = $rounds ?: max(1, ...array_map(fn ($row) => is_int($row['round'] ?? null) ? $row['round'] : 1, $rows));

$median = function (array $values) {
    sort($values);
    $n = count($values);

    return $n === 0 ? null : ($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
};
$cell = fn (string $driver, int $tasks) => array_filter($rows, fn ($row) => $row['driver'] === $driver && $row['tasks'] === $tasks);
$values = fn (string $driver, int $tasks, string $key) => array_column(array_filter($cell($driver, $tasks), fn ($row) => $row['ok'] === true), $key);
$clean = fn (string $driver, int $tasks) => count($values($driver, $tasks, 'seconds')) === $rounds && count($cell($driver, $tasks)) === $rounds;
$time = fn (float $s, bool $unit = true) => $s < 1 ? round($s * 1000).($unit ? ' ms' : '') : number_format($s, 1).($unit ? ' s' : '');
$mb = fn (float $v) => number_format($v).' MB';

// "19.6 s (15.7–53.4 s, 5/5)": the range in one unit, the unit of its maximum.
$showTime = function (string $driver, int $tasks) use ($values, $clean, $median, $time, $rounds, $cell) {
    $v = $values($driver, $tasks, 'seconds');
    $n = count($v);
    if ($n === 0) {
        $reasons = array_unique(array_column($cell($driver, $tasks), 'reason'));

        return '! '.(count($reasons) === 1 && str_starts_with((string) reset($reasons), 'out of memory') ? 'out of memory' : 'failed')." (0/{$rounds})";
    }
    $range = max($v) < 1 ? round(min($v) * 1000).'–'.round(max($v) * 1000).' ms' : number_format(min($v), 1).'–'.number_format(max($v), 1).' s';

    return ($clean($driver, $tasks) ? '' : '! ').$time($median($v))." ({$range}, {$n}/{$rounds})";
};
$showMemory = function (string $driver, int $tasks) use ($values, $clean, $median, $mb, $rounds) {
    $v = $values($driver, $tasks, 'peak_mb');
    $n = count($v);

    return $n === 0 ? "! failed (0/{$rounds})"
        : ($clean($driver, $tasks) ? '' : '! ').$mb($median($v)).' (max '.number_format(max($v)).", {$n}/{$rounds})";
};
$ratio = fn (float $r) => ($r >= 10 ? round($r) : round($r, 1)).'x';
// [typical, at least]: medians, and the process driver's best run against the thread driver's worst.
$ratios = function (int $tasks, string $key) use ($clean, $values, $median, $ratio) {
    if (! $clean('process', $tasks) || ! $clean('thread', $tasks)) {
        return ['-', '-'];
    }
    [$p, $t] = [$values('process', $tasks, $key), $values('thread', $tasks, $key)];

    return [$ratio($median($p) / $median($t)), $ratio(min($p) / max($t))];
};

// printf pads by bytes; "–" and "÷" are multibyte, so rows are padded with mb_str_pad.
$line = fn (array $cells) => implode(' | ', array_map(fn ($c, $w) => mb_str_pad((string) $c, $w), $cells, [6, 28, 24, 8, 8]))."\n";

printf("\n== %s tasks: median (fastest–slowest, runs that succeeded), %d rounds + %d warm-up measurements discarded ==\n", $set, $rounds, count($warmups));
echo $line(['tasks', 'process time', 'thread time', 'typical', 'at least']);
echo str_repeat('-', 87), "\n";
foreach ($sizes as $tasks) {
    [$typical, $least] = $ratios($tasks, 'seconds');
    echo $line([$tasks, $showTime('process', $tasks), $showTime('thread', $tasks), $typical, $least]);
}
echo "\n", $line(['tasks', 'process peak memory', 'thread peak memory', 'typical', 'at least']);
echo str_repeat('-', 87), "\n";
foreach ($sizes as $tasks) {
    [$typical, $least] = $ratios($tasks, 'peak_mb');
    echo $line([$tasks, $showMemory('process', $tasks), $showMemory('thread', $tasks), $typical, $least]);
}
printf("\nThreads once warm (median): %s\n", implode(', ', array_map(fn ($tasks) => "{$tasks} tasks ".(($w = $median(array_filter($values('thread', $tasks, 'warm_seconds'), 'is_numeric'))) === null ? '-' : $time($w)), $sizes)));
echo "typical = process median ÷ thread median; at least = process best run ÷ thread worst run (memory: lowest ÷ highest peak).\n";
echo "\"!\" = at least one run failed: not a clean result, no ratio.\n";

$failed = array_filter($rows, fn ($row) => $row['ok'] !== true);
foreach ($sizes as $tasks) {
    foreach (['process', 'thread'] as $driver) {
        $missing = $rounds - count($cell($driver, $tasks));
        if ($missing > 0) {
            $failed[] = ['driver' => $driver, 'tasks' => $tasks, 'round' => '?', 'reason' => "{$missing} of {$rounds} rounds missing from the results"];
        }
    }
}
echo $failed ? "\nFailures (".count($failed)."):\n" : "\nNo failures.\n";
foreach ($failed as $row) {
    printf("  round %s: %s %s tasks: %s%s\n", $row['round'] ?? '?', $row['driver'], $row['tasks'], $row['reason'] ?? 'failed',
        isset($row['peak_mb']) ? " (peak {$row['peak_mb']} MB, exit ".($row['exit'] ?? '?').')' : '');
}
$warmFailed = array_filter($warmups, fn ($row) => $row['ok'] !== true);
foreach ($warmFailed as $row) {
    printf("  (warm-up, discarded) %s %s tasks: %s\n", $row['driver'], $row['tasks'], $row['reason'] ?? 'failed');
}
