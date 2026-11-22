<?php

declare(strict_types=1);

namespace Thud\Reconcile;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * Checks provider records against the app's own models, as configured in
 * config('thud.payments.{provider}.map'):
 *
 *     'payments' => ['model' => Payment::class, 'key' => 'stripe_payment_intent_id', 'paid' => ['paid_at' => 'not_null']],
 *     'subscriptions' => ['model' => Subscription::class, 'key' => 'stripe_id', 'active' => ['status' => 'active']],
 *
 * Condition values: 'not_null', 'null', a list (any of), or an exact value.
 * Lookups are batched, 500 references per query.
 */
final class Mapping
{
    public const CHUNK = 500;

    /**
     * @param  array<string, mixed>  $map
     */
    public function __construct(private readonly array $map) {}

    public function checksPayments(): bool
    {
        return isset($this->map['payments']);
    }

    public function checksSubscriptions(): bool
    {
        return isset($this->map['subscriptions']);
    }

    /**
     * Payments the provider says succeeded, with no matching paid record locally.
     *
     * @param  list<ProviderPayment>  $payments
     * @return list<array{check: string, provider_ref: string, customer_ref: string|null, occurred_at: string}>
     */
    public function unrecordedPayments(array $payments): array
    {
        $config = $this->config('payments');
        $gaps = [];

        foreach (array_chunk($payments, self::CHUNK) as $chunk) {
            $found = $this->matching($config, 'paid', array_map(fn (ProviderPayment $p) => $p->ref, $chunk));

            foreach ($chunk as $payment) {
                if (! isset($found[$payment->ref])) {
                    $gaps[] = [
                        'check' => 'payment_not_recorded',
                        'provider_ref' => $payment->ref,
                        'customer_ref' => $payment->customerRef,
                        'occurred_at' => $payment->occurredAt->toIso8601ZuluString(),
                    ];
                }
            }
        }

        return $gaps;
    }

    /**
     * Subscriptions the provider says are active, missing or not active locally.
     *
     * @param  list<ProviderSubscription>  $subscriptions
     * @return list<array{check: string, provider_ref: string, customer_ref: string|null}>
     */
    public function subscriptionGaps(array $subscriptions): array
    {
        $config = $this->config('subscriptions');
        $gaps = [];

        foreach (array_chunk($subscriptions, self::CHUNK) as $chunk) {
            $refs = array_map(fn (ProviderSubscription $s) => $s->ref, $chunk);
            $existing = $this->matching($config, null, $refs);
            $active = $this->matching($config, 'active', $refs);

            foreach ($chunk as $subscription) {
                $check = match (true) {
                    ! isset($existing[$subscription->ref]) => 'subscription_missing_locally',
                    ! isset($active[$subscription->ref]) => 'subscription_inactive_locally',
                    default => null,
                };

                if ($check !== null) {
                    $gaps[] = ['check' => $check, 'provider_ref' => $subscription->ref, 'customer_ref' => $subscription->customerRef];
                }
            }
        }

        return $gaps;
    }

    /**
     * The references among $refs that have a local row (meeting the $conditionsKey conditions).
     *
     * @param  array{model: class-string<Model>, key: string, conditions: array<string, array<string, mixed>>}  $config
     * @param  list<string>  $refs
     * @return array<string, true>
     */
    private function matching(array $config, ?string $conditionsKey, array $refs): array
    {
        $query = $config['model']::query();
        $base = $query->getQuery();
        $base->whereIn($config['key'], $refs);

        if ($conditionsKey !== null) {
            foreach ($config['conditions'][$conditionsKey] ?? [] as $column => $expected) {
                self::apply($base, $column, $expected);
            }
        }

        return array_fill_keys(array_map('strval', $query->pluck($config['key'])->all()), true);
    }

    private static function apply(QueryBuilder $query, string $column, mixed $expected): void
    {
        match (true) {
            $expected === 'not_null' => $query->whereNotNull($column),
            $expected === 'null' || $expected === null => $query->whereNull($column),
            is_array($expected) => $query->whereIn($column, $expected),
            default => $query->where($column, $expected),
        };
    }

    /**
     * @return array{model: class-string<Model>, key: string, conditions: array<string, array<string, mixed>>}
     */
    private function config(string $type): array
    {
        $entry = $this->map[$type] ?? null;
        $model = is_array($entry) ? ($entry['model'] ?? null) : null;
        $key = is_array($entry) ? ($entry['key'] ?? null) : null;

        if (! is_string($model) || ! is_subclass_of($model, Model::class) || ! is_string($key)) {
            throw new InvalidArgumentException("thud.payments map for [{$type}] needs a 'model' (an Eloquent model) and a 'key' column.");
        }

        $conditions = [];
        foreach (['paid', 'active'] as $name) {
            if (is_array($entry[$name] ?? null)) {
                $conditions[$name] = $entry[$name];
            }
        }

        return ['model' => $model, 'key' => $key, 'conditions' => $conditions];
    }
}
