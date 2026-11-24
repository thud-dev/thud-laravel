# Changelog

All notable changes to `thud/laravel` are documented here. This project follows [Semantic Versioning](https://semver.org).

## v0.2.2 - 2026-11-24

- `thud:sync` warns about unnamed scheduled closures. They're named after their file and line, so moving the code loses their history and can look like a missed run; add `->name('...')` to each.

## v0.2.1 - 2026-11-22

- The copy of a job queued for a delayed `within()` check is now encrypted with the app key, even when the job itself isn't encrypted, and can't be tampered with on the queue. Delayed checks queued by v0.2.0 report as errors after upgrading.
- `thud:install` only accepts tokens made of `thd_` plus letters and digits, and writes `.env` without a regex replacement.
- README notes how sending affects webhook requests and that delayed checks need `APP_KEY`.

## v0.2.0 - 2026-11-22

- Supports Laravel 9 and 10, and PHP 8.1.
- Requires `guzzlehttp/guzzle` directly, since Laravel 9 doesn't install it.
- On Laravel 9 and 10, mail is counted per mailable class (those versions don't record the class themselves).

## v0.1.0 - 2026-11-22

First release.

- Records every queued job, scheduled task and webhook with no code changes: rows written, mails, notifications, HTTP calls, cache writes, jobs dispatched, duration and status.
- Expectations (`Expect::mailSent()`, `Expect::state()->within()`, `Expect::atLeast()` and more) checked after each successful run.
- Watches every scheduled task for missed and failed runs once you run `thud:sync`; opt out with `->dontWatch()`.
- `thud` middleware for webhooks, which reads the event ID and type for Stripe, Paystack, Paddle, Lemon Squeezy and Flutterwave.
- Nightly payment reconciliation (`thud:reconcile`) for Stripe, Paystack, Paddle, Lemon Squeezy and Flutterwave.
- `Thud::fake()` and assertions for your test suite.
- `thud:install`, `thud:test`, `thud:sync` and `thud:reconcile` commands.
