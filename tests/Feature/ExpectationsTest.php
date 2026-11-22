<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Thud\Expectations\Expect;
use Thud\Expectations\Result;
use Thud\Expectations\VerifyOutcome;
use Thud\Recording\RunContext;
use Thud\Tests\Fixtures\FixtureMail;
use Thud\Tests\Fixtures\FixtureNotification;
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

describe('expectation types', function (): void {
    beforeEach(function (): void {
        $this->run = new RunContext('job', 'Example');
        $this->counter = $this->run->counter;
    });

    it('checks a mailable was sent, optionally to an address', function (): void {
        expect(Expect::mailSent(FixtureMail::class)->evaluate($this->run)->status)->toBe(Result::FAILED);

        $this->counter->mailSent(FixtureMail::class, ['Customer@Example.com']);

        expect(Expect::mailSent(FixtureMail::class)->evaluate($this->run)->status)->toBe(Result::PASSED)
            ->and(Expect::mailSent(FixtureMail::class)->to(' customer@example.COM')->evaluate($this->run)->status)->toBe(Result::PASSED)
            ->and(Expect::mailSent(FixtureMail::class)->to('someone@else.com')->evaluate($this->run)->status)->toBe(Result::FAILED);
    });

    it('checks a notification was sent', function (): void {
        $this->counter->notificationSent(FixtureNotification::class);

        expect(Expect::notificationSent(FixtureNotification::class)->evaluate($this->run)->status)->toBe(Result::PASSED)
            ->and(Expect::notificationSent('App\\Notifications\\Other')->evaluate($this->run)->status)->toBe(Result::FAILED);
    });

    it('checks an HTTP call was made, optionally with a status', function (): void {
        $this->counter->httpCall('api.paystack.co', '2xx', 200);

        expect(Expect::httpCalled('api.paystack.co')->evaluate($this->run)->status)->toBe(Result::PASSED)
            ->and(Expect::httpCalled('API.Paystack.co')->withStatus(200)->evaluate($this->run)->status)->toBe(Result::PASSED)
            ->and(Expect::httpCalled('api.paystack.co')->withStatus(201)->evaluate($this->run)->status)->toBe(Result::FAILED)
            ->and(Expect::httpCalled('api.stripe.com')->evaluate($this->run)->status)->toBe(Result::FAILED);
    });

    it('checks minimum counts', function (): void {
        foreach (range(1, 3) as $i) {
            $this->counter->mailSent(FixtureMail::class);
        }
        $this->counter->increment('db_update');

        expect(Expect::atLeast(3)->mailsSent(FixtureMail::class)->evaluate($this->run)->status)->toBe(Result::PASSED)
            ->and(Expect::atLeast(4)->mailsSent()->evaluate($this->run)->message)->toBe('Expected at least 4 mails, got 3')
            ->and(Expect::atLeast(1)->rowsWritten()->evaluate($this->run)->status)->toBe(Result::PASSED)
            ->and(Expect::atLeast(1)->jobsDispatched()->evaluate($this->run)->status)->toBe(Result::FAILED);
    });

    it('checks state with a closure', function (): void {
        expect(Expect::state(fn () => true)->evaluate($this->run)->status)->toBe(Result::PASSED)
            ->and(Expect::state(fn () => false)->evaluate($this->run)->status)->toBe(Result::FAILED)
            ->and(Expect::state(fn () => 'yes')->evaluate($this->run)->status)->toBe(Result::ERROR);
    });
});

describe('queued jobs', function (): void {
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
});
