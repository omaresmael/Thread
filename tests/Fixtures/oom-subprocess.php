<?php

// Runs one out-of-memory task on a worker booting the real-shaped fixture app and
// prints only its outcome: any other output was printed by the thread.

use Illuminate\Contracts\Console\Kernel;
use Omaresmael\Thread\Payload;
use Omaresmael\Thread\Pool\PoolOptions;
use Omaresmael\Thread\Pool\ThreadPool;
use Omaresmael\Thread\Worker\Bootstrap;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/reporting-app.php';
$app->make(Kernel::class)->bootstrap();

$pool = new ThreadPool(new PoolOptions(1, null, null, Bootstrap::autoloadPath(), __DIR__.'/reporting-app.php'));

$outcome = $pool->run([
    Payload::task(function () {
        ini_set('memory_limit', '32M');

        return strlen(str_repeat('x', 64 << 20));
    }),
])[0];

$pool->shutdown();

echo $outcome->type->name.': '.$outcome->reason;
