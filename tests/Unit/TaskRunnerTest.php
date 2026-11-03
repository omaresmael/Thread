<?php

namespace Omaresmael\Thread\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Facade;
use Omaresmael\Thread\Payload;
use Omaresmael\Thread\Tests\Fixtures\Exceptions\ClosureHolder;
use Omaresmael\Thread\Tests\Fixtures\Exceptions\OrderFailed;
use Omaresmael\Thread\Tests\TestCase;
use Omaresmael\Thread\Worker\Bootstrap;
use Omaresmael\Thread\Worker\TaskRunner;
use RuntimeException;
use Throwable;

use function Illuminate\Support\defer;

class TaskRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Bootstrap::adopt($this->app);
    }

    public function test_runs_closure_with_dependency_injection(): void
    {
        $out = Payload::decode(TaskRunner::handle(Payload::task(fn (Repository $config) => $config->get('app.key'))));

        $this->assertSame(TestCase::APP_KEY, Payload::value($out));
        $this->assertIsInt($out['memory']);
    }

    public function test_sandbox_isolates_bindings_config_and_facades(): void
    {
        TaskRunner::handle(Payload::task(fn () => [app()->instance('leak', 1), config(['thread.leak' => 1])]));
        $out = TaskRunner::handle(Payload::task(fn () => [app()->bound('leak'), config('thread.leak')]));

        $this->assertSame([false, null], Payload::value(Payload::decode($out)));
        $this->assertFalse($this->app->bound('leak'));
        $this->assertNull($this->app['config']->get('thread.leak'));
        $this->assertSame($this->app, Container::getInstance());
        $this->assertSame($this->app, Facade::getFacadeApplication());
    }

    public function test_task_sees_the_sandbox_as_its_app(): void
    {
        $out = TaskRunner::handle(Payload::task(fn (Repository $config) => [
            app()->has('thread.worker'),
            $config === app('config'),
            $config === config(),
        ]));

        $this->assertSame([false, true, true], Payload::value(Payload::decode($out)));
    }

    public function test_resolved_services_are_given_the_sandbox_for_the_task_and_the_base_afterwards(): void
    {
        $this->app->make('view');

        $out = TaskRunner::handle(Payload::task(fn () => app('view')->getContainer() === app() && app()->make('view')->getContainer() !== null));

        $this->assertTrue(Payload::value(Payload::decode($out)));
        $this->assertSame($this->app, $this->app->make('view')->getContainer());
    }

    public function test_exceptions_become_failure_results_and_are_reported(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e::class;
        });

        $out = Payload::decode(TaskRunner::handle(Payload::task(fn () => throw new OrderFailed('A-1', 1))));

        $this->assertFalse($out['successful']);
        $this->assertSame(OrderFailed::class, $out['exception']);
        $this->assertSame([OrderFailed::class], $reported);
    }

    public function test_non_serializable_result_becomes_failure_result(): void
    {
        $out = Payload::decode(TaskRunner::handle(Payload::task(function () {
            return fn () => 1;
        })));

        $this->assertFalse($out['successful']);
        $this->assertStringContainsString("Serialization of 'Closure' is not allowed", $out['message']);
    }

    public function test_failure_that_cannot_be_serialized_falls_back_to_a_plain_runtime_exception(): void
    {
        $out = Payload::decode(TaskRunner::handle(Payload::task(fn () => throw new ClosureHolder(fn () => 1))));

        $this->assertFalse($out['successful']);
        $this->assertSame(RuntimeException::class, $out['exception']);
        $this->assertSame(ClosureHolder::class.': holds a closure', $out['message']);
        $this->assertStringContainsString('ClosureHolder', $out['file']); // where the original was thrown

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(ClosureHolder::class.': holds a closure');

        Payload::value($out);
    }

    public function test_malformed_payloads_become_failure_results(): void
    {
        foreach (['not a payload', serialize('string'), serialize(['closure' => 'nope', 'context' => []]), serialize(['closure' => serialize('x'), 'context' => []])] as $payload) {
            $out = Payload::decode(TaskRunner::handle($payload));

            $this->assertFalse($out['successful']);
        }

        $this->assertSame($this->app, Container::getInstance());
    }

    public function test_failure_results_leave_the_base_app_restored(): void
    {
        TaskRunner::handle(Payload::task(fn () => throw new RuntimeException('boom')));

        $this->assertSame($this->app, Container::getInstance());
        $this->assertSame($this->app, Facade::getFacadeApplication());
    }

    public function test_buffered_output_is_discarded(): void
    {
        ob_start();
        TaskRunner::handle(Payload::task(function () { echo 'noise'; return 1; }));

        $this->assertSame('', ob_get_clean());
    }

    public function test_buffers_left_open_by_a_failing_task_are_discarded(): void
    {
        $level = ob_get_level();
        TaskRunner::handle(Payload::task(function () { ob_start(); echo 'noise'; throw new RuntimeException('boom'); }));

        $this->assertSame($level, ob_get_level());
    }

    public function test_context_is_hydrated_into_the_task(): void
    {
        Context::add('trace', 'abc');
        $payload = Payload::task(fn () => Context::get('trace'));
        Context::flush();

        $this->assertSame('abc', Payload::value(Payload::decode(TaskRunner::handle($payload))));
    }

    public function test_context_added_by_a_task_does_not_leak_into_the_base(): void
    {
        TaskRunner::handle(Payload::task(fn () => Context::add('leak', 1)));

        $this->assertFalse(Context::has('leak'));
        $this->assertNull(Payload::value(Payload::decode(TaskRunner::handle(Payload::task(fn () => Context::get('leak'))))));
    }

    public function test_a_time_limit_is_accepted_and_cleared_afterwards(): void
    {
        $out = Payload::decode(TaskRunner::handle(Payload::task(fn () => 'ok'), 5));

        $this->assertSame('ok', Payload::value($out));
        $this->assertSame('0', ini_get('max_execution_time'));
    }
}
