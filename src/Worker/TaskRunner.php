<?php

namespace Omaresmael\Thread\Worker;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Context;
use Laravel\SerializableClosure\SerializableClosure;
use Omaresmael\Thread\Payload;
use Omaresmael\Thread\Worker\Resetters\FlushArrayCache;
use Omaresmael\Thread\Worker\Resetters\FlushDatabaseRecordModificationState;
use Omaresmael\Thread\Worker\Resetters\FlushLogContext;
use Omaresmael\Thread\Worker\Resetters\FlushMonologState;
use Omaresmael\Thread\Worker\Resetters\FlushOnce;
use Omaresmael\Thread\Worker\Resetters\FlushQueryLog;
use Omaresmael\Thread\Worker\Resetters\ForgetAuthGuards;
use Omaresmael\Thread\Worker\Resetters\ForgetScopedInstances;
use Omaresmael\Thread\Worker\Resetters\RefreshQueryDurationHandling;
use Omaresmael\Thread\Worker\Resetters\Resetter;
use Omaresmael\Thread\Worker\Resetters\RestoreLocale;
use Omaresmael\Thread\Worker\Resetters\RollbackTransactions;
use RuntimeException;
use Throwable;

/**
 * Runs one task payload inside a sandbox and always returns a result payload.
 */
final class TaskRunner
{
    /**
     * The resetters, in the order they run after every task.
     *
     * @var list<class-string<Resetter>>
     */
    public const RESETTERS = [
        RollbackTransactions::class,
        ForgetScopedInstances::class,
        ForgetAuthGuards::class,
        FlushQueryLog::class,
        RestoreLocale::class,
        FlushArrayCache::class,
        FlushOnce::class,
        FlushLogContext::class,
        FlushMonologState::class,
        FlushDatabaseRecordModificationState::class,
        RefreshQueryDurationHandling::class,
    ];


    public static function handle(string $payload, ?int $timeLimit = null): string
    {
        $base = null;
        $sandbox = null;

        try {
            $base = Bootstrap::base();
            $sandbox = Sandbox::enter($base);

            $data = self::run($payload, $timeLimit, $sandbox);
        } catch (Throwable $e) {
            self::report($e);

            $data = Payload::failure($e);
        }

        if ($sandbox !== null) {
            self::runResetters($sandbox, $base);
        }

        set_time_limit(0);

        if ($sandbox !== null) {
            try {
                Sandbox::leave($base);
            } catch (Throwable $e) {
                self::report($e);
            }
        }

        // Nothing may still hold the sandbox (it references itself) when the
        // garbage is collected, or the memory reported to the pool is inflated.
        unset($e, $sandbox);
        gc_collect_cycles();

        return self::encode($data);
    }

    /**
     * @return array<string, mixed>
     */
    private static function run(string $payload, ?int $timeLimit, Application $sandbox): array
    {
        $obLevel = ob_get_level();

        try {
            $task = self::unserializeTask($payload);

            Context::hydrate($task['context']);

            if ($timeLimit !== null && PHP_OS_FAMILY === 'Linux') {
                set_time_limit($timeLimit);
            }

            ob_start();

            return Payload::success($sandbox->call($task['closure']));
        } catch (Throwable $e) {
            self::report($e);

            return Payload::failure($e);
        } finally {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
        }
    }

    /**
     * @return array{closure: Closure, context: mixed}
     */
    private static function unserializeTask(string $payload): array
    {
        $task = unserialize($payload);

        if (! is_array($task) || ! isset($task['closure']) || ! is_string($task['closure']) || ! array_key_exists('context', $task)) {
            throw new RuntimeException('The task payload is malformed.');
        }

        $closure = unserialize($task['closure']);

        if (! $closure instanceof SerializableClosure) {
            throw new RuntimeException('The task payload does not contain a closure.');
        }

        return ['closure' => $closure->getClosure(), 'context' => $task['context']];
    }

    /**
     * Reporting is best effort: a broken handler must not hide the task's failure.
     */
    private static function report(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Each resetter is isolated: one that fails must not stop the others.
     */
    private static function runResetters(Application $sandbox, Application $base): void
    {
        foreach (self::RESETTERS as $resetter) {
            try {
                (new $resetter)($sandbox, $base);
            } catch (Throwable $e) {
                self::report($e);
            }
        }
    }

    /**
     * A failure whose exception cannot be serialized (a Closure in a property,
     * say) is sent as a plain RuntimeException carrying the original class and message.
     *
     * @param  array<string, mixed>  $data
     */
    private static function encode(array $data): string
    {
        try {
            return Payload::encode($data, memory_get_usage());
        } catch (Throwable) {
            return Payload::encode([
                ...Payload::failure(new RuntimeException($data['exception'].': '.$data['message'])),
                'file' => $data['file'],
                'line' => $data['line'],
            ], memory_get_usage());
        }
    }
}
