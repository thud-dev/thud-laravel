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
 * Paddle Billing, with an API key (read permissions are enough).
 *
 * - Payments: GET /transactions?status=completed&billed_at[GTE]=&billed_at[LTE]= (30 per page max).
 * - Subscriptions: GET /subscriptions?status=active (200 per page max).
 * - Both paginate with `after` (the last ID) while meta.pagination.has_more.
 * - Rate limit: 240 requests a minute per IP, so pages are spaced 300 ms apart.
 * - Replay: the API lists events but has no single-event endpoint, so replay is unsupported.
 */
final class PaddleProvider implements PaymentProvider
{
    public const BASE_URL = 'https://api.paddle.com';

    public const SANDBOX_URL = 'https://sandbox-api.paddle.com';

    public const MAX_SUBSCRIPTIONS = 10_000;

    public function __construct(
        private readonly Http $http,
        private readonly string $key,
        private readonly bool $sandbox = false,
        private readonly int $pauseMs = 300,
    ) {}

    public function name(): string
    {
        return 'paddle';
    }

    public function successfulPayments(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $query = [
            'status' => 'completed',
            'billed_at[GTE]' => $from->toIso8601ZuluString(),
            'billed_at[LTE]' => $to->toIso8601ZuluString(),
            'per_page' => 30,
        ];

        foreach ($this->list('/transactions', $query) as $transaction) {
            if (($transaction['status'] ?? null) !== 'completed' || ! is_string($transaction['id'] ?? null)) {
                continue;
            }

            $at = $transaction['billed_at'] ?? $transaction['created_at'] ?? null;

            yield new ProviderPayment(
                $transaction['id'],
                is_string($transaction['customer_id'] ?? null) ? $transaction['customer_id'] : null,
                is_string($at) ? CarbonImmutable::parse($at)->utc() : CarbonImmutable::now('UTC'),
            );
        }
    }

    public function activeSubscriptions(): iterable
    {
        $count = 0;

        foreach ($this->list('/subscriptions', ['status' => 'active', 'per_page' => 200]) as $subscription) {
            if (($subscription['status'] ?? null) !== 'active' || ! is_string($subscription['id'] ?? null)) {
                continue;
            }

            if (++$count > self::MAX_SUBSCRIPTIONS) {
                return;
            }

            yield new ProviderSubscription(
                $subscription['id'],
                is_string($subscription['customer_id'] ?? null) ? $subscription['customer_id'] : null,
                'active',
            );
        }
    }

    public function fetchEventForReplay(string $ref): ?array
    {
        return null;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return iterable<array<string, mixed>>
     */
    private function list(string $path, array $query): iterable
    {
        $after = null;

        do {
            $response = $this->request()
                ->get(($this->sandbox ? self::SANDBOX_URL : self::BASE_URL).$path, [...$query, ...($after === null ? [] : ['after' => $after])])
                ->throw();

            $data = (array) $response->json('data', []);

            foreach ($data as $record) {
                if (is_array($record)) {
                    yield $record;
                    $after = is_string($record['id'] ?? null) ? $record['id'] : $after;
                }
            }

            $hasMore = (bool) $response->json('meta.pagination.has_more', false) && $data !== [];

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
