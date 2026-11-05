<?php

use Illuminate\Foundation\Application;

// The fixture app shaped like a real one: it has an exception handler (which logs
// and renders to the console), and its storage lives in a temp directory.
$app = Application::configure(basePath: __DIR__.'/app')->withExceptions()->create();
$app->useStoragePath(sys_get_temp_dir().'/thread-reporting-app');

return $app;
