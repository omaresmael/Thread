<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Max Workers
    |--------------------------------------------------------------------------
    |
    | Upper limit on workers in the pool. Workers are started on demand:
    | a task uses an idle worker if one exists, otherwise a new worker is
    | started until this limit is reached; beyond that, tasks wait in a
    | queue. Started workers stay alive as long as the parent process.
    |
    */

    'max_workers' => (int) env('THREAD_MAX_WORKERS', 4),

    /*
    |--------------------------------------------------------------------------
    | Recycling
    |--------------------------------------------------------------------------
    |
    | A worker is replaced after handling "max_tasks" tasks, or once its
    | reported memory usage exceeds "max_memory" megabytes. Set either to
    | null to disable that limit. "max_tasks" = 1 boots a fresh worker for
    | every task (hard isolation, no speedup).
    |
    */

    'max_tasks' => env('THREAD_MAX_TASKS', 500),

    'max_memory' => env('THREAD_MAX_MEMORY', 128),

];
