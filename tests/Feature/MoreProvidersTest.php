<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Thud\Reconcile\Mapping;
use Thud\Reconcile\Providers\FlutterwaveProvider;
use Thud\Reconcile\Providers\LemonSqueezyProvider;
use Thud\Reconcile\Providers\PaddleProvider;
use Thud\Reconcile\Providers\Providers;
use Thud\Reconcile\Reconciler;
use Thud\Tests\Fixtures\Models\Payment;
use Thud\Tests\Fixtures\Models\Subscription;

beforeEach(function (): void {
    Schema::create('payments', function (Blueprint $table): void {
        $table->id();
        $table->string('provider_ref');
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });
    Schema::create('subscriptions', function (Blueprint $table): void {
        $table->id();
        $table->string('provider_ref');
        $table->string('status');
        $table->timestamps();
    });

    // Recorded locally: payment p1 (paid) and subscription s1 (active); s2 exists but is cancelled.
    Payment::query()->create(['provider_ref' => 'p1', 'paid_at' => now()]);
    Subscription::query()->create(['provider_ref' => 's1', 'status' => 'active']);
    Subscription::query()->create(['provider_ref' => 's2', 'status' => 'cancelled']);

    $this->map = new Mapping([
        'payments' => ['model' => Payment::class, 'key' => 'provider_ref', 'paid' => ['paid_at' => 'not_null']],
        'subscriptions' => ['model' => Subscription::class, 'key' => 'provider_ref', 'active' => ['status' => 'active']],
    ]);
    $this->to = CarbonImmutable::parse('2026-11-22 00:00', 'UTC');
    $this->from = $this->to->subHours(48);
    $this->gaps = fn (array $result) => array_map(fn ($g) => $g['check'].':'.$g['provider_ref'], $result['gaps']);
});

it('reconciles Paddle across cursor pages', function (): void {
    Http::fake([
        'api.paddle.com/transactions*' => Http::sequence()
            ->push(['data' => [['id' => 'p1', 'status' => 'completed', 'customer_id' => 'ctm_1', 'billed_at' => '2026-11-21T10:00:00Z']], 'meta' => ['pagination' => ['has_more' => true]]])
            ->push(['data' => [['id' => 'p2', 'status' => 'completed', 'customer_id' => 'ctm_2', 'billed_at' => '2026-11-21T11:00:00Z']], 'meta' => ['pagination' => ['has_more' => false]]]),
        'api.paddle.com/subscriptions*' => Http::response(['data' => [
            ['id' => 's1', 'status' => 'active', 'customer_id' => 'ctm_1'],
            ['id' => 's3', 'status' => 'active', 'customer_id' => 'ctm_3'],
        ], 'meta' => ['pagination' => ['has_more' => false]]]),
    ]);

    $result = (new Reconciler)->run(new PaddleProvider(app(Factory::class), 'pdl_key', pauseMs: 0), $this->map, $this->from, $this->to);

    expect(($this->gaps)($result))->toBe(['payment_not_recorded:p2', 'subscription_missing_locally:s3'])
        ->and($result['gaps'][0]['customer_ref'])->toBe('ctm_2');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/transactions') && ($r['after'] ?? null) === 'p1');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/transactions')
        && $r['status'] === 'completed'
        && str_contains(urldecode($r->url()), 'billed_at[GTE]=2026-11-20T00:00:00Z'));
});

it('uses the Paddle sandbox when configured', function (): void {
    Http::fake(['sandbox-api.paddle.com/*' => Http::response(['data' => [], 'meta' => ['pagination' => ['has_more' => false]]])]);
    config(['thud.payments.paddle' => ['key' => 'pdl_sbox', 'sandbox' => true]]);

    $result = (new Reconciler)->run(app(Providers::class)->make('paddle'), $this->map, $this->from, $this->to);

    expect($result['gaps'])->toBe([]);
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://sandbox-api.paddle.com/'));
});

it('reconciles Lemon Squeezy, keeping paid orders in the window and stopping at older pages', function (): void {
    Http::fake([
        'api.lemonsqueezy.com/v1/orders*' => Http::sequence()
            ->push(['data' => [
                ['id' => 'p1', 'attributes' => ['status' => 'paid', 'customer_id' => 11, 'created_at' => '2026-11-21T09:00:00.000000Z']],
                ['id' => 'p2', 'attributes' => ['status' => 'paid', 'customer_id' => 12, 'created_at' => '2026-11-21T08:00:00.000000Z']],
                ['id' => 'p9', 'attributes' => ['status' => 'refunded', 'customer_id' => 19, 'created_at' => '2026-11-21T07:00:00.000000Z']],
            ], 'links' => ['next' => 'https://api.lemonsqueezy.com/v1/orders?page[number]=2']])
            ->push(['data' => [
                ['id' => 'p0', 'attributes' => ['status' => 'paid', 'customer_id' => 10, 'created_at' => '2026-11-01T09:00:00.000000Z']],
            ], 'links' => ['next' => 'https://api.lemonsqueezy.com/v1/orders?page[number]=3']]),
        'api.lemonsqueezy.com/v1/subscriptions*' => Http::response(['data' => [
            ['id' => 's2', 'attributes' => ['status' => 'active', 'customer_id' => 12]],
        ], 'links' => []]),
    ]);

    $result = (new Reconciler)->run(new LemonSqueezyProvider(app(Factory::class), 'ls_key', storeId: '42', pauseMs: 0), $this->map, $this->from, $this->to);

    expect(($this->gaps)($result))->toBe(['payment_not_recorded:p2', 'subscription_inactive_locally:s2']);

    // Page 2 was entirely older than the window, so page 3 was never requested.
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'filter[store_id]=42')
        && $r->hasHeader('Accept', 'application/vnd.api+json'));
    Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'filter[status]=active'));
});

it('reconciles Flutterwave by tx_ref across pages, within the exact window', function (): void {
    Http::fake([
        'api.flutterwave.com/v3/transactions*' => Http::sequence()
            ->push(['status' => 'success', 'data' => [
                ['id' => 1, 'tx_ref' => 'p1', 'status' => 'successful', 'created_at' => '2026-11-21T10:00:00.000Z', 'customer' => ['id' => 501]],
                ['id' => 2, 'tx_ref' => 'p_failed', 'status' => 'failed', 'created_at' => '2026-11-21T10:05:00.000Z'],
            ], 'meta' => ['page_info' => ['total' => 4, 'current_page' => 1, 'total_pages' => 2]]])
            ->push(['status' => 'success', 'data' => [
                ['id' => 3, 'tx_ref' => 'p2', 'status' => 'successful', 'created_at' => '2026-11-21T12:00:00.000Z', 'customer' => ['id' => 502]],
                ['id' => 4, 'tx_ref' => 'p_early', 'status' => 'successful', 'created_at' => '2026-11-19T23:59:00.000Z'],
            ], 'meta' => ['page_info' => ['total' => 4, 'current_page' => 2, 'total_pages' => 2]]]),
        'api.flutterwave.com/v3/subscriptions*' => Http::response(['status' => 'success', 'data' => [
            ['id' => 900, 'status' => 'active', 'customer' => ['id' => 501, 'customer_email' => 'a@example.com']],
        ], 'meta' => ['page_info' => ['total_pages' => 1]]]),
    ]);

    $result = (new Reconciler)->run(new FlutterwaveProvider(app(Factory::class), 'FLWSECK_TEST', pauseMs: 0), $this->map, $this->from, $this->to);

    expect(($this->gaps)($result))->toBe(['payment_not_recorded:p2', 'subscription_missing_locally:900'])
        ->and(json_encode($result))->not->toContain('a@example.com');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/transactions')
        && $r['from'] === '2026-11-20' && $r['to'] === '2026-11-22' && $r['status'] === 'successful' && (int) $r['page'] === 2);
});

it('builds every supported provider from config', function (string $name): void {
    config(["thud.payments.{$name}" => ['key' => 'k']]);

    expect(app(Providers::class)->make($name)?->name())->toBe($name)
        ->and(Providers::configured())->toContain($name);
})->with(['stripe', 'paystack', 'paddle', 'lemon_squeezy', 'flutterwave']);
