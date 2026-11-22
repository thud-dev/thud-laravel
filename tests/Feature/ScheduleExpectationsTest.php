<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Thud\Expectations\Expect;
use Thud\Hooks\ScheduleHooks;
use Thud\Schedule\ScheduleRegistry;
use Thud\Tests\Fixtures\FixtureMail;

afterEach(function (): void {
    putenv(ScheduleHooks::ENV);
    unset($_SERVER[ScheduleHooks::ENV], $_ENV[ScheduleHooks::ENV]);
});

it('evaluates a scheduled callback\'s expectations when it finishes', function (): void {
    app(Schedule::class)
        ->call(fn () => DB::table('orders')->insert(['status' => 'exported']))
        ->name('orders:export')
        ->everyMinute()
        ->expect(Expect::atLeast(1)->rowsWritten()->label('Rows exported'))
        ->expect(Expect::atLeast(1)->mailsSent(FixtureMail::class)->label('Report mailed'));

    $this->artisan('schedule:run')->assertExitCode(0);

    $expectations = collect(recordedRun('orders:export')['expectations'])->pluck('result', 'label')->all();

    expect($expectations)->toBe(['Rows exported' => 'passed', 'Report mailed' => 'failed']);
});

it('uses the task name for default labels', function (): void {
    app(Schedule::class)->call(fn () => null)->name('noop')->everyMinute()->expect(Expect::state(fn () => true));

    $this->artisan('schedule:run');

    expect(recordedRun('noop')['expectations'][0]['label'])->toBe('noop::expectations()#0');
});

it('evaluates an artisan command\'s expectations in the child process that ran it', function (): void {
    $event = app(Schedule::class)
        ->command('invoices:send')
        ->dailyAt('06:00')
        ->expect(Expect::atLeast(1)->mailsSent(FixtureMail::class)->label('Invoices mailed'));
    $events = app('events');

    $events->dispatch(new ScheduledTaskStarting($event));

    // Child process: the command runs and sends a mail.
    $events->dispatch(new CommandStarting('invoices:send', new ArrayInput([]), new NullOutput));
    Mail::to('billing@example.com')->send(new FixtureMail);
    $events->dispatch(new CommandFinished('invoices:send', new ArrayInput([]), new NullOutput, 0));

    $events->dispatch(new ScheduledTaskFinished($event, 1.0));

    expect(recordedRun('invoices:send')['expectations'])->toBe([['label' => 'Invoices mailed', 'result' => 'passed']]);
});

it('syncs every scheduled task, with ->expectRun() setting the grace period', function (): void {
    config(['app.timezone' => 'UTC']);
    $schedule = app(Schedule::class);
    $schedule->command('invoices:send')->dailyAt('06:00')->timezone('Africa/Lagos')->expectRun();
    $schedule->command('reports:weekly')->weeklyOn(1, '09:00')->expectRun(graceMinutes: 30);
    $schedule->command('cache:prune')->hourly(); // watched by default

    $this->artisan('thud:sync')->assertExitCode(0)->expectsOutputToContain('3 scheduled tasks synced');

    Http::assertSent(function ($request): bool {
        $body = json_decode((string) gzdecode($request->body()), true);
        $tasks = collect($body['tasks'])->keyBy('name');

        return $request->url() === 'https://ingest.thud.test/v1/schedule'
            && $tasks->count() === 3
            && $tasks['invoices:send']['cron'] === '0 6 * * *'
            && $tasks['invoices:send']['timezone'] === 'Africa/Lagos'
            && $tasks['invoices:send']['grace_minutes'] === 10
            && str_starts_with($tasks['invoices:send']['mutex'], 'framework')
            && $tasks['reports:weekly']['timezone'] === 'UTC'
            && $tasks['reports:weekly']['grace_minutes'] === 30
            && $tasks['cache:prune']['grace_minutes'] === 10;
    });
});

it('leaves out tasks that can skip runs on purpose, unless they use ->expectRun(), and ->dontWatch() tasks', function (): void {
    $schedule = app(Schedule::class);
    $schedule->command('always:runs')->hourly();
    $schedule->command('weekdays:only')->hourly()->when(fn () => true);
    $schedule->command('office:hours')->hourly()->between('09:00', '17:00');
    $schedule->command('conditional:but:watched')->hourly()->skip(fn () => false)->expectRun(graceMinutes: 5);

    // Sub-minute tasks arrived in Laravel 10.
    if (method_exists(Event::class, 'everyTenSeconds')) {
        $schedule->command('every:ten:seconds')->everyTenSeconds();
    }

    $schedule->command('noisy:task')->hourly()->dontWatch();

    $this->artisan('thud:sync')
        ->expectsOutputToContain('weekdays:only')
        ->assertExitCode(0);

    Http::assertSent(function ($request): bool {
        $names = collect(json_decode((string) gzdecode($request->body()), true)['tasks'])->pluck('name')->sort()->values()->all();

        return $names === ['always:runs', 'conditional:but:watched'];
    });
});

it('only syncs ->expectRun() tasks when watching every task is turned off', function (): void {
    config(['thud.watch_scheduled_tasks' => false]);
    $schedule = app(Schedule::class);
    $schedule->command('invoices:send')->daily()->expectRun();
    $schedule->command('cache:prune')->hourly();

    $this->artisan('thud:sync')->assertExitCode(0)->expectsOutputToContain('1 scheduled task synced');
});

it('shows the tasks without sending them on a dry run', function (): void {
    app(Schedule::class)->command('invoices:send')->daily()->expectRun();

    $this->artisan('thud:sync', ['--dry-run' => true])->assertExitCode(0)->expectsOutputToContain('invoices:send');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/schedule'));
});

it('keeps ->expectRun() and ->expect() working with any call order', function (): void {
    $event = app(Schedule::class)->command('late:cron')->expectRun()->dailyAt('03:00');

    expect(app(ScheduleRegistry::class)->watchedTasks()[0]['cron'])->toBe('0 3 * * *')
        ->and(app(ScheduleRegistry::class)->findByMutex($event->mutexName()))->toBe($event);
});
