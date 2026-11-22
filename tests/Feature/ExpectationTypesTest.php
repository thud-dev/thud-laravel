<?php

declare(strict_types=1);

use Thud\Expectations\Expect;
use Thud\Expectations\Result;
use Thud\Recording\RunContext;
use Thud\Tests\Fixtures\FixtureMail;
use Thud\Tests\Fixtures\FixtureNotification;

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
