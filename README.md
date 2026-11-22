# Thud for Laravel

**Your monitoring says everything is green. Thud tells you when green is lying.**

Thud catches Laravel queued jobs, scheduled commands and webhooks that report success but didn't actually do their work.

> Status: pre-release. The package is being built in phases; see `docs/BUILD.md`.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13 (Laravel 13 needs PHP 8.3+)

## Installation

```bash
composer require thud/laravel
php artisan vendor:publish --tag=thud-config
```

Set `THUD_ENABLED=false` to turn everything off with zero overhead.

## Payment reconciliation

Every night, `php artisan thud:reconcile` compares what your payment provider says happened with what your database says happened, and reports the gaps: payments that succeeded but aren't recorded, and subscriptions that are active at the provider but missing or inactive in your app. It runs inside your app with your own key, and sends Thud references and pass/fail only: never amounts, emails or card data.

Configure one or more providers in `config/thud.php`, mapping them to your own models:

```php
'payments' => [
    'stripe' => [
        'key' => env('THUD_STRIPE_KEY'),
        'map' => [
            'payments' => ['model' => App\Models\Payment::class, 'key' => 'stripe_payment_intent_id', 'paid' => ['paid_at' => 'not_null']],
            'subscriptions' => ['model' => App\Models\Subscription::class, 'key' => 'stripe_id', 'active' => ['status' => 'active']],
        ],
    ],
],
```

Conditions can be `'not_null'`, `'null'`, a list of allowed values, or an exact value. Leave out `payments` or `subscriptions` to skip that check.

| Provider | Key | Reference compared with your `key` column | Notes |
| --- | --- | --- | --- |
| `stripe` | A [restricted key](https://dashboard.stripe.com/apikeys) with read access to PaymentIntents and Subscriptions | Payment intent ID (`pi_…`), subscription ID (`sub_…`) | |
| `paystack` | Secret key (Paystack has no read-only keys; Thud only makes GET requests) | Transaction `reference`, `subscription_code` | |
| `paddle` | API key with read permissions | Transaction ID (`txn_…`), subscription ID (`sub_…`) | Add `'sandbox' => true` for the sandbox |
| `lemon_squeezy` | API key | Order ID, subscription ID | Add `'store_id' => …` to check one store |
| `flutterwave` | Secret key (only used for GET requests) | Transaction `tx_ref`, subscription ID | |

The command runs daily at 03:15 once a provider has a key (`reconcile_at` in the config; `null` turns it off). Run it by hand with `php artisan thud:reconcile stripe --hours=48`.

## Development

```bash
composer test      # Pest
composer analyse   # PHPStan level 8
composer lint      # Pint (check only); `composer format` to fix
```

## License

MIT. See [LICENSE.md](LICENSE.md).
