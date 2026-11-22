<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Thud\Recording\Listeners\QueryListener;
use Thud\Recording\RunStack;
use Thud\Tests\Fixtures\Jobs\ChildJob;
use Thud\Transport\Client;

/*
 * The host job must still succeed, and finish within 2.5 s of its normal time, whatever state
 * the ingest API is in. These tests use real network calls (no Http::fake for the endpoint).
 */

function useIngestEndpoint(string $endpoint): void
{
    app()->instance(Client::class, new Client(app(Factory::class), 'test-token', $endpoint, 'testing'));
}

/**
 * Runs a job in a (simulated) queue worker, which flushes right after the job, and returns the
 * wall time in seconds.
 */
function timeJobInWorker(): float
{
    event(new Looping('database', 'default', new WorkerOptions));

    $start = hrtime(true);
    ChildJob::dispatch();

    return (hrtime(true) - $start) / 1e9;
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
    fclose($socket);

    return $port;
}

it('keeps the job fast when the ingest API is down', function (): void {
    useIngestEndpoint('http://127.0.0.1:'.freePort().'/v1');

    expect(timeJobInWorker())->toBeLessThan(2.5);
});

it('gives up after 2 seconds when the ingest API hangs', function (): void {
    $port = freePort();
    $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/../Fixtures/slow-ingest.php']);
    $server->start();

    try {
        $deadline = microtime(true) + 5;
        while (! @fsockopen('127.0.0.1', $port) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        useIngestEndpoint("http://127.0.0.1:{$port}/v1");

        $elapsed = timeJobInWorker();

        expect($elapsed)->toBeGreaterThan(1.5) // proves the slow server was really hit
            ->toBeLessThan(2.5);
    } finally {
        $server->stop(0);
    }
});

it('keeps the job succeeding when the ingest API returns 500', function (): void {
    Http::fake(['ingest-down.thud.test/*' => Http::response('oops', 500)]);
    useIngestEndpoint('https://ingest-down.thud.test/v1');

    expect(timeJobInWorker())->toBeLessThan(2.5)
        ->and(Http::recorded())->toHaveCount(1)
        ->and(json_decode((string) gzdecode(Http::recorded()[0][0]->body()), true)['runs'][0]['status'])->toBe('succeeded');
});

it('keeps the host app working when a listener blows up', function (): void {
    app()->instance(RunStack::class, null);
    app()->forgetInstance(QueryListener::class);

    ChildJob::dispatch();

    expect(DB::table('orders')->count())->toBe(2);
});
