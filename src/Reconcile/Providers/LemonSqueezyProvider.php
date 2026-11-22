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
 * Lemon Squeezy (JSON:API), with an API key.
 *
 * - Payments: GET /v1/orders (optionally filter[store_id]). The list has no status or date
 *   filter, so only `paid` orders created in the window are kept; orders come newest first, so
 *   paging stops at the first page that is entirely older than the window.
 * - Subscriptions: GET /v1/subscriptions?filter[status]=active.
 * - Both paginate with page[number] / page[size]=100 while links.next is present.
 * - Replay: not supported.
 */
final class LemonSqueezyProvider implements PaymentProvider
{
    public const BASE_URL = 'https://api.lemonsqueezy.com/v1';

    public const MAX_SUBSCRIPTIONS = 10_000;

    public function __construct(
        private readonly Http $http,
        private readonly string $key,
        private readonly ?string $storeId = null,
        private readonly int $pauseMs = 250,
    ) {}

    public function name(): string
    {
        return 'lemon_squeezy';
    }

    public function successfulPayments(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        foreach ($this->pages('/orders', $this->storeFilter()) as $page) {
            $olderThanWindow = 0;

            foreach ($page as $order) {
                $attributes = is_array($order['attributes'] ?? null) ? $order['attributes'] : [];
                $created = is_string($attributes['created_at'] ?? null) ? CarbonImmutable::parse($attributes['created_at'])->utc() : null;

                if ($created !== null && $created->lessThan($from)) {
                    $olderThanWindow++;

                    continue;
                }

                if (($attributes['status'] ?? null) !== 'paid' || $created === null || $created->greaterThan($to) || ! isset($order['id'])) {
                    continue;
                }

                yield new ProviderPayment(
                    (string) $order['id'],
                    isset($attributes['customer_id']) ? (string) $attributes['customer_id'] : null,
                    $created,
                );
            }

            if ($page !== [] && $olderThanWindow === count($page)) {
                return;
            }
        }
    }

    public function activeSubscriptions(): iterable
    {
        $count = 0;

        foreach ($this->pages('/subscriptions', [...$this->storeFilter(), 'filter[status]' => 'active']) as $page) {
            foreach ($page as $subscription) {
                $attributes = is_array($subscription['attributes'] ?? null) ? $subscription['attributes'] : [];

                if (($attributes['status'] ?? null) !== 'active' || ! isset($subscription['id'])) {
                    continue;
                }

                if (++$count > self::MAX_SUBSCRIPTIONS) {
                    return;
                }

                yield new ProviderSubscription(
                    (string) $subscription['id'],
                    isset($attributes['customer_id']) ? (string) $attributes['customer_id'] : null,
                    'active',
                );
            }
        }
    }

    public function fetchEventForReplay(string $ref): ?array
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    private function storeFilter(): array
    {
        return $this->storeId === null || $this->storeId === '' ? [] : ['filter[store_id]' => $this->storeId];
    }

    /**
     * One list of records per page, while links.next is present.
     *
     * @param  array<string, scalar>  $query
     * @return iterable<list<array<string, mixed>>>
     */
    private function pages(string $path, array $query): iterable
    {
        $number = 1;

        do {
            $response = $this->request()
                ->get(self::BASE_URL.$path, [...$query, 'page[number]' => $number, 'page[size]' => 100])
                ->throw();

            $records = array_values(array_filter((array) $response->json('data', []), 'is_array'));

            yield $records;

            $hasNext = is_string($response->json('links.next')) && $records !== [];
            $number++;

            if ($hasNext && $this->pauseMs > 0) {
                usleep($this->pauseMs * 1000);
            }
        } while ($hasNext);
    }

    private function request(): PendingRequest
    {
        return HttpRequest::withToken($this->http, $this->key)
            ->withHeaders(['Accept' => 'application/vnd.api+json', 'Content-Type' => 'application/vnd.api+json'])
            ->timeout(20)
            ->retry(2, 1000, throw: false);
    }
}
