<?php
// php sampler.php <out file> <stop file> [interval ms] [process names]: every interval (default 10 ms), sums the memory
// (PSS, KB) of every process whose name is in the list (default "php,frankenphp"), except this sampler itself. Writes
// {"peak_kb", "start_kb", "max_processes", "samples", "seconds"} to <out file> after every new peak and once more when <stop file>
// appears. PSS counts shared pages once across processes, so the sum is what the processes take together: the server,
// its thread workers (threads of the same process), and the process driver's child PHP processes.
[, $out, $stop] = $argv;
$interval = (int) ($argv[3] ?? 10) * 1000;
$names = explode(',', $argv[4] ?? 'php,frankenphp');
$self = getmypid();
$state = ['peak_kb' => 0, 'start_kb' => null, 'max_processes' => 0, 'samples' => 0, 'seconds' => 0];
$t0 = microtime(true);
$write = function () use (&$state, $out) {
    file_put_contents("{$out}.tmp", json_encode($state));
    rename("{$out}.tmp", $out);
};

while (! file_exists($stop)) {
    [$total, $count] = [0, 0];
    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) as $dir) {
        if ((int) basename($dir) === $self || ! in_array(trim((string) @file_get_contents("{$dir}/comm")), $names, true)) {
            continue;
        }
        if (preg_match('/^Pss:\s+(\d+)/m', (string) @file_get_contents("{$dir}/smaps_rollup"), $m)) {
            $total += (int) $m[1];
            $count++;
        }
    }
    $state['samples']++;
    $state['seconds'] = round(microtime(true) - $t0, 3);
    $state['start_kb'] ??= $total;
    $state['max_processes'] = max($state['max_processes'], $count);
    if ($total > $state['peak_kb']) {
        $state['peak_kb'] = $total;
        $write();
    }
    usleep($interval);
}
$write();
