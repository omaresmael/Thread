<?php
// php record-web.php <load summary file> <memory file> <oom kills> <server state> <mode> <driver> <tasks> <clients> <round> <warmup>
// Turns one web measurement into exactly one JSON line, success or failure. A measurement succeeds when the load client
// finished and every request got a 200 with "ok": true; otherwise it fails, with the reason (errors are counted either way).
[, $loadFile, $memFile, $oom, $server, $mode, $driver, $tasks, $clients, $round, $warmup] = $argv;
$load = json_decode((string) @file_get_contents($loadFile), true);
$mem = json_decode((string) @file_get_contents($memFile), true);
$oom = (int) $oom;

$row = ['mode' => $mode, 'round' => (int) $round, 'warmup' => $warmup === '1', 'driver' => $driver,
    'tasks' => (int) $tasks, 'clients' => (int) $clients];

$load = is_array($load) && isset($load['requests']) ? $load : null;
$reason = match (true) {
    $server !== 'running' => "server {$server}",
    $oom > 0 => 'out of memory',
    $load === null => 'load client failed: '.mb_strimwidth(trim((string) @file_get_contents($loadFile)), 0, 160, '…'),
    $load['errors'] > 0 => "{$load['errors']} of {$load['requests']} requests failed: ".implode('; ', array_map(
        fn ($r, $n) => "{$r} ({$n}×)", array_keys($load['error_reasons']), $load['error_reasons'])),
    default => null,
};

echo json_encode($row + ['ok' => $reason === null] + ($reason === null ? [] : ['reason' => mb_strimwidth($reason, 0, 300, '…')])
    + ($load ?? ['errors' => null])
    + ['peak_mb' => is_array($mem) ? (int) round($mem['peak_kb'] / 1024) : null,
        'start_mb' => is_array($mem) && $mem['start_kb'] !== null ? (int) round($mem['start_kb'] / 1024) : null,
        'max_processes' => $mem['max_processes'] ?? null, 'memory_samples' => $mem['samples'] ?? null, 'memory_seconds' => $mem['seconds'] ?? null,
        'oom_kills' => $oom, 'server' => $server],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
