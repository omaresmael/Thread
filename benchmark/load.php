<?php
// php load.php <url> <requests> <clients> [raw file]: the load client. Keeps <clients> requests in flight (curl_multi)
// until <requests> have completed, and prints one JSON summary line: latency p50/p95/max (ms), requests per second,
// HTTP codes, and errors. A request is an error when it times out (120 s), fails to connect, gets a status other than
// 200, or its JSON body says "ok": false. With [raw file], every request's latency, status and body go there as JSON lines.
[, $url, $total, $clients] = $argv;
[$total, $clients] = [(int) $total, (int) $clients];
$raw = isset($argv[4]) ? fopen($argv[4], 'w') : null;

$multi = curl_multi_init();
$started = $done = 0;
$latencies = $codes = $errors = $inner = [];

$add = function () use (&$started, $multi, $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_FORBID_REUSE => false]);
    curl_setopt($ch, CURLOPT_PRIVATE, (string) hrtime(true));
    curl_multi_add_handle($multi, $ch);
    $started++;
};

$t0 = hrtime(true);
while ($started < min($clients, $total)) {
    $add();
}
while ($done < $total) {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.01);
    while ($info = curl_multi_info_read($multi)) {
        $ch = $info['handle'];
        $ms = (hrtime(true) - (int) curl_getinfo($ch, CURLINFO_PRIVATE)) / 1e6;
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = (string) curl_multi_getcontent($ch);
        $json = json_decode($body, true);
        $curlError = $info['result'] !== CURLE_OK ? curl_strerror($info['result']) : null;
        $ok = $curlError === null && $code === 200 && is_array($json) && ($json['ok'] ?? false) === true;
        $latencies[] = $ms;
        $codes[$code] = ($codes[$code] ?? 0) + 1;
        if (is_array($json) && isset($json['ms'])) {
            $inner[] = $json['ms'];
        }
        if (! $ok) {
            $errors[] = $curlError !== null ? "curl: {$curlError}"
                : (is_array($json) && isset($json['reason']) ? "HTTP {$code}: {$json['reason']}" : "HTTP {$code}: ".mb_strimwidth(trim(strip_tags($body)), 0, 120, '…'));
        }
        if ($raw) {
            fwrite($raw, json_encode(['i' => $done, 'ms' => round($ms, 2), 'code' => $code, 'ok' => $ok,
                'body' => is_array($json) ? $json : mb_strimwidth($body, 0, 300, '…'), 'curl' => $curlError])."\n");
        }
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        $done++;
        if ($started < $total) {
            $add();
        }
    }
}
$wall = (hrtime(true) - $t0) / 1e9;

function percentile(array $values, float $p): ?float
{
    if (! $values) {
        return null;
    }
    sort($values);

    return round($values[(int) min(count($values) - 1, max(0, ceil($p / 100 * count($values)) - 1))], 2);
}

$reasons = array_count_values($errors);
arsort($reasons);
echo json_encode([
    'requests' => $total, 'clients' => $clients, 'wall_s' => round($wall, 3), 'rps' => round($total / $wall, 2),
    'p50_ms' => percentile($latencies, 50), 'p95_ms' => percentile($latencies, 95),
    'min_ms' => round(min($latencies), 2), 'max_ms' => round(max($latencies), 2),
    'run_ms_p50' => percentile($inner, 50), 'run_ms_p95' => percentile($inner, 95),   // run() inside PHP, as the server measured it
    'codes' => $codes, 'errors' => count($errors), 'error_reasons' => array_slice($reasons, 0, 5, true),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
