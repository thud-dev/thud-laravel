<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Thud\Hooks\QueueHooks;
use Thud\Recording\SideEffectCounter;
use Thud\Tests\Fixtures\FixtureMail;
use Thud\Tests\Fixtures\FixtureNotification;
use Thud\Tests\Fixtures\Jobs\ChildJob;
use Thud\Tests\Fixtures\Jobs\DoesRealWork;
use Thud\Tests\Fixtures\Jobs\FailingJob;
use Thud\Tests\Fixtures\Jobs\ParentJob;
use Thud\Tests\Fixtures\Jobs\QueuesAnotherJob;
use Thud\Tests\Fixtures\Jobs\SwallowsException;

it('records the side effects of a job that does real work', function (): void {
    Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cus_123'])]);

    DoesRealWork::dispatch();

    $run = recordedRun(DoesRealWork::class);

    expect($run['type'])->toBe('job')
        ->and($run['status'])->toBe('succeeded')
        ->and($run['queue'])->toBe('sync')
        ->and($run['attempt'])->toBe(1)
        ->and($run['metrics'])->toBe([
            'db_insert' => 2,
            'db_update' => 1,
            'db_delete' => 1,
            // One mailable plus the mail the notification's mail channel sends.
            'mail_sent' => 2,
            'notification_sent' => 1,
            'http_out' => 1,
            'cache_write' => 1,
            'jobs_dispatched' => 0,
        ]);

    $breakdown = (array) $run['breakdown'];

    expect($breakdown['mail'])->toBe([FixtureMail::class => 1, FixtureNotification::class => 1])
        ->and($breakdown['notifications'])->toBe([FixtureNotification::class => 1])
        ->and($breakdown['http_hosts'])->toBe(['api.stripe.com' => ['2xx' => 1]]);
});

it('sends the exact run shape from the ingest contract', function (): void {
    Http::fake();

    DoesRealWork::dispatch();

    $run = recordedRun(DoesRealWork::class);

    expect(array_keys($run))->toBe([
        'run_id', 'type', 'name', 'queue', 'status', 'started_at', 'duration_ms',
        'attempt', 'metrics', 'breakdown', 'expectations', 'provider_event', 'exception', 'parent_run_id',
    ])
        ->and($run['run_id'])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($run['started_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/')
        ->and($run['duration_ms'])->toBeInt()
        ->and(array_keys($run['metrics']))->toBe(SideEffectCounter::METRICS)
        ->and($run['expectations'])->toBe([])
        ->and($run['provider_event'])->toBeNull()
        ->and($run['exception'])->toBeNull()
        ->and($run['parent_run_id'])->toBeNull();
});

it('never records addresses, URL paths or query strings', function (): void {
    Http::fake();

    DoesRealWork::dispatch();

    $json = json_encode(recordedRuns(), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('customer@example.com')
        ->not->toContain('cus_123')
        ->not->toContain('expand')
        ->not->toContain('secret');
});

it('records a job that swallows its exception as succeeded with zero side effects', function (): void {
    SwallowsException::dispatch();

    $run = recordedRun(SwallowsException::class);

    expect($run['status'])->toBe('succeeded')
        ->and(array_sum($run['metrics']))->toBe(0);
});

it('gives a nested dispatchSync job its own run without inflating the parent', function (): void {
    ParentJob::dispatch();

    $parent = recordedRun(ParentJob::class);
    $child = recordedRun(ChildJob::class);

    expect($parent['metrics']['db_insert'])->toBe(1)
        ->and($child['metrics']['db_insert'])->toBe(2)
        ->and($child['run_id'])->not->toBe($parent['run_id'])
        // The sync driver fires no JobQueued, so the inline child is counted as the parent's dispatch.
        ->and($parent['metrics']['jobs_dispatched'])->toBe(1)
        ->and(((array) $parent['breakdown'])['jobs'])->toBe([ChildJob::class => 1])
        ->and($child['parent_run_id'])->toBe($parent['run_id'])
        ->and($parent['parent_run_id'])->toBeNull()
        // The child finishes first.
        ->and(array_column(recordedRuns(), 'name'))->toBe([ChildJob::class, ParentJob::class]);
});

it('records a throwing job once, as failed', function (): void {
    expect(fn () => FailingJob::dispatch())->toThrow(RuntimeException::class, 'Boom');

    $run = recordedRun(FailingJob::class);

    expect($run['status'])->toBe('failed')
        ->and($run['metrics']['db_insert'])->toBe(1)
        // The class only: messages can hold user data.
        ->and($run['exception'])->toBe(RuntimeException::class)
        ->and(json_encode($run))->not->toContain('Boom');
});

it('counts queued jobs but not the queue table writes behind them', function (): void {
    QueuesAnotherJob::dispatch();

    $run = recordedRun(QueuesAnotherJob::class);

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($run['metrics']['jobs_dispatched'])->toBe(1)
        ->and($run['metrics']['db_insert'])->toBe(0)
        ->and(((array) $run['breakdown'])['jobs'])->toBe([ChildJob::class => 1])
        // The queued job carries the run that queued it, for the child's own run to point back.
        ->and(json_decode((string) DB::table('jobs')->value('payload'), true)['thud_parent_run_id'])->toBe($run['run_id']);
});

it('ignores writes to the jobs table when a worker runs the database queue', function (): void {
    config(['queue.connections.database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]]);

    ChildJob::dispatch()->onConnection('database');
    expect(recordedRuns())->toBe([]);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertExitCode(0);

    $run = recordedRun(ChildJob::class);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($run['queue'])->toBe('default')
        ->and($run['metrics']['db_insert'])->toBe(2)
        ->and($run['metrics']['db_update'])->toBe(0)
        ->and($run['metrics']['db_delete'])->toBe(0);
});

it('skips jobs listed in ignore_jobs', function (): void {
    config(['thud.ignore_jobs' => [ChildJob::class]]);
    app()->forgetInstance(QueueHooks::class);

    ParentJob::dispatch();

    expect(array_column(recordedRuns(), 'name'))->toBe([ParentJob::class])
        // The ignored child's writes don't leak into the parent either.
        ->and(recordedRun(ParentJob::class)['metrics']['db_insert'])->toBe(1);
});

it('does not record side effects outside a run', function (): void {
    DB::table('orders')->insert(['status' => 'pending']);

    expect(recordedRuns())->toBe([]);
});
