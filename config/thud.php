<?php

declare(strict_types=1);

return [

    /*
    | Kill switch. When false, Thud registers no listeners and adds zero overhead.
    */
    'enabled' => env('THUD_ENABLED', true),

    'token' => env('THUD_TOKEN'),

    'endpoint' => env('THUD_ENDPOINT', 'https://ingest.thud.dev/v1'),

    'environment' => env('THUD_ENV', env('APP_ENV')),

    'record' => [
        'jobs' => true,
        'scheduled' => true,
        'webhooks' => true,
    ],

    /*
    | Tables whose writes are queue/framework noise, not real side effects.
    */
    'ignore_tables' => [
        'jobs',
        'failed_jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
        'telescope_entries',
        'pulse_entries',
    ],

    // Class names to skip entirely.
    'ignore_jobs' => [],

    // Class names allowed to do nothing on some runs.
    'may_be_empty' => [],

    /*
    | Runs are recorded but never sent while the app runs its own test suite (so a THUD_TOKEN
    | in .env can't pollute your baselines). Use Thud::fake() to assert on runs in tests.
    */
    'send_in_tests' => false,

    // Queue for VerifyOutcome jobs. null = the default queue.
    'verify_queue' => env('THUD_VERIFY_QUEUE'),

    /*
    | Payment reconciliation. Use restricted, read-only keys only.
    |
    | Example mapping:
    |
    | 'stripe' => [
    |     'key' => env('THUD_STRIPE_KEY'),
    |     'map' => [
    |         'subscriptions' => ['model' => App\Models\Subscription::class, 'key' => 'stripe_id', 'active' => ['status' => 'active']],
    |         'payments' => ['model' => App\Models\Payment::class, 'key' => 'stripe_payment_intent_id', 'paid' => ['paid_at' => 'not_null']],
    |     ],
    | ],
    |
    | 'paystack' => [
    |     'key' => env('THUD_PAYSTACK_SECRET_KEY'),
    |     'map' => [
    |         'payments' => ['model' => App\Models\Payment::class, 'key' => 'paystack_reference', 'paid' => ['status' => 'paid']],
    |         'subscriptions' => ['model' => App\Models\Subscription::class, 'key' => 'paystack_subscription_code', 'active' => ['status' => 'active']],
    |     ],
    | ],
    |
    | Condition values: 'not_null', 'null', a list of allowed values, or an exact value.
    | Leave out 'payments' or 'subscriptions' to skip that check.
    */
    'payments' => [],

    // When thud:reconcile runs each night (app timezone), once a provider is configured. null = never.
    'reconcile_at' => '03:15',

];
