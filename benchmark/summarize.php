<?php
// php summarize.php results/<cli|web-classic|web-worker>.jsonl [rounds]: Markdown tables of one flow.
//
// Per driver and cell, the median of the runs that succeeded, the fastest–slowest range and how many rounds succeeded
// ("1.1 s (1.0–1.2 s, 5/5)"). Warm-up rounds ("warmup": true) are left out of everything. A cell where any round failed
// is marked "!" and gets no ratio; every failure is listed with its round and reason.
//
// Ratios of the thread driver against the process driver, as in the README:
//   typically = the other driver's median ÷ the thread driver's median;
//   at least  = the other driver's best run ÷ the thread driver's worst run (a minimum that held in every run).
// For req/s (higher is better) the division is the other way round. When the thread driver is the slower one, the
// ratio is shown as "slower", with "at least" = the thread driver's best run ÷ the other driver's worst.
[$file, $rounds] = [$argv[1], (int) ($argv[2] ?? 0)];
const DRIVERS = ['thread', 'process'];

$rows = $warmups = [];
foreach (file($file) as $i => $line) {
    $row = json_decode($line, true);
    if (! is_array($row) || ! isset($row['driver'], $row['tasks'])) {   // the runners never write such lines; say so if one appears
        $rows[] = ['driver' => '?', 'tasks' => 0, 'clients' => 0, 'ok' => false, 'round' => '?', 'reason' => 'unreadable line '.($i + 1)];
    } elseif (! in_array($row['driver'], DRIVERS, true)) {
        continue;   // a driver this summary does not compare (older result files also hold sync runs)
    } elseif ($row['warmup'] ?? false) {
        $warmups[] = $row;
    } else {
        $rows[] = $row;
    }
}
$web = isset($rows[0]['clients']);
$mode = $rows[0]['mode'] ?? basename($file, '.jsonl');
$rounds = $rounds ?: max(1, ...array_map(fn ($row) => is_int($row['round'] ?? null) ? $row['round'] : 1, $rows));
$cells = [];   // "tasks/clients" => [tasks, clients]
foreach ($rows as $row) {
    if ($row['tasks'] > 0) {
        $cells["{$row['tasks']}/".($row['clients'] ?? 0)] = [$row['tasks'], $row['clients'] ?? 0];
    }
}
uasort($cells, fn ($a, $b) => $a <=> $b);

function median(array $values): ?float
{
    sort($values);
    $n = count($values);

    return $n === 0 ? null : ($n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
}
$runs = fn (string $driver, array $cell) => array_values(array_filter($rows, fn ($row) => $row['driver'] === $driver
    && $row['tasks'] === $cell[0] && ($row['clients'] ?? 0) === $cell[1]));
$values = fn (string $driver, array $cell, string $key) => array_values(array_filter(array_column(
    array_filter($runs($driver, $cell), fn ($row) => $row['ok'] === true), $key), 'is_numeric'));
$clean = fn (string $driver, array $cell) => count($runs($driver, $cell)) === $rounds
    && count(array_filter($runs($driver, $cell), fn ($row) => $row['ok'] === true)) === $rounds;
$okCount = fn (string $driver, array $cell) => count(array_filter($runs($driver, $cell), fn ($row) => $row['ok'] === true));

$seconds = fn (float $s) => $s < 1 ? number_format($s * 1000).' ms' : number_format($s, $s < 10 ? 2 : 1).' s';
$ms = fn (float $v) => $seconds($v / 1000);
$rps = fn (float $v) => number_format($v, $v < 10 ? 1 : 0);
$mb = fn (float $v) => number_format($v).' MB';
$range = function (array $v, callable $format) {
    [$lo, $hi] = [$format(min($v)), $format(max($v))];
    if ($lo === $hi) {
        return $lo;
    }
    // "1.0–1.2 s": one unit when both ends share it.
    [$a, $b] = [explode(' ', $lo), explode(' ', $hi)];

    return count($a) === 2 && count($b) === 2 && $a[1] === $b[1] ? "{$a[0]}–{$b[0]} {$b[1]}" : "{$lo}–{$hi}";
};
$show = function (string $driver, array $cell, string $key, callable $format, bool $withRange = true) use ($values, $clean, $okCount, $rounds, $range, $runs) {
    $v = $values($driver, $cell, $key);
    $mark = $clean($driver, $cell) ? '' : '! ';
    if (! $v) {
        $reasons = array_unique(array_map(fn ($r) => explode(':', $r['reason'] ?? 'failed')[0], $runs($driver, $cell)));

        return "! failed (0/{$rounds}".($reasons ? ': '.implode(', ', $reasons) : '').')';
    }

    return $mark.$format(median($v)).($withRange ? ' ('.$range($v, $format).', '.$okCount($driver, $cell)."/{$rounds})" : '');
};
$fmtRatio = fn (float $r) => ($r >= 10 ? number_format($r) : number_format($r, 1)).'×';
// The thread driver against another one on one metric. $lowerIsBetter: time, latency, memory; else req/s.
$ratio = function (string $other, array $cell, string $key, bool $lowerIsBetter = true, string $better = 'faster', string $worse = 'slower') use ($values, $clean, $fmtRatio) {
    if (! $clean('thread', $cell) || ! $clean($other, $cell)) {
        return '–';
    }
    [$t, $o] = [$values('thread', $cell, $key), $values($other, $cell, $key)];
    // Express everything as "how many times better the thread driver is": > 1 better, < 1 worse.
    $typical = $lowerIsBetter ? median($o) / median($t) : median($t) / median($o);
    $atLeast = $lowerIsBetter ? min($o) / max($t) : min($t) / max($o);       // thread's worst against the other's best
    $atMost = $lowerIsBetter ? max($o) / min($t) : max($t) / min($o);        // thread's best against the other's worst
    if ($typical >= 1) {
        return $atLeast >= 1 ? "at least {$fmtRatio($atLeast)} {$better} (typically {$fmtRatio($typical)})"
            : "typically {$fmtRatio($typical)} {$better}, not in every run";
    }

    return $atMost < 1 ? "at least {$fmtRatio(1 / $atMost)} {$worse} (typically {$fmtRatio(1 / $typical)})"
        : "typically {$fmtRatio(1 / $typical)} {$worse}, not in every run";
};
$table = function (array $head, array $lines) {
    echo '| '.implode(' | ', $head)." |\n|".implode('|', array_map(fn ($h) => ':---'.(str_starts_with($h, '!') ? '' : ''), $head))."|\n";
    foreach ($lines as $line) {
        echo '| '.implode(' | ', $line)." |\n";
    }
    echo "\n";
};

printf("## %s: %d rounds (median, fastest–slowest, runs that succeeded), %d warm-up measurements discarded\n\n", $mode, $rounds, count($warmups));

if (! $web) {
    $table(['Tasks', 'thread', 'process', 'thread vs process'],
        array_map(fn ($c) => [$c[0], $show('thread', $c, 'seconds', $seconds),
            $show('process', $c, 'seconds', $seconds), $ratio('process', $c, 'seconds')], $cells));
    echo "Peak memory, all PHP processes (median, lowest–highest):\n\n";
    $table(['Tasks', 'thread', 'process', 'thread vs process'],
        array_map(fn ($c) => [$c[0], $show('thread', $c, 'peak_mb', $mb),
            $show('process', $c, 'peak_mb', $mb), $ratio('process', $c, 'peak_mb', true, 'less', 'more')], $cells));
    echo 'Threads once warm (the same call again on the booted pool, median): '.implode(', ', array_map(fn ($c) => "{$c[0]} tasks "
        .(($w = median($values('thread', $c, 'warm_seconds'))) === null ? '–' : $seconds($w)), $cells)).".\n";
} else {
    foreach (['p50_ms' => ['p50 latency (median of the runs\' p50)', $ms, true],
        'p95_ms' => ['p95 latency (median of the runs\' p95)', $ms, true],
        'rps' => ['Requests per second', $rps, false]] as $key => [$title, $format, $lower]) {
        echo "### {$title}\n\n";
        $table(['Tasks', 'Clients', 'thread', 'process', 'thread vs process'],
            array_map(fn ($c) => [$c[0], $c[1], $show('thread', $c, $key, $format),
                $show('process', $c, $key, $format), $ratio('process', $c, $key, $lower)], $cells));
    }
    echo "### Errors (failed requests, all measured runs) and peak server memory (median, lowest–highest)\n\n";
    $errors = fn (string $d, array $c) => (string) array_sum(array_map(fn ($r) => (int) ($r['errors'] ?? 0), $runs($d, $c)));
    $table(['Tasks', 'Clients', 'thread errors', 'process errors', 'thread memory', 'process memory', 'thread vs process'],
        array_map(fn ($c) => [$c[0], $c[1], $errors('thread', $c), $errors('process', $c),
            $show('thread', $c, 'peak_mb', $mb), $show('process', $c, 'peak_mb', $mb),
            $ratio('process', $c, 'peak_mb', true, 'less', 'more')], $cells));
}

echo "\"!\" = at least one run failed: not a clean result, no ratio. typically = medians; at least = the other driver's best run ÷ the thread driver's worst.\n";

$failed = array_filter($rows, fn ($row) => $row['ok'] !== true);
foreach ($cells as $cell) {
    foreach (DRIVERS as $driver) {
        $missing = $rounds - count($runs($driver, $cell));
        if ($missing > 0 && $runs($driver, $cell)) {
            $failed[] = ['driver' => $driver, 'tasks' => $cell[0], 'clients' => $cell[1], 'round' => '?', 'reason' => "{$missing} of {$rounds} rounds missing"];
        }
    }
}
echo $failed ? "\nFailures (".count($failed)."):\n" : "\nNo failures.\n";
foreach ($failed as $row) {
    printf("- round %s: %s, %s tasks%s: %s%s\n", $row['round'] ?? '?', $row['driver'], $row['tasks'],
        $web ? ", {$row['clients']} clients" : '', $row['reason'] ?? 'failed', isset($row['peak_mb']) ? " (peak {$row['peak_mb']} MB)" : '');
}
foreach (array_filter($warmups, fn ($row) => $row['ok'] !== true) as $row) {
    printf("- (warm-up, discarded) %s, %s tasks%s: %s\n", $row['driver'], $row['tasks'], $web ? ", {$row['clients']} clients" : '', $row['reason'] ?? 'failed');
}
