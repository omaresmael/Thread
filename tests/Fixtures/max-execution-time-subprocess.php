<?php

// Run with `-d max_execution_time=1`, like a web server's PHP (30 s by default; the
// CLI's own default is 0). Runs a task longer than that limit with no timeout on a
// fresh worker, and prints the result or the exception.

use Illuminate\Contracts\Console\Kernel;
use Omaresmael\Thread\Facades\Thread;
use Omaresmael\Thread\ThreadServiceProvider;

require __DIR__.'/../../vendor/autoload.php';

// Only the threads run under the limit.
set_time_limit(0);

$app = require __DIR__.'/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->register(ThreadServiceProvider::class);

try {
    echo Thread::run([fn () => usleep(1_500_000) ?: 'done'])[0];
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage();
}
