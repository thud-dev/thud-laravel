<?php

declare(strict_types=1);

namespace Thud\Reconcile\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Thud\Reconcile\Contracts\PaymentProvider;
use Thud\Reconcile\ProviderPayment;
use Thud\Reconcile\ProviderSubscription;
use Thud\Support\HttpRequest;

/**
 * Paystack, with the secret key (Paystack has no read-only keys; it is only used for GETs).
 *
 * - Payments: GET /transaction?status=success&from=&to= (reference + customer.customer_code).
 * - Subscriptions: GET /subscription; the list has no status filter, so only `active` ones are kept.
 * - Both paginate with page / per page, until meta.pageCount.
 * - Replay: not supported by the API (link to the transaction in the Paystack dashboard instead).
 */
final class PaystackProvider implements PaymentProvider
{
    public const BASE_URL = 'https://api.paystack.co';

    public const MAX_SUBSCRIPTIONS = 10_000;

    public function __construct(
        private readonly Http $http,
        private readonly string $key,
        private readonly int $pauseMs = 200,
    ) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function successfulPayments(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $query = ['status' => 'success', 'from' => $from->toIso8601ZuluString(), 'to' => $to->toIso8601ZuluString()];

        foreach ($this->list('/transaction', $query) as $transaction) {
            if (($transaction['status'] ?? null) !== 'success' || ! is_string($transaction['reference'] ?? null)) {
                continue;
            }

            $customer = is_array($transaction['customer'] ?? null) ? $transaction['customer'] : [];
            $at = $transaction['paid_at'] ?? $transaction['paidAt'] ?? $transaction['created_at'] ?? $transaction['createdAt'] ?? null;

            yield new ProviderPayment(
                $transaction['reference'],
                is_string($customer['customer_code'] ?? null) ? $customer['customer_code'] : null,
                is_string($at) ? CarbonImmutable::parse($at)->utc() : CarbonImmutable::now('UTC'),
            );
        }
    }

    public function activeSubscriptions(): iterable
    {
        $count = 0;

        foreach ($this->list('/subscription', []) as $subscription) {
            if (($subscription['status'] ?? null) !== 'active' || ! is_string($subscription['subscription_code'] ?? null)) {
                continue;
            }

            if (++$count > self::MAX_SUBSCRIPTIONS) {
                return;
            }

            $customer = is_array($subscription['customer'] ?? null) ? $subscription['customer'] : [];

            yield new ProviderSubscription(
                $subscription['subscription_code'],
                is_string($customer['customer_code'] ?? null) ? $customer['customer_code'] : null,
                'active',
            );
        }
    }

    public function fetchEventForReplay(string $ref): ?array
    {
        return null;
    }

    /**
     * Every record of a Paystack list endpoint, page by page.
     *
     * @param  array<string, scalar>  $query
     * @return iterable<array<string, mixed>>
     */
    private function list(string $path, array $query): iterable
    {
        $page = 1;

        do {
            // The transaction list documents per_page; other lists use perPage. Sending both is harmless.
            $response = $this->request()
                ->get(self::BASE_URL.$path, [...$query, 'perPage' => 100, 'per_page' => 100, 'page' => $page])
                ->throw();

            $data = (array) $response->json('data', []);

            foreach ($data as $record) {
                if (is_array($record)) {
                    yield $record;
                }
            }

            $pageCount = (int) $response->json('meta.pageCount', 1);
            $page++;

            if ($page <= $pageCount && $this->pauseMs > 0) {
                usleep($this->pauseMs * 1000);
            }
        } while ($page <= $pageCount && $data !== []);
    }

    private function request(): PendingRequest
    {
        return HttpRequest::withToken($this->http, $this->key)->acceptJson()->timeout(20)->retry(2, 1000, throw: false);
    }
}
