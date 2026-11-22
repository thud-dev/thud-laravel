<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Thud\Hooks\WebhookMiddleware;

beforeEach(function (): void {
    Route::post('/stripe/webhook', function () {
        DB::table('orders')->where('id', 1)->update(['status' => 'active']);

        return response()->noContent();
    })->middleware('thud:stripe')->name('stripe.webhook');

    Route::post('/paystack/webhook', fn () => response('ok'))->middleware('thud:paystack');

    Route::post('/broken/webhook', fn () => throw new RuntimeException('Handler crashed'))
        ->middleware('thud')
        ->name('broken.webhook');

    Route::post('/rejected/webhook', fn () => response('bad signature', 400))
        ->middleware('thud:stripe')
        ->name('rejected.webhook');
});

it('records a webhook run with its status and the provider event reference', function (): void {
    DB::table('orders')->insert(['status' => 'pending']);

    $this->postJson('/stripe/webhook', [
        'id' => 'evt_1QXYZ',
        'type' => 'invoice.paid',
        'data' => ['object' => ['customer_email' => 'buyer@example.com', 'amount_paid' => 4900]],
    ])->assertNoContent();

    $run = recordedRun('stripe.webhook');

    expect($run['type'])->toBe('webhook')
        ->and($run['status'])->toBe('succeeded')
        ->and($run['http_status'])->toBe(204)
        ->and($run['metrics']['db_update'])->toBe(1)
        ->and($run['provider_event'])->toBe(['provider' => 'stripe', 'id' => 'evt_1QXYZ', 'type' => 'invoice.paid']);
});

it('never records webhook payload data beyond the event ID and type', function (): void {
    $this->postJson('/stripe/webhook', [
        'id' => 'evt_1',
        'type' => 'invoice.paid',
        'data' => ['object' => ['customer_email' => 'buyer@example.com', 'amount_paid' => 4900]],
    ]);

    $json = json_encode(recordedRuns(), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('buyer@example.com')->not->toContain('4900');
});

it('names unnamed webhook routes by method and URI', function (): void {
    $this->postJson('/paystack/webhook', ['event' => 'charge.success', 'data' => ['id' => 302961]])->assertOk();

    $run = recordedRun('POST paystack/webhook');

    expect($run['provider_event'])->toBe(['provider' => 'paystack', 'id' => '302961', 'type' => 'charge.success']);
});

it('records a crashing handler as failed with status 500', function (): void {
    $this->postJson('/broken/webhook')->assertStatus(500);

    $run = recordedRun('broken.webhook');

    expect($run['status'])->toBe('failed')
        ->and($run['http_status'])->toBe(500)
        ->and($run['provider_event'])->toBeNull();
});

it('records a 4xx response as failed', function (): void {
    $this->postJson('/rejected/webhook', ['id' => 'evt_2', 'type' => 'customer.created'])->assertStatus(400);

    expect(recordedRun('rejected.webhook')['status'])->toBe('failed');
});

it('reads the event reference for every supported provider', function (string $provider, array $payload, ?array $expected): void {
    $request = Request::create('/hook', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR));

    expect(WebhookMiddleware::providerEvent($provider, $request))->toBe($expected);
})->with([
    'stripe' => ['stripe', ['id' => 'evt_1', 'type' => 'invoice.paid'], ['provider' => 'stripe', 'id' => 'evt_1', 'type' => 'invoice.paid']],
    'paystack' => ['paystack', ['event' => 'charge.success', 'data' => ['id' => 42]], ['provider' => 'paystack', 'id' => '42', 'type' => 'charge.success']],
    'paddle' => ['paddle', ['event_id' => 'evt_01h', 'event_type' => 'transaction.completed'], ['provider' => 'paddle', 'id' => 'evt_01h', 'type' => 'transaction.completed']],
    'lemon squeezy' => ['lemon_squeezy', ['meta' => ['event_name' => 'order_created'], 'data' => ['id' => '1']], ['provider' => 'lemon_squeezy', 'id' => '1', 'type' => 'order_created']],
    'flutterwave' => ['flutterwave', ['event' => 'charge.completed', 'data' => ['id' => 285959875]], ['provider' => 'flutterwave', 'id' => '285959875', 'type' => 'charge.completed']],
    'missing id' => ['stripe', ['type' => 'invoice.paid'], null],
    'unknown provider' => ['acme', ['id' => 'x'], null],
]);
