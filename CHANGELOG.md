# Changelog

All notable changes to `thud/laravel` are documented here. This project follows [Semantic Versioning](https://semver.org).

## v0.4.0 - 2026-11-28

- Each run records the tables it wrote to, with how many inserts, updates and deletes (`breakdown.tables`, at most 50 tables). Table names only, never values. The dashboard uses them to say what a normal run writes.
- Payment reconciliation sends the shape of your mapping (model class, key column and configured conditions), so a payment gap can name the record your app was expected to have.

## v0.3.0 - 2026-11-24

- A failed run sends its exception's class name (such as `TypeError`), never its message or stack trace. This includes webhooks whose crash Laravel turned into a 500 response.
- Each queued job carries the ID of the run that queued it (`thud_parent_run_id` in the job payload), and its own run sends it as `parent_run_id`, so the dashboard can link parent and child.
- A job run inline by the `sync` driver counts as a job dispatched by the run that dispatched it, as it would on a real queue.

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
