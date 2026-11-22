<?php

declare(strict_types=1);

namespace Thud\Reconcile\Contracts;

use Carbon\CarbonImmutable;
use Thud\Reconcile\ProviderPayment;
use Thud\Reconcile\ProviderSubscription;

/**
 * One payment provider adapter. Uses Laravel's Http client with a read-only key; no SDKs.
 */
interface PaymentProvider
{
    /**
     * 'stripe', 'paystack', 'paddle', 'lemon_squeezy' or 'flutterwave'.
     */
    public function name(): string;

    /**
     * @return iterable<ProviderPayment>
     */
    public function successfulPayments(CarbonImmutable $from, CarbonImmutable $to): iterable;

    /**
     * Paginated; capped at 10,000 items.
     *
     * @return iterable<ProviderSubscription>
     */
    public function activeSubscriptions(): iterable;

    /**
     * @return array<string, mixed>|null null when the provider doesn't support replay
     */
    public function fetchEventForReplay(string $ref): ?array;
}
