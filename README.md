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

## Development

```bash
composer test      # Pest
composer analyse   # PHPStan level 8
composer lint      # Pint (check only); `composer format` to fix
```

## License

MIT. See [LICENSE.md](LICENSE.md).
