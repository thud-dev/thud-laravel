<?php

declare(strict_types=1);

namespace Thud\Commands;

use Illuminate\Console\Command;
use Thud\Transport\Transport;

/**
 * php artisan thud:test: checks the token against the ingest API.
 */
final class TestCommand extends Command
{
    protected $signature = 'thud:test';

    protected $description = 'Check that this app can reach Thud';

    public function handle(Transport $transport): int
    {
        if (! (bool) config('thud.enabled', true)) {
            $this->components->warn('Thud is disabled (THUD_ENABLED=false). Nothing is recorded or sent.');
        }

        $ping = $transport->ping();

        if (! $ping['ok']) {
            $this->components->error($ping['error'] ?? 'Could not connect to Thud.');

            return self::FAILURE;
        }

        $app = $ping['app'] ?? 'your app';
        $environment = $ping['environment'] ?? 'unknown environment';

        $this->components->info("Connected as {$app} ({$environment}). Runs will appear in your dashboard within a minute.");

        return self::SUCCESS;
    }
}
