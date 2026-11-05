<?php
// php record.php <stdout file> <stderr file> <exit code> <oom kills> <peak MB> <opcache> <driver> <tasks> <set> <round> <wall seconds>
// Turns one measurement into exactly one JSON line, success or failure. bench.php prints a JSON line itself
// (also when run() throws); when it printed none, exited non-zero, or the container killed processes for
// running out of memory, this records a failure with the reason.
[, $stdout, $stderr, $exit, $oomKills, $peak, $opcache, $driver, $tasks, $set, $round, $wall] = $argv + [11 => null];
[$exit, $oomKills, $tasks] = [(int) $exit, (int) $oomKills, (int) $tasks];

$lines = array_filter(array_map('trim', @file($stdout) ?: []), fn ($line) => $line !== '');
$row = json_decode((string) end($lines), true);
$row = is_array($row) && isset($row['ok']) ? $row : null;

// Warm-up measurements (BENCH_WARMUP=1, set by run.sh) are kept for inspection but left out of every statistic.
$base = ['set' => $set, 'round' => (int) $round, 'warmup' => getenv('BENCH_WARMUP') === '1', 'driver' => $driver, 'tasks' => $tasks];
$extra = ['exit' => $exit, 'oom_kills' => $oomKills, 'peak_mb' => (int) $peak, 'opcache_cli' => (int) $opcache,
    'wall_seconds' => $wall === null ? null : (float) $wall];   // the whole php bench.php call, boot included

if ($row !== null && $row['ok'] === true && $exit === 0) {
    echo json_encode($base + $row + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

// The first meaningful line of what PHP printed, for failures bench.php could not report itself.
$output = trim(preg_replace('/\e\[[0-9;]*m/', '', implode("\n", [@file_get_contents($stderr), implode("\n", $lines)])));
$first = trim((string) current(array_filter(array_map('trim', explode("\n", $output)), fn ($line) => $line !== '')));

$reason = match (true) {
    $oomKills > 0 => 'out of memory',
    $exit === -1 => 'measurement lost: its shell was killed before it reported',
    $exit === 124 => 'stopped after 15 minutes',
    $row !== null && isset($row['reason']) => $row['reason'],
    default => "exit {$exit}".($first === '' ? '' : ': '.mb_strimwidth($first, 0, 160, '…')),
};

echo json_encode($base + ['ok' => false, 'reason' => $reason] + ($row ?? [])
    + ['seconds' => null, 'warm_seconds' => null] + $extra
    + ($row === null && $first !== '' ? ['message' => mb_strimwidth($first, 0, 300, '…')] : []),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
