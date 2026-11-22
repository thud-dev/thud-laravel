<?php

declare(strict_types=1);

namespace Thud\Transport;

use Composer\InstalledVersions;
use Illuminate\Http\Client\Factory as Http;
use Throwable;
use Thud\Support\Safely;

/**
 * Sends finished runs to the ingest API.
 *
 * Gzip JSON, bearer token, a 2-second timeout and no retries: on any failure the batch is
 * dropped and a debug line is logged at most once per 10 minutes. Never throws.
 */
final class Client
{
    public const TIMEOUT_SECONDS = 2;

    public const MAX_RUNS_PER_REQUEST = 100;

    public function __construct(
        private readonly Http $http,
        private readonly ?string $token,
        private readonly string $endpoint,
        private readonly ?string $environment,
        private readonly bool $runningTests = false,
    ) {}

    public function canSend(): bool
    {
        return $this->token !== null && $this->token !== '' && ! $this->runningTests;
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    public function sendRuns(array $runs): void
    {
        if ($runs === [] || ! $this->canSend()) {
            return;
        }

        foreach (array_chunk($runs, self::MAX_RUNS_PER_REQUEST) as $chunk) {
            $this->post('/runs', [
                'package_version' => self::packageVersion(),
                'environment' => $this->environment,
                'host' => ($host = gethostname()) !== false ? $host : null,
                'runs' => $chunk,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body): void
    {
        try {
            $gzipped = gzencode(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            if ($gzipped === false) {
                Safely::log('transport', 'Could not gzip the payload; batch dropped.');

                return;
            }

            $response = $this->http->createPendingRequest()
                ->withToken((string) $this->token)
                ->withHeaders(['Content-Encoding' => 'gzip', 'Accept' => 'application/json'])
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withBody($gzipped, 'application/json')
                ->post(rtrim($this->endpoint, '/').$path);

            if ($response->failed()) {
                Safely::log('transport', "Ingest returned HTTP {$response->status()}; batch dropped.");
            }
        } catch (Throwable $e) {
            Safely::log('transport', "Ingest request failed ({$e->getMessage()}); batch dropped.");
        }
    }

    public static function packageVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('thud/laravel') ?? 'dev';
        } catch (Throwable) {
            return 'dev';
        }
    }
}
