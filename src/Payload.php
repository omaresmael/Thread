<?php

namespace Omaresmael\Thread;

use Closure;
use Illuminate\Support\Facades\Context;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * The wire format between parent and worker: `serialize()`d plain arrays.
 */
final class Payload
{
    /**
     * Encode a task (and the current Context) for a worker.
     */
    public static function task(Closure $task): string
    {
        return serialize([
            'closure' => serialize(new SerializableClosure($task)),
            'context' => Context::dehydrate(),
        ]);
    }

    /**
     * @return array{successful: true, result: string}
     */
    public static function success(mixed $result): array
    {
        return ['successful' => true, 'result' => serialize($result)];
    }

    /**
     * @return array{successful: false, exception: class-string<Throwable>, message: string, file: string, line: int, parameters: array<string, mixed>}
     */
    public static function failure(Throwable $e): array
    {
        $reflection = new ReflectionClass($e);
        $constructor = $reflection->getConstructor();
        $parameters = [];

        if ($constructor) {
            $declaringClass = $constructor->getDeclaringClass()->getName();

            if ($declaringClass === $reflection->getName()) {
                foreach ($constructor->getParameters() as $parameter) {
                    $parameters[$parameter->name] = $e->{$parameter->name} ?? null;
                }
            }
        }

        return [
            'successful' => false,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'parameters' => $parameters,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data, int $memory): string
    {
        return serialize([...$data, 'memory' => $memory]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $encoded): array
    {
        return unserialize($encoded);
    }

    /**
     * Return the task's result, or throw the exception it failed with
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public static function value(array $data): mixed
    {
        if ($data['successful']) {
            return unserialize($data['result']);
        }

        $class = $data['exception'];

        try {
            $exception = new $class(
                ...(! empty(array_filter($data['parameters'], fn ($parameter) => ! is_null($parameter)))
                    ? $data['parameters']
                    : [$data['message']])
            );
        } catch (Throwable) {
            throw new RuntimeException("{$class}: {$data['message']}");
        }

        throw $exception;
    }
}
