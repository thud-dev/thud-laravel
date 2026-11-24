<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Thud\Hooks\ScheduleHooks;
use Thud\Tests\Fixtures\Jobs\ChildJob;

afterEach(function (): void {
    putenv(ScheduleHooks::ENV);
    unset($_SERVER[ScheduleHooks::ENV], $_ENV[ScheduleHooks::ENV]);
});

it('records a scheduled callback in the scheduler process', function (): void {
    app(Schedule::class)
        ->call(fn () => DB::table('orders')->insert(['status' => 'imported']))
        ->name('orders:import')
        ->everyMinute();

    $this->artisan('schedule:run')->assertExitCode(0);

    $run = recordedRun('orders:import');

    expect($run['type'])->toBe('scheduled')
        ->and($run['status'])->toBe('succeeded')
        ->and($run['metrics']['db_insert'])->toBe(1);
});

it('names an unnamed closure after where it was defined', function (): void {
    app(Schedule::class)->call(fn () => null)->everyMinute();

    $this->artisan('schedule:run')->assertExitCode(0);

    expect(recordedRuns())->toHaveCount(1)
        ->and(recordedRuns()[0]['name'])->toStartWith('Closure at ')
        ->toContain('ScheduleRecordingTest.php:');
});

it('records a failing scheduled callback as failed', function (): void {
    app(Schedule::class)
        ->call(fn () => throw new RuntimeException('Export failed'))
        ->name('exports:nightly')
        ->everyMinute();

    $this->artisan('schedule:run');

    expect(recordedRun('exports:nightly'))->toMatchArray(['status' => 'failed', 'exception' => RuntimeException::class]);
});

it('records a task whose filters fail as skipped', function (): void {
    app(Schedule::class)
        ->call(fn () => null)
        ->name('reports:weekly')
        ->everyMinute()
        ->when(fn () => false);

    $this->artisan('schedule:run');

    $run = recordedRun('reports:weekly');

    expect($run['status'])->toBe('skipped')
        ->and($run['duration_ms'])->toBe(0);
});

it('names a scheduled job after its class', function (): void {
    app(Schedule::class)->job(new ChildJob)->everyMinute();

    $this->artisan('schedule:run')->assertExitCode(0);

    expect(array_column(recordedRuns(), 'name'))->toContain(ChildJob::class);
});

it('names artisan commands without the PHP binary and artisan path', function (): void {
    $event = app(Schedule::class)->command('invoices:send --force');

    expect(ScheduleHooks::nameFor($event))->toBe('invoices:send --force');
});

it('hands a scheduled artisan command to the child process that runs it', function (): void {
    $event = app(Schedule::class)->command('invoices:send');
    $events = app('events');

    // Scheduler process: the task starts. No run is recorded here, the run ID goes to the child.
    $events->dispatch(new ScheduledTaskStarting($event));

    $handoff = json_decode((string) getenv(ScheduleHooks::ENV), true);
    expect($handoff['name'])->toBe('invoices:send')
        ->and($_SERVER[ScheduleHooks::ENV])->toBe(getenv(ScheduleHooks::ENV))
        ->and(recordedRuns())->toBe([]);

    // Child process: the command starts, does its work, finishes.
    $events->dispatch(new CommandStarting('invoices:send', new ArrayInput([]), new NullOutput));
    expect(getenv(ScheduleHooks::ENV))->toBeFalse('grandchildren must not adopt the run');

    DB::table('orders')->insert(['status' => 'invoiced']);
    $events->dispatch(new CommandFinished('invoices:send', new ArrayInput([]), new NullOutput, 0));

    // Scheduler process: the task finishes. Nothing more is recorded.
    $events->dispatch(new ScheduledTaskFinished($event, 1.0));

    $run = recordedRun('invoices:send');

    expect(recordedRuns())->toHaveCount(1)
        ->and($run['run_id'])->toBe($handoff['id'])
        ->and($run['status'])->toBe('succeeded')
        ->and($run['metrics']['db_insert'])->toBe(1);
});

it('records a scheduled command that exits non-zero as failed', function (): void {
    $event = app(Schedule::class)->command('invoices:send');
    $events = app('events');

    $events->dispatch(new ScheduledTaskStarting($event));
    $events->dispatch(new CommandStarting('invoices:send', new ArrayInput([]), new NullOutput));
    $events->dispatch(new CommandFinished('invoices:send', new ArrayInput([]), new NullOutput, 1));

    expect(recordedRun('invoices:send')['status'])->toBe('failed');
});

it('does not adopt a run for commands the scheduler did not start', function (): void {
    $events = app('events');

    $events->dispatch(new CommandStarting('migrate', new ArrayInput([]), new NullOutput));
    DB::table('orders')->insert(['status' => 'pending']);
    $events->dispatch(new CommandFinished('migrate', new ArrayInput([]), new NullOutput, 0));

    expect(recordedRuns())->toBe([]);
});
