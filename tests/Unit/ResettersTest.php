<?php

namespace Omaresmael\Thread\Tests\Unit;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Monolog\Handler\TestHandler;
use Omaresmael\Thread\Payload;
use Omaresmael\Thread\Tests\TestCase;
use Omaresmael\Thread\Worker\Bootstrap;
use Omaresmael\Thread\Worker\Resetters\Resetter;
use Omaresmael\Thread\Worker\TaskRunner;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Throwable;

class ResettersTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('logging.channels.probe', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Resolved on the base, so tasks share the manager (and its single in-memory connection).
        Schema::create('t', fn ($table) => $table->integer('v'));
        $this->app->make('auth');
        $this->app->make('translator');

        Bootstrap::adopt($this->app);
    }

    protected function tearDown(): void
    {
        Carbon::setLocale('en');   // process-wide: tests must not inherit each other's locale

        parent::tearDown();
    }

    private function task(Closure $task): mixed
    {
        return Payload::value(Payload::decode(TaskRunner::handle(Payload::task($task))));
    }

    public function test_resetters_run_in_the_documented_order(): void
    {
        $this->assertSame(
            [
                'RollbackTransactions', 'ForgetScopedInstances', 'ForgetAuthGuards', 'FlushQueryLog', 'RestoreLocale',
                'FlushArrayCache', 'FlushOnce', 'FlushLogContext', 'FlushMonologState', 'FlushDatabaseRecordModificationState',
                'RefreshQueryDurationHandling',
            ],
            array_map(fn (string $class) => class_basename($class), TaskRunner::RESETTERS),
        );
    }

    public function test_open_transactions_are_rolled_back(): void
    {
        $this->task(function () { DB::beginTransaction(); DB::beginTransaction(); DB::table('t')->insert(['v' => 1]); });

        $this->assertSame([0, 0], $this->task(fn () => [DB::transactionLevel(), DB::table('t')->count()]));
        $this->assertSame(0, $this->app['db']->connection()->transactionLevel());
    }

    public function test_committed_work_is_kept(): void
    {
        $this->task(fn () => DB::table('t')->insert(['v' => 1]));

        $this->assertSame(1, $this->task(fn () => DB::table('t')->count()));
    }

    public function test_scoped_instances_are_forgotten(): void
    {
        $this->app->scoped('counter', fn () => new stdClass);
        $this->app->make('counter')->n = 5;

        // A task reaching the base through a service that captured it.
        $this->task(function () { Bootstrap::base()->make('counter')->n = 9; });

        $this->assertSame([false, 0], $this->task(fn () => [isset(Bootstrap::base()->make('counter')->n), count((array) app('counter'))]));
        $this->assertFalse(isset($this->app->make('counter')->n));
    }

    public function test_context_is_flushed_between_tasks(): void
    {
        $this->task(function () { Context::add('x', 1); });

        $this->assertFalse($this->task(fn () => Context::has('x')));
    }

    public function test_context_the_base_holds_is_flushed(): void
    {
        $this->app->make(\Illuminate\Log\Context\Repository::class)->add('stale', 1);

        $this->task(fn () => 1);

        $this->assertFalse(Context::has('stale'));
    }

    public function test_auth_guards_are_forgotten(): void
    {
        $this->task(function () { Auth::setUser(new GenericUser(['id' => 1])); });

        $this->assertFalse($this->task(fn () => Auth::hasUser()));
        $this->assertFalse($this->app['auth']->hasResolvedGuards());
    }

    /**
     * The auth manager was resolved at boot: during a task it writes the default
     * guard into the sandbox's config, not the base's that every task is cloned from.
     */
    public function test_the_default_guard_set_by_a_task_does_not_carry_over(): void
    {
        $this->app['config']->set('auth.guards.other', ['driver' => 'session', 'provider' => 'users']);

        $this->assertSame('other', $this->task(function () { Auth::shouldUse('other'); return Auth::getDefaultDriver(); }));

        $this->assertSame('web', $this->task(fn () => Auth::getDefaultDriver()));
        $this->assertSame('web', $this->app['config']->get('auth.defaults.guard'));
    }

    public function test_the_default_log_channel_set_by_a_task_does_not_carry_over(): void
    {
        $this->app->make('log');
        $default = $this->app['config']->get('logging.default');

        $this->task(fn () => Log::setDefaultDriver('null'));

        $this->assertSame($default, $this->task(fn () => Log::getDefaultDriver()));
        $this->assertSame($default, $this->app['config']->get('logging.default'));
    }

    public function test_query_log_is_flushed_and_disabled(): void
    {
        $this->task(function () { DB::enableQueryLog(); DB::select('select 1'); });

        $this->assertSame([false, 0], $this->task(fn () => [DB::logging(), count(DB::getQueryLog())]));
    }

    public function test_locale_is_restored(): void
    {
        $this->task(fn () => app()->setLocale('fr'));

        $this->assertSame(['en', 'en'], $this->task(fn () => [app()->getLocale(), app('translator')->getLocale()]));
        $this->assertSame('en', $this->app['translator']->getLocale());
    }

    public function test_the_base_locale_is_recorded_when_the_app_is_adopted(): void
    {
        $this->app->setLocale('de');
        Bootstrap::adopt($this->app);

        $this->assertSame('de', Bootstrap::baseLocale());
    }

    public function test_the_auth_driver_shortcut_is_forgotten(): void
    {
        $guard = $this->app->make('auth.driver');

        $this->task(function () { app('auth.driver')->setUser(new GenericUser(['id' => 1])); });

        $this->assertFalse($this->task(fn () => app('auth.driver')->hasUser()));
        $this->assertNotSame($guard, $this->app->make('auth.driver'));   // resolved() stays true after forgetInstance()
    }

    public function test_the_fallback_locale_is_restored(): void
    {
        $this->task(fn () => app()->setFallbackLocale('de'));

        $this->assertSame(['en', 'en'], $this->task(fn () => [app('translator')->getFallback(), app()->getFallbackLocale()]));
        $this->assertSame('en', $this->app['translator']->getFallback());
    }

    public function test_the_carbon_locale_is_restored(): void
    {
        $this->task(function () { Carbon::setLocale('fr'); CarbonImmutable::setLocale('fr'); });

        $this->assertSame(['en', 'en'], $this->task(fn () => [Carbon::getLocale(), CarbonImmutable::getLocale()]));
    }

    public function test_the_base_fallback_and_carbon_locales_are_recorded_when_the_app_is_adopted(): void
    {
        $this->app->setFallbackLocale('de');
        Carbon::setLocale('fr');
        Bootstrap::adopt($this->app);

        $this->assertSame('de', Bootstrap::baseFallbackLocale());
        $this->assertSame('fr', Bootstrap::baseCarbonLocale());
        $this->assertSame('fr', $this->task(fn () => Carbon::getLocale()));
    }

    public function test_the_array_cache_store_is_flushed(): void
    {
        $this->app->make('cache');

        $this->task(function () { Cache::store('array')->put('k', 1); });

        $this->assertFalse($this->task(fn () => Cache::store('array')->has('k')));
    }

    public function test_the_array_cache_store_is_left_alone_when_it_is_not_configured(): void
    {
        $this->app->make('cache');
        $this->app['config']->set('cache.stores.array', null);
        $this->app['config']->set('cache.stores.other', ['driver' => 'array']);
        $this->app->make('cache')->store('other')->put('k', 1);
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e->getMessage();
        });

        $this->task(fn () => 1);

        $this->assertSame([], $reported);   // an undefined store would throw, and resetter failures are reported
        $this->assertTrue($this->app->make('cache')->store('other')->has('k'));
    }

    public function test_once_memoized_values_are_forgotten(): void
    {
        $this->app->instance('probe.once', new OnceProbe);

        $this->assertSame([1, 1], $this->task(fn () => [app('probe.once')->value(), app('probe.once')->value()]));

        $this->assertSame(2, $this->task(fn () => app('probe.once')->value()));
    }

    public function test_shared_and_channel_log_context_is_flushed(): void
    {
        $this->app->make('log');

        $this->task(function () { Log::shareContext(['shared' => 1]); Log::channel('probe')->withContext(['channel' => 1]); });

        $context = $this->task(function () { Log::channel('probe')->info('hello'); return Log::channel('probe')->getLogger()->getHandlers()[0]->getRecords()[0]->context; });
        $this->assertSame([], $context);
        $this->assertSame([], $this->app['log']->sharedContext());
    }

    public function test_monolog_handlers_are_reset(): void
    {
        $this->app->make('log');

        $this->assertSame(1, $this->task(function () { Log::channel('probe')->info('hello'); return count(Log::channel('probe')->getLogger()->getHandlers()[0]->getRecords()); }));

        $this->assertSame(0, $this->task(fn () => count(Log::channel('probe')->getLogger()->getHandlers()[0]->getRecords())));
    }

    public function test_the_record_modification_state_is_forgotten(): void
    {
        $this->assertTrue($this->task(function () { DB::table('t')->insert(['v' => 1]); return DB::connection()->hasModifiedRecords(); }));

        $this->assertFalse($this->task(fn () => DB::connection()->hasModifiedRecords()));
    }

    public function test_query_duration_totals_and_handlers_are_refreshed(): void
    {
        $runs = 0;
        $this->app['db']->connection()->whenQueryingForLongerThan(-1, function () use (&$runs) { $runs++; });

        $this->task(function () { DB::select('select 1'); DB::select('select 1'); });
        $this->assertSame(1, $runs);

        $this->assertSame(0.0, $this->task(fn () => DB::connection()->totalQueryDuration()));
        $this->task(fn () => DB::select('select 1'));
        $this->assertSame(2, $runs);
    }

    public function test_url_generator_changes_made_by_a_task_do_not_carry_over(): void
    {
        $this->app->make('url');
        $before = $this->app['url']->to('/x');

        $this->assertSame('https://forced.test/x', $this->task(function () { URL::forceRootUrl('https://forced.test'); URL::forceScheme('https'); return url('/x'); }));

        $this->assertSame($before, $this->task(fn () => url('/x')));
        $this->assertSame($before, $this->app['url']->to('/x'));
    }

    public function test_the_url_generator_is_not_resolved_for_a_task_that_does_not_need_it(): void
    {
        $this->assertFalse($this->app->resolved('url'));

        $this->task(fn () => 1);

        $this->assertFalse($this->app->resolved('url'));
    }

    public function test_resetters_ignore_services_that_were_never_resolved(): void
    {
        $bare = new Application;

        foreach (TaskRunner::RESETTERS as $class) {
            $this->assertInstanceOf(Resetter::class, $resetter = new $class);
            $resetter($bare, $bare);
        }

        $this->assertFalse($bare->resolved('db'));
        $this->assertFalse($bare->resolved('auth'));
        $this->assertFalse($bare->resolved('translator'));
        $this->assertFalse($bare->resolved('log'));
        $this->assertFalse($bare->resolved('cache'));
    }

    public function test_a_failing_resetter_does_not_stop_the_others_or_the_task_result(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e->getMessage();
        });
        $this->app->instance('db', new FakeDatabase($this->app, throwOn: 'getConnections'));

        $this->assertSame('ok', $this->task(function () { Context::add('x', 1); return 'ok'; }));

        $this->assertFalse($this->task(fn () => Context::has('x')));
        $this->assertContains('fake getConnections failure', $reported);
        $this->assertSame($this->app, Container::getInstance());
    }

    public function test_a_failure_while_entering_the_sandbox_restores_the_base(): void
    {
        $this->app->make('cache');
        $this->app->instance('db', new FakeDatabase($this->app, throwOn: 'enter'));

        $out = Payload::decode(TaskRunner::handle(Payload::task(fn () => 'never runs')));

        $this->assertFalse($out['successful']);
        $this->assertSame('fake enter failure', $out['message']);
        $this->assertBaseRestored();
    }

    public function test_a_failure_while_leaving_the_sandbox_still_restores_the_base(): void
    {
        $this->app->make('cache');
        $this->app->make('view');
        $this->app->instance('db', new FakeDatabase($this->app, throwOn: 'leave'));

        $this->assertSame('ok', $this->task(fn () => 'ok'));

        $this->assertBaseRestored();
        $this->assertSame($this->app, $this->app->make('view')->getContainer());
    }

    public function test_memory_reported_after_a_failing_task_does_not_include_what_the_task_held(): void
    {
        // The test environment's exception log would keep the exception, and what it references, alive.
        $this->app->make(ExceptionHandler::class)->ignore(RuntimeException::class);
        $big = str_repeat('x', 8_000_000);
        $payload = Payload::task(function () use ($big) { throw new RuntimeException(strlen($big).' bytes'); });
        $before = memory_get_usage();

        $out = Payload::decode(TaskRunner::handle($payload));

        $this->assertFalse($out['successful']);
        $this->assertLessThan($before + 2_000_000, $out['memory']);
    }

    private function assertBaseRestored(): void
    {
        $this->assertSame($this->app, Container::getInstance());
        $this->assertSame($this->app, Facade::getFacadeApplication());
        $this->assertSame($this->app, (new ReflectionProperty($this->app->make('cache'), 'app'))->getValue($this->app->make('cache')));
    }
}

/**
 * Stands in for the DB manager: fails when handed the sandbox (`enter`) or
 * the base (`leave`), or when asked for its connections.
 */
class FakeDatabase
{
    public function __construct(private Application $base, private string $throwOn)
    {
    }

    public function setApplication(Application $app): static
    {
        if ($this->throwOn === 'enter' && $app !== $this->base) {
            throw new RuntimeException('fake enter failure');
        }

        if ($this->throwOn === 'leave' && $app === $this->base) {
            throw new RuntimeException('fake leave failure');
        }

        return $this;
    }

    public function getConnections(): array
    {
        throw new RuntimeException('fake getConnections failure');
    }
}


/**
 * Memoizes with `once()`, which is keyed by the object it is called on.
 */
class OnceProbe
{
    private int $calls = 0;

    public function value(): int
    {
        return once(fn () => ++$this->calls);
    }
}
