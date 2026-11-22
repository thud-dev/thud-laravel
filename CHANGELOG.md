# Changelog

All notable changes to `thud/laravel` are documented here. This project follows [Semantic Versioning](https://semver.org).

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
