<?php

declare(strict_types=1);

namespace Thud;

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\Looping;
use Illuminate\Routing\Router;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Thud\Commands\InstallCommand;
use Thud\Commands\SyncCommand;
use Thud\Commands\TestCommand;
use Thud\Expectations\Evaluator;
use Thud\Expectations\VerifyOutcome;
use Thud\Hooks\QueueHooks;
use Thud\Hooks\ScheduleHooks;
use Thud\Hooks\WebhookMiddleware;
use Thud\Reconcile\Providers\Providers;
use Thud\Reconcile\ReconcileCommand;
use Thud\Recording\Listeners\CacheListener;
use Thud\Recording\Listeners\HttpListener;
use Thud\Recording\Listeners\JobDispatchListener;
use Thud\Recording\Listeners\MailListener;
use Thud\Recording\Listeners\NotificationListener;
use Thud\Recording\Listeners\QueryListener;
use Thud\Recording\RunStack;
use Thud\Schedule\ScheduleMacros;
use Thud\Schedule\ScheduleRegistry;
use Thud\Support\Safely;
use Thud\Transport\Buffer;
use Thud\Transport\Client;
use Thud\Transport\FlushOnTerminate;
use Thud\Transport\Transport;

/**
 * Registers Thud's config, listeners, hooks and webhook middleware.
 *
 * With THUD_ENABLED=false nothing but the config, the facade root and a pass-through `thud`
 * middleware alias is registered: zero listeners, zero overhead.
 */
final class ThudServiceProvider extends PackageServiceProvider
{
    private const OCTANE_REQUEST_RECEIVED = 'Laravel\Octane\Events\RequestReceived';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('thud')
            ->hasConfigFile()
            ->hasCommands([InstallCommand::class, SyncCommand::class, TestCommand::class, ReconcileCommand::class]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Thud::class);

        // Needed even when disabled: schedules may call ->expectRun() and ->expect().
        $this->app->singleton(ScheduleRegistry::class);

        if (! $this->enabled()) {
            return;
        }

        $this->app->singleton(RunStack::class);
        $this->app->singleton(Buffer::class);

        $this->app->singleton(Client::class, fn ($app) => new Client(
            $app->make(Http::class),
            self::stringOrNull(self::config($app, 'thud.token')),
            (string) self::config($app, 'thud.endpoint'),
            self::stringOrNull(self::config($app, 'thud.environment')),
            runningTests: $app->runningUnitTests() && ! (bool) self::config($app, 'thud.send_in_tests', false),
        ));

        $this->app->alias(Client::class, Transport::class);
        $this->app->singleton(FlushOnTerminate::class);

        $this->app->singleton(QueryListener::class, fn ($app) => new QueryListener(
            $app->make(RunStack::class),
            self::stringList(self::config($app, 'thud.ignore_tables')),
        ));

        foreach ([MailListener::class, NotificationListener::class, HttpListener::class, CacheListener::class, JobDispatchListener::class, ScheduleHooks::class] as $class) {
            $this->app->singleton($class);
        }

        $this->app->singleton(Evaluator::class);

        $this->app->singleton(QueueHooks::class, fn ($app) => new QueueHooks(
            $app->make(RunStack::class),
            $app->make(Buffer::class),
            $app->make(FlushOnTerminate::class),
            $app->make(Evaluator::class),
            $app,
            // Thud's own verification jobs are never recorded as runs.
            [...self::stringList(self::config($app, 'thud.ignore_jobs')), VerifyOutcome::class],
        ));
    }

    public function packageBooted(): void
    {
        ScheduleMacros::register();

        // The alias must exist even when disabled, or routes using it would break.
        Safely::run(fn () => $this->app->make(Router::class)->aliasMiddleware('thud', WebhookMiddleware::class), key: 'boot');

        if (! $this->enabled()) {
            return;
        }

        $this->scheduleReconciliation();

        Safely::run(function (): void {
            $this->registerListeners($this->app->make(Dispatcher::class));
            $this->app->make(FlushOnTerminate::class)->register();
        }, key: 'boot');
    }

    private function registerListeners(Dispatcher $events): void
    {
        $config = $this->app->make(Repository::class);

        // Side effects: counted on whichever run is in flight.
        $this->listen($events, QueryExecuted::class, QueryListener::class);
        $this->listen($events, MessageSent::class, MailListener::class);
        MailListener::tagMailablesOn($this->app->version());
        $this->listen($events, NotificationSent::class, NotificationListener::class);
        $this->listen($events, ResponseReceived::class, HttpListener::class);
        $this->listen($events, ConnectionFailed::class, HttpListener::class);
        $this->listen($events, KeyWritten::class, CacheListener::class);
        $this->listen($events, JobQueued::class, JobDispatchListener::class);

        // Every worker loop happens between jobs, which is when a worker flushes.
        $this->listen($events, Looping::class, FlushOnTerminate::class, 'workerLooping');

        if ((bool) $config->get('thud.record.jobs')) {
            $this->listen($events, JobProcessing::class, QueueHooks::class, 'processing');
            $this->listen($events, JobProcessed::class, QueueHooks::class, 'processed');
            $this->listen($events, JobFailed::class, QueueHooks::class, 'failed');
            $this->listen($events, JobExceptionOccurred::class, QueueHooks::class, 'exceptionOccurred');
        }

        if ((bool) $config->get('thud.record.scheduled')) {
            $this->listen($events, ScheduledTaskStarting::class, ScheduleHooks::class, 'starting');
            $this->listen($events, ScheduledTaskFinished::class, ScheduleHooks::class, 'finished');
            $this->listen($events, ScheduledTaskFailed::class, ScheduleHooks::class, 'failed');
            $this->listen($events, ScheduledTaskSkipped::class, ScheduleHooks::class, 'skipped');
            $this->listen($events, CommandStarting::class, ScheduleHooks::class, 'commandStarting');
            $this->listen($events, CommandFinished::class, ScheduleHooks::class, 'commandFinished');
        }

        // Octane reuses the app between requests: start every request with a clean stack.
        if (class_exists(self::OCTANE_REQUEST_RECEIVED)) {
            $events->listen(self::OCTANE_REQUEST_RECEIVED, function (): void {
                Safely::run(fn () => $this->app->make(RunStack::class)->reset(), key: 'octane');
            });
        }
    }

    /**
     * Listens lazily (the listener is resolved on first use) and never lets a listener throw.
     *
     * @param  class-string  $event
     * @param  class-string  $listener
     */
    private function listen(Dispatcher $events, string $event, string $listener, string $method = 'handle'): void
    {
        $instance = null;

        $events->listen($event, function (object $payload) use (&$instance, $listener, $method, $event): void {
            try {
                $instance ??= $this->app->make($listener);
                $callable = [$instance, $method];

                if (is_callable($callable)) {
                    $callable($payload);
                }
            } catch (\Throwable $e) {
                Safely::log(class_basename($event), $e->getMessage());
            }
        });
    }

    /**
     * Runs thud:reconcile nightly once a payment provider is configured (config thud.reconcile_at).
     */
    private function scheduleReconciliation(): void
    {
        $at = self::config($this->app, 'thud.reconcile_at', '03:15');

        if (! is_string($at) || $at === '' || Providers::configured() === []) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($at): void {
            Safely::run(fn () => $schedule->command('thud:reconcile')->dailyAt($at)->withoutOverlapping(), key: 'schedule');
        });
    }

    private function enabled(): bool
    {
        return (bool) self::config($this->app, 'thud.enabled', true);
    }

    private static function config(Application $app, string $key, mixed $default = null): mixed
    {
        return $app->make(Repository::class)->get($key, $default);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
