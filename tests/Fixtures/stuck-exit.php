<?php

// Boots the fixture app as the parent (the same app the workers boot), runs one task
// stuck 3 s in a socket read with a 1 s timeout and prints how many seconds run()
// took to throw. The process then exits, which waits for the stuck thread.

use Illuminate\Contracts\Console\Kernel;
use Omaresmael\Thread\Exceptions\TaskTimedOutException;
use Omaresmael\Thread\Facades\Thread;
use Omaresmael\Thread\ThreadServiceProvider;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->register(ThreadServiceProvider::class);

// The abandon warning goes to stderr, not into the fixture app's storage.
config(['logging.default' => 'stderr']);

// One call first, so the timing covers the timeout path, not a cold process loading classes.
Thread::run([fn () => 1]);

$start = microtime(true);

try {
    Thread::run([
        function () {
            [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            stream_set_timeout($a, 3);

            return fread($a, 1);
        },
    ], 1);

    echo 'no exception';
} catch (TaskTimedOutException) {
    echo round(microtime(true) - $start, 3);
}
