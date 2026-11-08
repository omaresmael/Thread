<?php
// php server-info.php <mode>: runs inside a server container. Prints one JSON line describing the running server: the
// app's /info (PHP, SAPI, opcache, limits), FrankenPHP's threads from its admin API (how many regular PHP threads and
// worker threads, i.e. num_threads and the Octane worker count), and the server's processes.
$get = fn (string $url) => json_decode((string) @file_get_contents($url), true);
$threads = $get('http://localhost:2019/frankenphp/threads')['ThreadDebugStates'] ?? [];
$names = array_count_values(array_map(fn ($t) => str_starts_with($t['Name'] ?? '', 'Worker') ? 'worker' : 'regular', $threads));
exec('ps -eo pid,comm,nlwp,args --no-headers', $ps);
echo json_encode([
    'mode' => $argv[1] ?? '?',
    'at' => date('c'),
    'info' => $get('http://localhost/info'),
    'frankenphp_threads' => count($threads),
    'frankenphp_regular_threads' => $names['regular'] ?? 0,
    'frankenphp_worker_threads' => $names['worker'] ?? 0,
    'processes' => array_values(array_filter(array_map('trim', $ps), fn ($l) => ! preg_match('/\b(ps|sh|php server-info\.php)\b/', $l))),
    'frankenphp' => trim((string) shell_exec('frankenphp version')),
], JSON_UNESCAPED_SLASHES), "\n";
