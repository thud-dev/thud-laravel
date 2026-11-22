<?php

declare(strict_types=1);

namespace Thud\Reconcile;

use Carbon\CarbonImmutable;
use Closure;
use Thud\Reconcile\Contracts\PaymentProvider;

/**
 * Reads a provider's recent payments and active subscriptions and checks them against local
 * records. Stops early (truncated) when it runs longer than the time limit, so a huge account
 * never blocks the scheduler.
 */
final class Reconciler
{
    public const TIME_LIMIT_SECONDS = 300;

    /**
     * @param  (Closure(): float)|null  $clock  seconds since an arbitrary start, for tests
     */
    public function __construct(private readonly ?Closure $clock = null) {}

    /**
     * @return array{provider: string, window_start: string, window_end: string, checked: int, truncated: bool, gaps: list<array<string, string|null>>}
     */
    public function run(PaymentProvider $provider, Mapping $mapping, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $started = $this->now();
        $truncated = false;
        $payments = [];
        $subscriptions = [];

        if ($mapping->checksPayments()) {
            foreach ($provider->successfulPayments($from, $to) as $payment) {
                $payments[] = $payment;

                if ($this->now() - $started > self::TIME_LIMIT_SECONDS) {
                    $truncated = true;
                    break;
                }
            }
        }

        if ($mapping->checksSubscriptions() && ! $truncated) {
            foreach ($provider->activeSubscriptions() as $subscription) {
                $subscriptions[] = $subscription;

                if ($this->now() - $started > self::TIME_LIMIT_SECONDS) {
                    $truncated = true;
                    break;
                }
            }
        }

        $gaps = [
            ...($mapping->checksPayments() ? $mapping->unrecordedPayments($payments) : []),
            ...($mapping->checksSubscriptions() ? $mapping->subscriptionGaps($subscriptions) : []),
        ];

        return [
            'provider' => $provider->name(),
            'window_start' => $from->toIso8601ZuluString(),
            'window_end' => $to->toIso8601ZuluString(),
            'checked' => count($payments) + count($subscriptions),
            'truncated' => $truncated,
            'gaps' => $gaps,
        ];
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
