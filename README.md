# Thud for Laravel

**Your monitoring says everything is green. Thud tells you when green is lying.**

Thud catches Laravel queued jobs, scheduled tasks and webhooks that report success but didn't do their work: the digest job that "succeeded" and sent 0 mails, the payment that went through at Stripe but never reached your database, the nightly export that quietly stopped running.

This package runs inside your app and sends a small, anonymous fingerprint of each run to [Thud](https://thud.dev), which learns what normal looks like and alerts you when a run succeeds but does nothing. Full documentation: [thud.dev/docs](https://thud.dev/docs).

- [Requirements](#requirements)
- [Installation](#installation)
- [Zero-config mode](#zero-config-mode)
- [Expectations](#expectations)
- [Scheduled tasks](#scheduled-tasks)
- [Webhooks](#webhooks)
- [Payment reconciliation](#payment-reconciliation)
- [Testing](#testing)
- [Privacy: what is sent](#privacy-what-is-sent)
- [Configuration](#configuration)

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13 (Laravel 13 needs PHP 8.3+)

## Installation

Create an app in the Thud dashboard and copy its token, then:

```bash
composer require thud/laravel
php artisan thud:install --token=thd_your_token
```

`thud:install` publishes `config/thud.php`, saves `THUD_TOKEN` to `.env` and tests the connection. Run `php artisan thud:test` to test it again at any time.

Set `THUD_ENABLED=false` to turn Thud off completely: no listeners are registered and nothing is added to your app's work.

## Zero-config mode

With no code changes, Thud records every queued job, scheduled task and webhook request (routes using the `thud` middleware): rows inserted, updated and deleted, mails and notifications sent, outgoing HTTP calls, cache writes, jobs dispatched, duration and status. Writes to queue and framework tables (`jobs`, `cache`, `sessions`…) are ignored.

After 20 successful runs Thud knows what a normal run does. From then on, a run that **succeeds but does nothing**, or far less than usual, becomes a finding:

> `SendDailyDigest` succeeded but sent 0 mails (usually about 300)

A job that legitimately does nothing on some runs (a cleanup with nothing to clean) can be marked **May be empty** on its page in the dashboard.

Thud never gets in your way. Every hook is wrapped so it can't throw into your app, data is sent after the response (or after each job in a worker) with a 2-second timeout and no retries, and recording adds about 0.1 ms to a job running 100 queries.

## Expectations

For certainty, say what a successful run means, next to the code:

```php
use Thud\Expectations\Concerns\HasExpectations;
use Thud\Expectations\Expect;

class ActivateSubscription implements ShouldQueue
{
    use Dispatchable, Queueable, HasExpectations;

    public function __construct(public Order $order) {}

    public function handle(): void
    {
        // ...
    }

    public function expectations(): array
    {
        return [
            Expect::mailSent(WelcomeMail::class)->to($this->order->email),
            Expect::state(fn () => $this->order->subscription->status === 'active')
                ->within(seconds: 60)
                ->label('Subscription is active'),
        ];
    }
}
```

| Expectation | Passes when |
| --- | --- |
| `Expect::mailSent(Mailable::class)` | a mailable (or mail notification) of that class was sent |
| `->to($email)` | …to that address, compared as a hash inside your app |
| `Expect::notificationSent(Notification::class)` | a notification of that class was sent |
| `Expect::httpCalled('api.paystack.co')` | an HTTP call was made to that host |
| `->withStatus(200)` | …and one returned that status |
| `Expect::atLeast(10)->mailsSent(DigestMail::class)` | at least N mails (optionally of one class) were sent |
| `Expect::atLeast(1)->rowsWritten()` | at least N rows were inserted or updated |
| `->notificationsSent()`, `->httpCalls($host)`, `->jobsDispatched($job)` | other counts |
| `Expect::state(fn () => ...)` | the closure returns `true` after the run |
| `->within(seconds: 60)` | …checked 60 seconds later, by a queued `VerifyOutcome` job |

- Expectations are checked only after a successful run, and never break the job: one that throws is recorded as an error.
- Queries run by an expectation aren't counted as the job's side effects.
- Give each one a short `->label()`. Without one, Thud uses `ClassName::expectations()#index`.
- Put delayed checks on their own queue with `THUD_VERIFY_QUEUE`. If one hasn't reported 10 minutes after it was due, it's flagged as "verification never ran".

## Scheduled tasks

Every scheduled task is recorded and, once you run `php artisan thud:sync`, watched. Add it to your deploy script:

```bash
php artisan thud:sync   # --dry-run lists the tasks without sending them
```

Thud then tells you when a task **didn't run** within 10 minutes of when it was due (even if cron has stopped altogether, because the check runs on Thud's side) and when a task **failed** (the scheduler only logs its exception). Missed runs are worked out in each task's own timezone, so daylight-saving changes are handled.

```php
use Illuminate\Support\Facades\Schedule;
use Thud\Expectations\Expect;

Schedule::command('invoices:send')
    ->dailyAt('06:00')
    ->expectRun(graceMinutes: 30)   // allow 30 minutes instead of 10
    ->expect(Expect::atLeast(1)->mailsSent(InvoiceMail::class));

Schedule::command('cache:warm')->everyFiveMinutes()->dontWatch();
```

Tasks with `when()`, `skip()` or `between()` conditions, and sub-minute tasks, can skip runs on purpose, so they're only watched with `->expectRun()`. Set `THUD_WATCH_SCHEDULED_TASKS=false` to watch only tasks marked with `->expectRun()`. Artisan commands run in a separate process, and their expectations are checked in that process.

## Webhooks

Add the `thud` middleware to a webhook route:

```php
Route::post('/stripe/webhook', StripeWebhookController::class)
    ->middleware('thud:stripe');
```

Name the provider (`stripe`, `paystack`, `paddle`, `lemon_squeezy` or `flutterwave`) and Thud also reads the event's ID and type, and nothing else from the payload. Thud then tells you when your app answered an event with an error (resolved when the provider's retry succeeds) or processed the same event twice with writes both times.

## Payment reconciliation

Every night, `php artisan thud:reconcile` compares what your payment provider says happened with what your database says happened, and reports the gaps: payments that succeeded but aren't recorded, and subscriptions that are active at the provider but missing or inactive in your app. It runs inside your app with your own key. Thud receives references, customer IDs and timestamps only: never amounts, emails or card data. Reconciliation is part of the Team and Agency plans.

Map each provider to your own models in `config/thud.php`:

```php
'payments' => [
    'stripe' => [
        'key' => env('THUD_STRIPE_KEY'),
        'map' => [
            'payments' => [
                'model' => App\Models\Payment::class,
                'key' => 'stripe_payment_intent_id',
                'paid' => ['paid_at' => 'not_null'],
            ],
            'subscriptions' => [
                'model' => App\Models\Subscription::class,
                'key' => 'stripe_id',
                'active' => ['status' => 'active'],
            ],
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

The command runs daily at 03:15 once a provider has a key (`reconcile_at` in the config; `null` turns it off). Run it by hand with `php artisan thud:reconcile stripe --hours=48`. Each run checks the last 48 hours of payments and all active subscriptions; a run that passes 5 minutes stops and reports a partial check.

## Testing

Your expectations double as test assertions:

```php
use Thud\Facades\Thud;

it('activates the subscription', function () {
    Thud::fake();

    ActivateSubscription::dispatchSync($order);

    Thud::assertExpectationsMet(ActivateSubscription::class);
});
```

`Thud::fake()` records runs in memory and checks `within()` expectations immediately. Also available: `assertExpectationFailed($job, $label)`, `assertRunRecorded($job, fn ($run) => ...)`, `assertNothingRecorded()` and `Thud::runs()`.

Without the fake, Thud records in your test suite but never sends, so a `THUD_TOKEN` in `.env` can't pollute your dashboard (`send_in_tests` changes this).

## Privacy: what is sent

Thud is built so your customers' data never leaves your servers.

**Sent, for each run:** the job class, task or route name and queue; status, start time, duration and attempt; counts of rows written, mails, notifications, HTTP calls, cache writes and jobs dispatched; class names of mails, notifications and dispatched jobs; the **hosts** of HTTP calls with their status class; expectation labels with pass, fail or error and a short message such as "No WelcomeMail was sent"; for payment webhooks, the event ID, type and your app's response status.

**Sent, for payment reconciliation:** the provider, time window and number of records checked, and for each gap its type, the provider's reference, the customer reference and a timestamp.

**Never sent:** model attributes, query bindings, email addresses (compared as hashes), request or webhook payloads, URL paths or query strings, amounts, card details or names.

Expectations and reconciliation run inside your app, against your own database, with your own keys. Thud's servers never connect to your database or your payment provider.

## Configuration

| Key | Env | Default | |
| --- | --- | --- | --- |
| `enabled` | `THUD_ENABLED` | `true` | `false` registers nothing at all |
| `token` | `THUD_TOKEN` | | Your app token |
| `endpoint` | `THUD_ENDPOINT` | `https://ingest.thud.dev/v1` | |
| `environment` | `THUD_ENV` | `APP_ENV` | Shown in the dashboard |
| `record.jobs` / `.scheduled` / `.webhooks` | | `true` | Turn recording off by type |
| `ignore_tables` | | queue and framework tables | Writes to these never count |
| `ignore_jobs` | | `[]` | Job classes to skip entirely |
| `send_in_tests` | | `false` | Send data while your test suite runs |
| `verify_queue` | `THUD_VERIFY_QUEUE` | default queue | Queue for delayed checks |
| `watch_scheduled_tasks` | `THUD_WATCH_SCHEDULED_TASKS` | `true` | Watch every scheduled task for missed runs |
| `scheduled_grace_minutes` | | `10` | Default grace period for missed runs |
| `payments` | | `[]` | See [Payment reconciliation](#payment-reconciliation) |
| `reconcile_at` | | `03:15` | Nightly reconciliation time, or `null` |

| Command | |
| --- | --- |
| `thud:install --token=` | Publish the config, save the token, test the connection |
| `thud:test` | Test the connection |
| `thud:sync` | Send your scheduled tasks so missed runs are caught (run on deploy) |
| `thud:reconcile {provider?} --hours=48` | Reconcile payments |

## Development

```bash
composer test      # Pest
composer analyse   # PHPStan level 8
composer lint      # Pint (check only); `composer format` to fix
```

## License

MIT. See [LICENSE.md](LICENSE.md).
