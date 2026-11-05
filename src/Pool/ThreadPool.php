<?php

namespace Omaresmael\Thread\Pool;

use LogicException;
use Omaresmael\Thread\Exceptions\TaskTimedOutException;
use Omaresmael\Thread\Worker\Bootstrap;
use Omaresmael\Thread\Worker\TaskRunner;
use parallel\Events;
use parallel\Events\Error\Timeout;
use parallel\Events\Event;
use parallel\Events\Event\Type;
use parallel\Future;
use parallel\Future\Error\Killed;
use parallel\Runtime;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use WeakReference;

/**
 * Worker threads, the FIFO task queue, the event loop that waits on them and
 * the abandoned threads still running tasks nothing waits for.
 */
final class ThreadPool
{
    public const OPCACHE_WARNING = '[thread] opcache.enable_cli is off: each thread compiles the app itself (~100 ms and ~19 MB per worker instead of ~9 ms and ~1.5 MB)';

    /** Start of the crash reason of every task a failed boot left without a worker. */
    public const BOOT_FAILURE = 'Worker failed to boot: ';

    /** Logged when a timed-out task is left running: task key, timeout in seconds. */
    public const ABANDONED_WARNING = '[thread] Task [%s] timed out after %ss and is still running in an abandoned thread; process exit will wait for it.';

    /** How long after its timeout the parent stops waiting for a task the worker's own time limit has not ended, in seconds. */
    private const BACKSTOP_GRACE = 0.25;

    /** Longest single wait when no deadline is nearer, in seconds. */
    private const IDLE_TICK = 1.0;

    /** Error types that end a script (the ones Laravel's shutdown handler reports as fatal). */
    private const FATAL_ERRORS = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_PARSE;

    private static bool $warnedAboutOpcache = false;

    /** @var array<int, Worker> live workers by id */
    private array $workers = [];

    /**
     * Workers left running a task no call waits for any more (timed out, or
     * left by a call that ended with an exception), by id. They count toward
     * no limit and are closed once their task ends.
     *
     * @var array<int, array{Worker, Future}>
     */
    private array $abandoned = [];

    /** @var array<int, Future> boot futures of workers whose boot has not been seen yet */
    private array $boots = [];

    /** @var array<string, array{int, bool}> event target name => [worker id, is boot] */
    private array $targets = [];

    private readonly Events $events;

    private readonly int $pid;

    private int $started = 0;

    private int $targetSequence = 0;

    /** Number of the current (or last) `run()` call. */
    private int $call = 0;

    /** @var array<array-key, string> payloads of the current call not yet dispatched */
    private array $queue = [];

    /** @var array<array-key, Outcome> */
    private array $outcomes = [];

    /** The current call's timeout per task, in seconds. */
    private ?float $timeout = null;

    /** Worker-side time limit for the current call's tasks, in whole seconds. */
    private ?int $timeLimit = null;

    /** Set once a boot fails: no more workers are started for the rest of the call. */
    private ?string $bootFailure = null;

    /** Most workers the current call may start up to, and most tasks it may have in flight. */
    private int $limit = 0;

    /** @var array<int, Worker> every worker that ran a task in the current (or last) call, by id */
    private array $lastRunWorkers = [];

    public function __construct(
        public readonly PoolOptions $options,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->events = new Events;
        $this->pid = getmypid();

        $pool = WeakReference::create($this);
        register_shutdown_function(static fn () => $pool->get()?->shutdown());
    }

    /**
     * Run every payload and return one outcome per payload, in payload key order.
     *
     * `$concurrent` replaces `max_workers` for this call only: workers started
     * beyond `max_workers` are temporary and closed when the call ends.
     * A task still running `$timeout` + 0.25 s after its dispatch times out and its worker is abandoned.
     *
     * @param  array<array-key, string>  $payloads
     * @return array<array-key, Outcome>
     */
    public function run(array $payloads, ?float $timeout = null, ?int $concurrent = null): array
    {
        $this->closeAbandonedWorkers();

        $this->call++;
        $this->queue = $payloads;
        $this->outcomes = [];
        $this->timeout = $timeout;
        $this->timeLimit = $timeout === null ? null : max(1, (int) ceil($timeout));
        $this->bootFailure = null;
        $this->limit = $concurrent === null ? $this->options->maxWorkers : min($concurrent, count($payloads));
        $this->lastRunWorkers = [];

        try {
            while (count($this->outcomes) < count($payloads)) {
                $this->dispatch();

                if (count($this->outcomes) < count($payloads)) {
                    $this->wait();
                }
            }
            // Every payload key has an outcome; array_replace keeps the payloads' key order.
            return array_replace($payloads, $this->outcomes);
        } finally {
            $this->queue = [];
            $this->outcomes = [];
            $this->abandonBusyWorkers();
            $this->closeTemporaryWorkers();
        }
    }

    /**
     * Start workers until `$workers` are live (at most `max_workers`) and wait for them to boot.
     */
    public function warm(int $workers): void
    {
        $this->closeAbandonedWorkers();
        $this->bootFailure = null;

        while (count($this->workers) < min($workers, $this->options->maxWorkers)) {
            $this->start();
        }

        while ($this->boots !== []) {
            $this->wait();
        }

        if ($this->bootFailure !== null) {
            throw new RuntimeException(self::BOOT_FAILURE.$this->bootFailure);
        }
    }

    /**
     * @return array<int, Worker>
     */
    public function workers(): array
    {
        return $this->workers;
    }

    /**
     * Every worker that ran a task in the last `run()`, temporary ones included (even once closed).
     *
     * @internal
     *
     * @return array<int, Worker>
     */
    public function lastRunWorkers(): array
    {
        return $this->lastRunWorkers;
    }

    /**
     * @return array{started: int, live: int, abandoned: int}
     */
    public function stats(): array
    {
        return ['started' => $this->started, 'live' => count($this->workers), 'abandoned' => count($this->abandoned)];
    }

    /**
     * Close every idle worker, and every abandoned one once its task ends:
     * this waits for them, as PHP joins every thread before the process exits anyway.
     */
    public function shutdown(): void
    {
        if (getmypid() !== $this->pid) {
            return;
        }

        $this->abandonBusyWorkers();

        foreach ($this->workers as $worker) {
            $this->close($worker);
        }

        $this->closeAbandonedWorkers(waitForAll: true);
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    /**
     * Hand queued payloads to idle workers while fewer than the limit are in
     * flight, then to new ones while fewer than the limit are live.
     */
    private function dispatch(): void
    {
        foreach ($this->workers as $worker) {
            if ($this->queue === [] || $this->inFlight() >= $this->limit) {
                return;
            }

            if ($worker->key === null) {
                $this->assign($worker);
            }
        }

        // Every new worker gets its task at once, queued behind its boot.
        while ($this->queue !== [] && $this->bootFailure === null && count($this->workers) < $this->limit) {
            $this->assign($this->start());
        }

        // Nothing is left to run what is queued: fail it with the boot error instead of waiting forever.
        if ($this->queue !== [] && $this->bootFailure !== null && $this->workers === []) {
            foreach ($this->queue as $key => $payload) {
                $this->outcomes[$key] = Outcome::crash(self::BOOT_FAILURE.$this->bootFailure);
            }

            $this->queue = [];
        }
    }

    /**
     * Number of the current call's tasks that are running.
     */
    private function inFlight(): int
    {
        return count(array_filter($this->workers, fn (Worker $worker) => $worker->key !== null && $worker->call === $this->call));
    }

    /**
     * Start a worker and queue its boot. Tasks may be queued behind the boot right away.
     */
    private function start(): Worker
    {
        $this->warnIfOpcacheIsOff();

        $worker = new Worker(++$this->started, new Runtime($this->options->autoload));
        $worker->temporary = count($this->workers) >= $this->options->maxWorkers;

        $boot = $worker->runtime->run(
            static fn (string $appFile): array => Bootstrap::boot($appFile),
            [$this->options->appFile],
        );

        $this->boots[$worker->id] = $boot;
        $this->watch($worker, $boot, true);

        return $this->workers[$worker->id] = $worker;
    }

    private function assign(Worker $worker): void
    {
        $key = array_key_first($this->queue);
        $payload = $this->queue[$key];
        unset($this->queue[$key]);

        // If parallel rejects the task, the worker stays idle.
        $future = $worker->runtime->run(
            static fn (string $payload, ?int $timeLimit): string => TaskRunner::handle($payload, $timeLimit),
            [$payload, $this->timeLimit],
        );

        $worker->key = $key;
        $worker->call = $this->call;
        $worker->future = $future;
        $worker->dispatchedAt = microtime(true);
        $worker->firstDispatchedAt ??= $worker->dispatchedAt;
        $worker->deadline = $this->timeout === null ? null : $worker->dispatchedAt + $this->timeout + self::BACKSTOP_GRACE;
        $this->lastRunWorkers[$worker->id] = $worker;

        $this->watch($worker, $worker->future, false);
    }

    private function watch(Worker $worker, Future $future, bool $boot): void
    {
        $name = ($boot ? 'boot.' : 'task.').$worker->id.'.'.(++$this->targetSequence);

        $this->targets[$name] = [$worker->id, $boot];
        $this->events->addFuture($name, $future);
    }

    /**
     * Block until a boot or task finishes, or the nearest deadline (or the idle
     * tick) passes. Then time out overdue tasks and close abandoned workers that are done.
     */
    private function wait(): void
    {
        $this->events->setTimeout($this->microsecondsToNextDeadline());

        try {
            $this->handle($this->events->poll() ?? throw new LogicException('The thread pool is waiting but no boot or task is running.'));
        } catch (Timeout) {
            //
        }

        $this->abandonOverdueWorkers();
        $this->closeAbandonedWorkers();
    }

    private function handle(Event $event): void
    {
        [$id, $boot] = $this->targets[$event->source];
        unset($this->targets[$event->source]);

        $worker = $this->workers[$id];

        $boot ? $this->settleBoot($worker) : $this->finished($worker, $event);
    }

    /**
     * A task still running past its deadline (timeout + grace) is not waited
     * for: it times out and its worker is abandoned. It is never cancelled or
     * killed, as both block until a running syscall returns.
     */
    private function abandonOverdueWorkers(): void
    {
        $now = microtime(true);

        foreach ($this->workers as $worker) {
            // A task that is done has an event to poll: its own outcome wins.
            if ($worker->deadline === null || $worker->deadline > $now || $worker->future->done()) {
                continue;
            }

            $key = $worker->key;

            $this->abandon($worker);
            $this->record($worker, Outcome::timeout());

            $this->logger?->warning(sprintf(self::ABANDONED_WARNING, $key, TaskTimedOutException::formatSeconds((float) $this->timeout)));
        }
    }

    /**
     * Stop tracking a busy worker: it no longer counts as live, its events are
     * not polled and a failed boot of it fails no call. It is closed once its task ends.
     */
    private function abandon(Worker $worker): void
    {
        $this->abandoned[$worker->id] = [$worker, $worker->future];

        $this->forgetTargets($worker);
        unset($this->workers[$worker->id], $this->boots[$worker->id]);
    }

    /**
     * Workers still busy when a call ends are running tasks of a call that
     * ended with an exception: nothing waits for those tasks any more.
     */
    private function abandonBusyWorkers(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->key !== null) {
                $this->abandon($worker);
            }
        }
    }

    /**
     * Close the abandoned workers whose task has ended (with `$waitForAll`,
     * all of them, waiting for each task to end).
     *
     * The task may have ended in a fatal error (the worker's time limit, say,
     * if it fired after the backstop): the post-mortem clears it, so the
     * thread's shutdown handler finds nothing to print into the parent's terminal when it closes.
     */
    private function closeAbandonedWorkers(bool $waitForAll = false): void
    {
        foreach ($this->abandoned as $id => [$worker, $future]) {
            if (! $waitForAll && ! $future->done()) {
                continue;
            }

            unset($this->abandoned[$id]);

            try {
                $worker->postMortem();   // queued behind the task, so with `$waitForAll` this waits for it
            } catch (Throwable) {
                //
            }

            $worker->runtime->close();
        }
    }

    private function microsecondsToNextDeadline(): int
    {
        $deadlines = array_filter(array_map(fn (Worker $worker) => $worker->deadline, $this->workers));
        $seconds = $deadlines === [] ? self::IDLE_TICK : min($deadlines) - microtime(true);

        return max(1000, (int) ($seconds * 1e6));
    }

    /**
     * Record the result of a worker's boot. A task can finish before its boot's
     * event is polled, so this also runs when a task of a booting worker ends.
     */
    private function settleBoot(Worker $worker): void
    {
        $boot = $this->boots[$worker->id] ?? null;

        if ($boot === null) {
            return;
        }

        unset($this->boots[$worker->id]);
        $this->forgetTargets($worker, bootOnly: true);

        try {
            $worker->boot = $boot->value();
            $worker->ready = true;
            $worker->readyAt = microtime(true);
        } catch (Throwable $e) {
            $this->bootFailure = $e instanceof Killed ? 'the thread was killed' : $e->getMessage();

            $this->record($worker, Outcome::crash(self::BOOT_FAILURE.$this->bootFailure));
            $this->close($worker);
        }
    }

    private function finished(Worker $worker, Event $event): void
    {
        $this->settleBoot($worker);

        if (! isset($this->workers[$worker->id])) {
            return; // the boot failed and the task has its outcome already
        }

        // A fatal error or exit() ended the task: the booted app's state is not trusted afterwards.
        if ($event->type !== Type::Read) {
            $this->record($worker, $this->postMortemOutcome($worker));
            $this->close($worker);

            return;
        }

        $worker->tasks++;
        $this->record($worker, Outcome::result($event->value));
        $worker->memory = self::reportedMemory($event->value);

        if ($this->wornOut($worker)) {
            $this->close($worker);
        }
    }

    /**
     * Why the worker's task was killed: a time limit, another fatal error, or exit().
     */
    private function postMortemOutcome(Worker $worker): Outcome
    {
        try {
            $error = $worker->postMortem();
        } catch (Throwable) {
            $error = null;
        }

        // Only a fatal error ends a task: anything else is an older (e.g. @-silenced) error, not the cause.
        if ($error === null || ($error['type'] & self::FATAL_ERRORS) === 0) {
            return Outcome::crash('the task called exit() or the thread was killed');
        }

        return str_contains($error['message'], 'Maximum execution time')
            ? Outcome::timeout()
            : Outcome::crash($error['message']);
    }

    /**
     * The heap size a result payload reports. Read without instantiating any
     * class, so a result the parent cannot unserialize fails in `ThreadRunner`, not here.
     */
    private static function reportedMemory(string $result): ?int
    {
        $memory = unserialize($result, ['allowed_classes' => false])['memory'] ?? null;

        return is_int($memory) ? $memory : null;
    }

    /**
     * Whether the worker has run `max_tasks` tasks or its heap has outgrown `max_memory`.
     */
    private function wornOut(Worker $worker): bool
    {
        return ($this->options->maxTasks !== null && $worker->tasks >= $this->options->maxTasks)
            || ($this->options->maxMemoryMb !== null && $worker->memory !== null && $worker->memory > $this->options->maxMemoryMb * 1024 * 1024);
    }

    /**
     * Record the outcome of the worker's task and make it idle. Only the
     * current call's tasks have outcomes: a worker still busy when its call
     * ends is abandoned, so no later call sees an earlier call's result.
     */
    private function record(Worker $worker, Outcome $outcome): void
    {
        if ($worker->key !== null && $worker->call === $this->call) {
            $this->outcomes[$worker->key] = $outcome;
        }

        $worker->key = null;
        $worker->call = null;
        $worker->future = null;
        $worker->dispatchedAt = null;
        $worker->deadline = null;
    }

    /**
     * Temporary workers never outlive their call. Busy ones are abandoned by
     * then, so every one left is idle.
     */
    private function closeTemporaryWorkers(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->temporary) {
                $this->close($worker);
            }
        }
    }

    /**
     * Only called on workers with nothing slow left to run: idle ones, or ones
     * whose boot failed (a task queued behind it fails at once), so `close()` is prompt.
     */
    private function close(Worker $worker): void
    {
        $this->forgetTargets($worker);
        unset($this->workers[$worker->id], $this->boots[$worker->id]);

        $worker->runtime->close();
    }

    private function forgetTargets(Worker $worker, bool $bootOnly = false): void
    {
        foreach ($this->targets as $name => [$id, $boot]) {
            if ($id === $worker->id && ($boot || ! $bootOnly)) {
                $this->events->remove($name);
                unset($this->targets[$name]);
            }
        }
    }

    private function warnIfOpcacheIsOff(): void
    {
        if (self::$warnedAboutOpcache || (extension_loaded('Zend OPcache') && ini_get('opcache.enable_cli'))) {
            return;
        }

        self::$warnedAboutOpcache = true;

        $this->logger?->warning(self::OPCACHE_WARNING);
    }
}
