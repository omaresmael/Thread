<?php

namespace Omaresmael\Thread\Pool;

use Psr\Log\LoggerInterface;

/**
 * One pool per process, keyed by PID: a forked child starts a pool of its own
 * instead of handing tasks to its parent's threads. The child still inherits
 * the parent's pool, though, and its exit then waits forever on threads that
 * do not exist in it, so a process that has used the pool must not fork.
 */
final class PoolRegistry
{
    /** @var array<int, ThreadPool> */
    private static array $pools = [];

    /**
     * The options and logger only apply when this process's pool is created.
     */
    public static function for(PoolOptions $options, ?LoggerInterface $logger = null): ThreadPool
    {
        return self::$pools[getmypid()] ??= new ThreadPool($options, $logger);
    }

    /**
     * Shut down and forget every pool
     */
    public static function flush(): void
    {
        $pools = self::$pools;
        self::$pools = [];

        foreach ($pools as $pool) {
            $pool->shutdown();
        }
    }
}
