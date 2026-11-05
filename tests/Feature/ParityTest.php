<?php

namespace Omaresmael\Thread\Tests\Feature;

use ArrayObject;
use Closure;
use Illuminate\Support\Facades\Concurrency;
use LogicException;
use Omaresmael\Thread\Facades\Thread;
use Omaresmael\Thread\Tests\Feature\Concerns\RunsProcessDriverChild;
use Omaresmael\Thread\Tests\Fixtures\Exceptions\OrderFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * The same task sets, run through Laravel's process driver and through
 * Thread::run(), must give the same results or the same failure.
 */
class ParityTest extends FeatureTestCase
{
    use RunsProcessDriverChild;

    /**
     * Each task closure sits on its own line (SerializableClosure); the outer
     * closure only builds the list.
     */
    public static function cases(): array
    {
        return [
            'list' => [static fn () => [
                fn () => 1,
                fn () => 'two',
                fn () => [3],
            ]],
            'assoc + int keys' => [static fn () => [
                'a' => fn () => 1,
                5 => fn () => 2,
            ]],
            'objects' => [static fn () => [
                fn () => new ArrayObject([1, 2]),
                fn () => now()->startOfDay(),
            ]],
            'custom exception' => [static fn () => [
                fn () => throw new OrderFailed('P', 9),
            ]],
            'message-only exception' => [static fn () => [
                fn () => throw new LogicException('plain'),
            ]],
            'second task fails' => [static fn () => [
                'x' => fn () => 1,
                'y' => fn () => throw new RuntimeException('y failed'),
            ]],
        ];
    }

    #[DataProvider('cases')]
    public function test_matches_process_driver(Closure $make): void
    {
        $this->assertEquals(
            $this->capture(fn () => Concurrency::driver('process')->run($make())),
            $this->capture(fn () => Thread::run($make())),
        );
    }

    /**
     * What a run produced: its result, or the failure it threw. A failure is
     * compared by class, message and public properties (what a caller can
     * read, such as OrderFailed::$orderId). Left out because they legitimately
     * differ by where the exception was built: file, line, trace, previous
     * (the process driver rebuilds the exception in the parent, so it has none
     * either way), and the protected/private internals.
     *
     * @return array{value: mixed}|array{exception: array{string, string, array<string, mixed>}}
     */
    private function capture(Closure $run): array
    {
        try {
            return ['value' => $run()];
        } catch (Throwable $e) {
            return ['exception' => [$e::class, $e->getMessage(), get_object_vars($e)]];
        }
    }
}
