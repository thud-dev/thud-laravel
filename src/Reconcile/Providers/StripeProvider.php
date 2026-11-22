<?php

declare(strict_types=1);

namespace Thud\Reconcile\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Thud\Reconcile\Contracts\PaymentProvider;
use Thud\Reconcile\ProviderPayment;
use Thud\Reconcile\ProviderSubscription;

/**
 * Stripe, with a restricted read-only key.
 *
 * - Payments: GET /v1/payment_intents (created[gte]/[lte]); the list has no status filter, so
 *   only `succeeded` intents are kept.
 * - Subscriptions: GET /v1/subscriptions?status=active.
 * - Both paginate with starting_after / has_more, 100 per page.
 * - Replay: GET /v1/events/{id}.
 */
final class StripeProvider implements PaymentProvider
{
    public const BASE_URL = 'https://api.stripe.com/v1';

    public const MAX_SUBSCRIPTIONS = 10_000;

    public function __construct(
        private readonly Http $http,
        private readonly string $key,
        private readonly int $pauseMs = 100,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function successfulPayments(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        foreach ($this->list('/payment_intents', ['created[gte]' => $from->getTimestamp(), 'created[lte]' => $to->getTimestamp()]) as $intent) {
            if (($intent['status'] ?? null) !== 'succeeded' || ! is_string($intent['id'] ?? null)) {
                continue;
            }

            yield new ProviderPayment(
                $intent['id'],
                is_string($intent['customer'] ?? null) ? $intent['customer'] : null,
                CarbonImmutable::createFromTimestampUTC((int) ($intent['created'] ?? 0)),
            );
        }
    }

    public function activeSubscriptions(): iterable
    {
        $count = 0;

        foreach ($this->list('/subscriptions', ['status' => 'active']) as $subscription) {
            if (! is_string($subscription['id'] ?? null) || ++$count > self::MAX_SUBSCRIPTIONS) {
                continue;
            }

            yield new ProviderSubscription(
                $subscription['id'],
                is_string($subscription['customer'] ?? null) ? $subscription['customer'] : null,
                (string) ($subscription['status'] ?? 'active'),
            );
        }
    }

    public function fetchEventForReplay(string $ref): ?array
    {
        $response = $this->request()->get(self::BASE_URL.'/events/'.rawurlencode($ref));

        return $response->successful() ? (array) $response->json() : null;
    }

    /**
     * Every object of a Stripe list endpoint, page by page.
     *
     * @param  array<string, scalar>  $query
     * @return iterable<array<string, mixed>>
     */
    private function list(string $path, array $query): iterable
    {
        $startingAfter = null;

        do {
            $response = $this->request()
                ->get(self::BASE_URL.$path, [...$query, 'limit' => 100, ...($startingAfter === null ? [] : ['starting_after' => $startingAfter])])
                ->throw();

            $data = (array) $response->json('data', []);

            foreach ($data as $object) {
                if (is_array($object)) {
                    yield $object;
                    $startingAfter = is_string($object['id'] ?? null) ? $object['id'] : $startingAfter;
                }
            }

            $hasMore = (bool) $response->json('has_more', false) && $data !== [];

            if ($hasMore && $this->pauseMs > 0) {
                usleep($this->pauseMs * 1000);
            }
        } while ($hasMore);
    }

    private function request(): PendingRequest
    {
        return $this->http->createPendingRequest()->withToken($this->key)->acceptJson()->timeout(20)->retry(2, 1000, throw: false);
    }
}
