<?php

declare(strict_types=1);

namespace Thud\Reconcile\Providers;

use Illuminate\Http\Client\Factory as Http;
use Thud\Reconcile\Contracts\PaymentProvider;

/**
 * Builds the configured payment providers from config('thud.payments').
 */
final class Providers
{
    public const SUPPORTED = ['stripe', 'paystack'];

    public function __construct(private readonly Http $http) {}

    /**
     * Providers with a key in config('thud.payments').
     *
     * @return list<string>
     */
    public static function configured(): array
    {
        $payments = config('thud.payments');

        if (! is_array($payments)) {
            return [];
        }

        return array_values(array_filter(
            array_keys($payments),
            static fn ($name) => is_string($name) && is_array($payments[$name]) && is_string($payments[$name]['key'] ?? null) && $payments[$name]['key'] !== '',
        ));
    }

    public function make(string $name): ?PaymentProvider
    {
        $key = config("thud.payments.{$name}.key");

        if (! is_string($key) || $key === '') {
            return null;
        }

        return match ($name) {
            'stripe' => new StripeProvider($this->http, $key),
            'paystack' => new PaystackProvider($this->http, $key),
            default => null,
        };
    }
}
