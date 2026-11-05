<?php
// Records the peak combined memory (PSS, in KB) of every php process except itself, every 10 ms,
// into /tmp/peak, until /tmp/stop exists.
$self = getmypid();
$peak = 0;

while (! file_exists('/tmp/stop')) {
    $total = 0;

    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) as $dir) {
        if ((int) basename($dir) === $self || trim((string) @file_get_contents("{$dir}/comm")) !== 'php') {
            continue;
        }
        if (preg_match('/^Pss:\s+(\d+)/m', (string) @file_get_contents("{$dir}/smaps_rollup"), $m)) {
            $total += (int) $m[1];
        }
    }

    if ($total > $peak) {
        $peak = $total;
        file_put_contents('/tmp/peak', $peak);
    }

    usleep(10000);
}
