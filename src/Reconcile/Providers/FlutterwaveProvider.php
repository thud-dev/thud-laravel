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
 * Flutterwave v3, with the secret key (only used for GETs).
 *
 * - Payments: GET /v3/transactions?from=&to=&status=successful (dates as YYYY-MM-DD), keeping
 *   successful transactions inside the exact window. The reference is the merchant's tx_ref.
 * - Subscriptions: GET /v3/subscriptions?status=active.
 * - Both paginate with page until meta.page_info.total_pages.
 * - Replay: not supported.
 */
final class FlutterwaveProvider implements PaymentProvider
{
    public const BASE_URL = 'https://api.flutterwave.com/v3';

    public const MAX_SUBSCRIPTIONS = 10_000;

    public function __construct(
        private readonly Http $http,
        private readonly string $key,
        private readonly int $pauseMs = 200,
    ) {}

    public function name(): string
    {
        return 'flutterwave';
    }

    public function successfulPayments(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $query = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'status' => 'successful'];

        foreach ($this->list('/transactions', $query) as $transaction) {
            $ref = $transaction['tx_ref'] ?? null;
            $created = is_string($transaction['created_at'] ?? null) ? CarbonImmutable::parse($transaction['created_at'])->utc() : null;

            if (($transaction['status'] ?? null) !== 'successful' || ! is_string($ref) || $created === null) {
                continue;
            }

            // The API filters by day; keep only the exact window.
            if ($created->lessThan($from) || $created->greaterThan($to)) {
                continue;
            }

            $customer = is_array($transaction['customer'] ?? null) ? $transaction['customer'] : [];

            yield new ProviderPayment($ref, isset($customer['id']) ? (string) $customer['id'] : null, $created);
        }
    }

    public function activeSubscriptions(): iterable
    {
        $count = 0;

        foreach ($this->list('/subscriptions', ['status' => 'active']) as $subscription) {
            if (($subscription['status'] ?? null) !== 'active' || ! isset($subscription['id'])) {
                continue;
            }

            if (++$count > self::MAX_SUBSCRIPTIONS) {
                return;
            }

            $customer = is_array($subscription['customer'] ?? null) ? $subscription['customer'] : [];

            yield new ProviderSubscription(
                (string) $subscription['id'],
                isset($customer['id']) ? (string) $customer['id'] : null,
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
        $page = 1;

        do {
            $response = $this->request()->get(self::BASE_URL.$path, [...$query, 'page' => $page])->throw();
            $data = (array) $response->json('data', []);

            foreach ($data as $record) {
                if (is_array($record)) {
                    yield $record;
                }
            }

            $totalPages = (int) $response->json('meta.page_info.total_pages', 1);
            $page++;

            if ($page <= $totalPages && $this->pauseMs > 0) {
                usleep($this->pauseMs * 1000);
            }
        } while ($page <= $totalPages && $data !== []);
    }

    private function request(): PendingRequest
    {
        return $this->http->createPendingRequest()->withToken($this->key)->acceptJson()->timeout(20)->retry(2, 1000, throw: false);
    }
}
