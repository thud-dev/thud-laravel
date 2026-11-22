<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\ExpectationFailedException;
use Thud\Facades\Thud;
use Thud\Tests\Fixtures\Jobs\ActivateSubscription;
use Thud\Tests\Fixtures\Jobs\ChildJob;
use Thud\Transport\Client;
use Thud\Transport\Transport;

it('reports the connected app', function (): void {
    pingAnswers(200, ['app' => 'billing-app', 'environment' => 'production']);

    $this->artisan('thud:test')->assertExitCode(0)->expectsOutputToContain('Connected as billing-app (production)');
});

it('explains a rejected token', function (): void {
    pingAnswers(401, ['message' => 'Invalid app token.']);

    $this->artisan('thud:test')->assertExitCode(1)->expectsOutputToContain('token was rejected');
});

it('explains a missing token', function (): void {
    app()->instance(Transport::class, new Client(app(Factory::class), null, 'https://ingest.thud.test/v1', 'testing'));

    $this->artisan('thud:test')->assertExitCode(1)->expectsOutputToContain('THUD_TOKEN is not set');
});

it('asserts expectations met, with within() checks evaluated immediately', function (): void {
    Thud::fake();
    $orderId = (int) DB::table('orders')->insertGetId(['status' => 'pending']);

    ActivateSubscription::dispatchSync($orderId);

    Thud::assertExpectationsMet(ActivateSubscription::class);
    Thud::assertRunRecorded(ActivateSubscription::class, fn ($run) => $run->metric('mail_sent') === 1
        && $run->expectation('Order is still active') === 'passed');
});

it('asserts a failed expectation', function (): void {
    Thud::fake();
    $orderId = (int) DB::table('orders')->insertGetId(['status' => 'pending']);

    ActivateSubscription::dispatchSync($orderId, false);

    Thud::assertExpectationFailed(ActivateSubscription::class, 'Order is active');
});

it('fails assertExpectationsMet when an expectation fails', function (): void {
    Thud::fake();
    $orderId = (int) DB::table('orders')->insertGetId(['status' => 'pending']);
    ActivateSubscription::dispatchSync($orderId, false);

    expect(fn () => Thud::assertExpectationsMet(ActivateSubscription::class))
        ->toThrow(ExpectationFailedException::class, 'Order is active: failed');
});

it('never calls the ingest API', function (): void {
    Thud::fake();

    ChildJob::dispatch();
    Thud::flush();

    Thud::assertRunRecorded(ChildJob::class);
    expect(ingestRequests())->toBe([]);
});
