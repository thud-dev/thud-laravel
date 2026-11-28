<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Thud\Reconcile\Mapping;
use Thud\Reconcile\Providers\PaystackProvider;
use Thud\Reconcile\Providers\StripeProvider;
use Thud\Reconcile\ReconcileCommand;
use Thud\Reconcile\Reconciler;
use Thud\Tests\Fixtures\Models\Payment;
use Thud\Tests\Fixtures\Models\Subscription;
use Thud\Transport\Client;
use Thud\Transport\Transport;

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

    // Local state: pi_1 is recorded and paid, pi_2 is recorded but never marked paid, pi_3 is missing.
    Payment::query()->create(['provider_ref' => 'pi_1', 'paid_at' => now()]);
    Payment::query()->create(['provider_ref' => 'pi_2', 'paid_at' => null]);
    // sub_1 is active, sub_2 is cancelled locally, sub_3 is missing.
    Subscription::query()->create(['provider_ref' => 'sub_1', 'status' => 'active']);
    Subscription::query()->create(['provider_ref' => 'sub_2', 'status' => 'cancelled']);

    $this->map = [
        'payments' => ['model' => Payment::class, 'key' => 'provider_ref', 'paid' => ['paid_at' => 'not_null']],
        'subscriptions' => ['model' => Subscription::class, 'key' => 'provider_ref', 'active' => ['status' => 'active']],
    ];
});

function stripeFixtures(): void
{
    Http::fake([
        'api.stripe.com/v1/payment_intents*' => Http::sequence()
            ->push(['object' => 'list', 'has_more' => true, 'data' => [
                ['id' => 'pi_1', 'status' => 'succeeded', 'customer' => 'cus_A', 'created' => 1763700000],
                ['id' => 'pi_x', 'status' => 'requires_payment_method', 'customer' => null, 'created' => 1763700100],
            ]])
            ->push(['object' => 'list', 'has_more' => false, 'data' => [
                ['id' => 'pi_2', 'status' => 'succeeded', 'customer' => 'cus_B', 'created' => 1763700200],
                ['id' => 'pi_3', 'status' => 'succeeded', 'customer' => null, 'created' => 1763700300],
            ]]),
        'api.stripe.com/v1/subscriptions*' => Http::response(['object' => 'list', 'has_more' => false, 'data' => [
            ['id' => 'sub_1', 'status' => 'active', 'customer' => 'cus_A'],
            ['id' => 'sub_2', 'status' => 'active', 'customer' => 'cus_B'],
            ['id' => 'sub_3', 'status' => 'active', 'customer' => 'cus_C'],
        ]]),
        'ingest.thud.test/*' => Http::response(null, 202),
    ]);
}

function paystackFixtures(): void
{
    Http::fake([
        'api.paystack.co/transaction*' => Http::sequence()
            ->push(['status' => true, 'data' => [
                ['reference' => 'pi_1', 'status' => 'success', 'paid_at' => '2026-11-21T10:00:00.000Z', 'customer' => ['customer_code' => 'CUS_a']],
            ], 'meta' => ['total' => 3, 'perPage' => 100, 'page' => 1, 'pageCount' => 2]])
            ->push(['status' => true, 'data' => [
                ['reference' => 'pi_3', 'status' => 'success', 'paid_at' => '2026-11-21T11:00:00.000Z', 'customer' => ['customer_code' => 'CUS_c']],
            ], 'meta' => ['total' => 3, 'perPage' => 100, 'page' => 2, 'pageCount' => 2]]),
        'api.paystack.co/subscription*' => Http::response(['status' => true, 'data' => [
            ['subscription_code' => 'sub_1', 'status' => 'active', 'customer' => ['customer_code' => 'CUS_a']],
            ['subscription_code' => 'sub_2', 'status' => 'active', 'customer' => ['customer_code' => 'CUS_b']],
            ['subscription_code' => 'sub_9', 'status' => 'cancelled', 'customer' => ['customer_code' => 'CUS_z']],
        ], 'meta' => ['pageCount' => 1]]),
        'ingest.thud.test/*' => Http::response(null, 202),
    ]);
}

it('finds Stripe payments and subscriptions missing or wrong in the database, across pages', function (): void {
    stripeFixtures();
    $to = CarbonImmutable::parse('2026-11-22 00:00', 'UTC');

    $result = (new Reconciler)->run(new StripeProvider(app(Factory::class), 'rk_test', 0), new Mapping($this->map), $to->subHours(48), $to);

    expect($result['provider'])->toBe('stripe')
        ->and($result['checked'])->toBe(6) // 3 succeeded intents + 3 active subscriptions
        ->and($result['truncated'])->toBeFalse()
        ->and(array_map(fn ($g) => $g['check'].':'.$g['provider_ref'], $result['gaps']))->toBe([
            'payment_not_recorded:pi_2',
            'payment_not_recorded:pi_3',
            'subscription_inactive_locally:sub_2',
            'subscription_missing_locally:sub_3',
        ])
        ->and($result['gaps'][0]['customer_ref'])->toBe('cus_B');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/payment_intents')
        && str_contains(urldecode($r->url()), 'created[gte]='.$to->subHours(48)->getTimestamp())
        && $r->hasHeader('Authorization', 'Bearer rk_test'));
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/payment_intents') && ($r['starting_after'] ?? null) === 'pi_x');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/subscriptions') && $r['status'] === 'active');
});

it('finds Paystack gaps across pages and ignores inactive subscriptions', function (): void {
    paystackFixtures();
    $to = CarbonImmutable::parse('2026-11-22 00:00', 'UTC');

    $result = (new Reconciler)->run(new PaystackProvider(app(Factory::class), 'sk_test', 0), new Mapping($this->map), $to->subHours(48), $to);

    expect($result['checked'])->toBe(4)
        ->and(array_map(fn ($g) => $g['check'].':'.$g['provider_ref'], $result['gaps']))->toBe([
            'payment_not_recorded:pi_3',
            'subscription_inactive_locally:sub_2',
        ])
        ->and($result['gaps'][0]['occurred_at'])->toBe('2026-11-21T11:00:00Z');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/transaction') && $r['status'] === 'success' && (int) $r['page'] === 2);
});

it('stops after the time limit and marks the check as truncated', function (): void {
    stripeFixtures();
    $ticks = 0;
    $clock = function () use (&$ticks): float {
        return ($ticks++) * 200.0; // every check is 200 seconds later
    };

    $result = (new Reconciler($clock))->run(new StripeProvider(app(Factory::class), 'rk', 0), new Mapping($this->map), now()->subDay()->toImmutable(), now()->toImmutable());

    expect($result['truncated'])->toBeTrue()
        ->and($result['checked'])->toBeLessThan(6);
});

it('only checks what is mapped', function (): void {
    stripeFixtures();

    $result = (new Reconciler)->run(new StripeProvider(app(Factory::class), 'rk', 0), new Mapping(['subscriptions' => $this->map['subscriptions']]), now()->subDay()->toImmutable(), now()->toImmutable());

    expect($result['checked'])->toBe(3);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/payment_intents'));
});

it('sends references and results to Thud, never amounts or emails', function (): void {
    stripeFixtures();
    config(['thud.payments' => ['stripe' => ['key' => 'rk_live', 'map' => $this->map]]]);

    $this->artisan('thud:reconcile', ['--hours' => 24])->assertExitCode(0)->expectsOutputToContain('4 gaps');

    Http::assertSent(function (Request $r): bool {
        if ($r->url() !== 'https://ingest.thud.test/v1/payments/checks') {
            return false;
        }

        $body = json_decode((string) gzdecode($r->body()), true);

        return $body['provider'] === 'stripe'
            && $body['checked'] === 6
            && count($body['gaps']) === 4
            && array_keys($body) === ['provider', 'window_start', 'window_end', 'checked', 'truncated', 'gaps', 'mapping']
            // The mapping's shape, so the dashboard can name the record it expected: never values.
            && $body['mapping'] === [
                'payments' => ['model' => Payment::class, 'key' => 'provider_ref', 'conditions' => ['paid_at' => 'not_null']],
                'subscriptions' => ['model' => Subscription::class, 'key' => 'provider_ref', 'conditions' => ['status' => 'active']],
            ];
    });
});

it('explains when no provider is configured, or an unknown one is asked for', function (): void {
    config(['thud.payments' => []]);
    $this->artisan('thud:reconcile')->assertExitCode(0)->expectsOutputToContain('No payment provider is configured');

    config(['thud.payments' => ['stripe' => ['key' => 'rk', 'map' => []]]]);
    $this->artisan('thud:reconcile', ['provider' => 'venmo'])->assertExitCode(1);
});

it('stops calling for a day when the plan does not include reconciliation', function (): void {
    Http::fake([
        'api.stripe.com/*' => Http::response(['object' => 'list', 'has_more' => false, 'data' => []]),
        'free-plan.thud.test/*' => Http::response(['message' => 'Upgrade'], 402),
    ]);
    config(['thud.payments' => ['stripe' => ['key' => 'rk', 'map' => $this->map]], 'thud.endpoint' => 'https://free-plan.thud.test/v1']);
    app()->forgetInstance(Client::class);
    app()->forgetInstance(Transport::class);

    $this->artisan('thud:reconcile')->assertExitCode(0)->expectsOutputToContain('needs the Team plan');

    expect(Cache::has(ReconcileCommand::PLAN_BLOCK_KEY))->toBeTrue();
});

it('fetches a Stripe event for replay; Paystack has none', function (): void {
    Http::fake(['api.stripe.com/v1/events/evt_1' => Http::response(['id' => 'evt_1', 'type' => 'invoice.paid'])]);
    $http = app(Factory::class);

    expect((new StripeProvider($http, 'rk'))->fetchEventForReplay('evt_1'))->toMatchArray(['type' => 'invoice.paid'])
        ->and((new PaystackProvider($http, 'sk'))->fetchEventForReplay('T1'))->toBeNull();
});
