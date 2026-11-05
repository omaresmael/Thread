<?php

// With a 1.2 s timeout the backstop (1.45 s) abandons a sleeping task before the
// worker's time limit (2 s) ends it with a fatal error. Workers boot the
// real-shaped fixture app, whose handler would log and print that fatal when the
// thread closes. Prints only the outcomes and abandoned counts: any other output was printed by a thread.

use Illuminate\Contracts\Console\Kernel;
use Omaresmael\Thread\Payload;
use Omaresmael\Thread\Pool\PoolOptions;
use Omaresmael\Thread\Pool\ThreadPool;
use Omaresmael\Thread\Worker\Bootstrap;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/reporting-app.php';
$app->make(Kernel::class)->bootstrap();

$pool = new ThreadPool(new PoolOptions(1, null, null, Bootstrap::autoloadPath(), __DIR__.'/reporting-app.php'));

// Reaped by a later run(), after the time limit has ended the task.
$timedOut = $pool->run([Payload::task(fn () => sleep(5))], 1.2)[0];
$abandoned = $pool->stats()['abandoned'];
usleep(1_300_000);   // until 2.75 s after dispatch: well past the 2 s time limit
$next = $pool->run([Payload::task(fn () => 1)])[0];

echo "reaped by run: {$timedOut->type->name} {$abandoned} {$next->type->name} {$pool->stats()['abandoned']}; ";

// Reaped by shutdown(), which waits for the time limit to end the task.
$timedOut = $pool->run([Payload::task(fn () => sleep(5))], 1.2)[0];
$abandoned = $pool->stats()['abandoned'];
$pool->shutdown();

echo "reaped by shutdown: {$timedOut->type->name} {$abandoned} {$pool->stats()['abandoned']}";
