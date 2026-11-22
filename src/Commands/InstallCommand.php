<?php

declare(strict_types=1);

namespace Thud\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Thud\Transport\Client;
use Thud\Transport\Transport;

/**
 * php artisan thud:install: publishes the config, writes THUD_TOKEN to .env and checks the
 * connection.
 */
final class InstallCommand extends Command
{
    protected $signature = 'thud:install {--token= : Your app token (starts with thd_)} {--force : Overwrite an existing config file}';

    protected $description = 'Install Thud: publish the config, save your token and test the connection';

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'thud-config', '--force' => (bool) $this->option('force')]);

        $token = $this->option('token');

        if (! is_string($token) || $token === '') {
            $token = $this->input->isInteractive() ? (string) $this->secret('Your Thud app token (starts with thd_)') : '';
        }

        if ($token === '') {
            $this->components->warn('No token given. Add THUD_TOKEN to your .env, then run `php artisan thud:test`.');

            return self::SUCCESS;
        }

        if (preg_match('/^thd_[A-Za-z0-9]+$/', $token) !== 1) {
            $this->components->error('That doesn\'t look like a Thud token: tokens start with thd_.');

            return self::FAILURE;
        }

        $this->writeEnv($files, 'THUD_TOKEN', $token);
        config(['thud.token' => $token]);
        app()->forgetInstance(Client::class);
        app()->forgetInstance(Transport::class);

        $this->components->info('Saved THUD_TOKEN to .env.');

        return $this->call('thud:test');
    }

    private function writeEnv(Filesystem $files, string $key, string $value): void
    {
        $path = app()->environmentFilePath();
        $contents = $files->exists($path) ? $files->get($path) : '';
        $line = "{$key}={$value}";
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        // A callback, not a replacement string, so $ or \ in the value are written as they are.
        $contents = preg_match($pattern, $contents) === 1
            ? (string) preg_replace_callback($pattern, static fn (): string => $line, $contents)
            : rtrim($contents)."\n\n{$line}\n";

        $files->put($path, ltrim($contents));
    }
}
