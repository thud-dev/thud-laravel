<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Thud\Expectations\VerifyOutcome;
use Thud\Tests\Fixtures\Jobs\ActivateSubscription;
use Thud\Tests\Fixtures\Jobs\FailingJob;
use Thud\Tests\Fixtures\Jobs\ThrowingExpectation;

function orderId(): int
{
    return (int) DB::table('orders')->insertGetId(['status' => 'pending']);
}

/**
 * @return array<string, array<string, mixed>> expectations of the latest run of $name, by label
 */
function expectationsOf(string $name): array
{
    $run = recordedRun($name);

    return collect($run['expectations'])->keyBy('label')->all();
}

it('records passed expectations for a job that did its work', function (): void {
    ActivateSubscription::dispatch(orderId());

    $expectations = expectationsOf(ActivateSubscription::class);

    expect($expectations['Welcome mail sent']['result'])->toBe('passed')
        ->and($expectations['Order is active']['result'])->toBe('passed')
        ->and($expectations['ActivateSubscription::expectations()#3']['result'])->toBe('passed');
});

it('catches a job that "succeeded" without doing its work', function (): void {
    ActivateSubscription::dispatch(orderId(), doTheWork: false);

    $run = recordedRun(ActivateSubscription::class);
    $expectations = expectationsOf(ActivateSubscription::class);

    expect($run['status'])->toBe('succeeded')
        ->and($expectations['Welcome mail sent'])->toMatchArray(['result' => 'failed', 'message' => 'No FixtureMail was sent'])
        ->and($expectations['Order is active']['result'])->toBe('failed')
        ->and($expectations['ActivateSubscription::expectations()#3']['result'])->toBe('failed');
});

it('never counts the expectations\' own queries as side effects', function (): void {
    ActivateSubscription::dispatch(orderId());

    // One update by the job; the state checks' selects and VerifyOutcome's queue insert aren't counted.
    expect(recordedRun(ActivateSubscription::class)['metrics'])->toMatchArray(['db_update' => 1, 'jobs_dispatched' => 0]);
});

it('defers within() checks to a delayed VerifyOutcome job', function (): void {
    Bus::fake([VerifyOutcome::class]);

    ActivateSubscription::dispatch(orderId());

    $pending = expectationsOf(ActivateSubscription::class)['Order is still active'];

    expect($pending['result'])->toBe('pending')
        ->and($pending['due_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    Bus::assertDispatched(VerifyOutcome::class, fn (VerifyOutcome $job) => $job->label === 'Order is still active'
        && $job->index === 2
        && $job->runId === recordedRun(ActivateSubscription::class)['run_id']
        && $job->delay === 60);
});

it('reports a VerifyOutcome result for the same run', function (): void {
    $orderId = orderId();
    DB::table('orders')->where('id', $orderId)->update(['status' => 'active']);

    app()->call([VerifyOutcome::forJob('01JD3Z8Q6M4Y2K9V7W5T3R1P0N', 'Order is still active', 2, new ActivateSubscription($orderId)), 'handle']);

    Http::assertSent(function ($request): bool {
        $body = json_decode((string) gzdecode($request->body()), true);

        return $request->url() === 'https://ingest.thud.test/v1/runs/01JD3Z8Q6M4Y2K9V7W5T3R1P0N/results'
            && $body === ['results' => [['label' => 'Order is still active', 'result' => 'passed']]];
    });
});

it('records a throwing or misbehaving expectation as an error, without throwing', function (): void {
    ThrowingExpectation::dispatch();

    $expectations = expectationsOf(ThrowingExpectation::class);

    expect($expectations['Check that blows up'])->toMatchArray(['result' => 'error', 'message' => 'Threw RuntimeException'])
        ->and($expectations['Returns a string']['result'])->toBe('error')
        ->and(json_encode($expectations))->not->toContain('database unreachable');
});

it('does not evaluate expectations for a failed job', function (): void {
    expect(fn () => FailingJob::dispatch())->toThrow(RuntimeException::class);

    expect(recordedRun(FailingJob::class)['expectations'])->toBe([]);
});

it('never records VerifyOutcome itself as a run', function (): void {
    $orderId = orderId();
    dispatch(VerifyOutcome::forJob('01JD3Z8Q6M4Y2K9V7W5T3R1P0N', 'x', 2, new ActivateSubscription($orderId)));

    expect(array_column(recordedRuns(), 'name'))->not->toContain(VerifyOutcome::class);
});
