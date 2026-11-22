<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Thud\Recording\RunContext;
use Thud\Recording\RunStack;
use Thud\Tests\Fixtures\Jobs\ChildJob;
use Thud\Transport\Buffer;
use Thud\Transport\Client;
use Thud\Transport\FlushOnTerminate;

function fakeRun(string $name = 'App\\Jobs\\Example'): array
{
    $run = new RunContext('job', $name);
    $run->finish('succeeded');

    return $run->toPayload();
}

function simulateWorkerLoop(): void
{
    event(new Looping('database', 'default', new WorkerOptions));
}

it('posts gzipped JSON with the bearer token to /v1/runs', function (): void {
    app(Client::class)->sendRuns([fakeRun()]);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode((string) gzdecode($request->body()), true);

        return $request->url() === 'https://ingest.thud.test/v1/runs'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Content-Encoding', 'gzip')
            && $request->hasHeader('Content-Type', 'application/json')
            && array_keys($body) === ['package_version', 'environment', 'host', 'runs']
            && $body['environment'] === 'testing'
            && count($body['runs']) === 1;
    });
});

it('sends at most 100 runs per request', function (): void {
    app(Client::class)->sendRuns(array_map(fn () => fakeRun(), range(1, 250)));

    expect(array_map(fn (array $body) => count($body['runs']), ingestRequests()))->toBe([100, 100, 50]);
});

it('sends nothing without a token', function (): void {
    (new Client(app(Factory::class), null, 'https://ingest.thud.test/v1', 'production'))->sendRuns([fakeRun()]);

    expect(ingestRequests())->toBe([]);
});

it('sends nothing while the host app runs its test suite', function (): void {
    (new Client(app(Factory::class), 'token', 'https://ingest.thud.test/v1', 'testing', runningTests: true))->sendRuns([fakeRun()]);

    expect(ingestRequests())->toBe([]);
});

it('drops the batch on an error response and logs at most once per 10 minutes', function (): void {
    Http::fake(['ingest-down.thud.test/*' => Http::response('down', 500)]);
    Log::spy();

    $client = new Client(app(Factory::class), 'test-token', 'https://ingest-down.thud.test/v1', 'testing');
    $client->sendRuns([fakeRun()]);
    $client->sendRuns([fakeRun()]);

    Log::shouldHaveReceived('debug')->once();
});

it('flushes right after each job in a queue worker', function (): void {
    simulateWorkerLoop();

    ChildJob::dispatch();

    expect(app(Buffer::class)->count())->toBe(0)
        ->and(ingestRequests())->toHaveCount(1)
        ->and(ingestRequests()[0]['runs'][0]['name'])->toBe(ChildJob::class);
});

it('keeps runs buffered until the app terminates outside a worker', function (): void {
    ChildJob::dispatch();

    expect(app(Buffer::class)->count())->toBe(1)
        ->and(ingestRequests())->toBe([]);

    app()->terminate();

    expect(app(Buffer::class)->count())->toBe(0)
        ->and(ingestRequests())->toHaveCount(1);
});

it('flushes a console process once 50 runs are waiting', function (): void {
    foreach (range(1, 49) as $i) {
        ChildJob::dispatch();
    }

    expect(ingestRequests())->toBe([]);

    ChildJob::dispatch();

    expect(ingestRequests())->toHaveCount(1)
        ->and(ingestRequests()[0]['runs'])->toHaveCount(50);
});

it('never counts its own ingest call as a side effect', function (): void {
    $stack = app(RunStack::class);
    $run = new RunContext('job', 'InFlight');
    $stack->push($run);

    app(Buffer::class)->add(fakeRun());
    app(FlushOnTerminate::class)->flush();

    expect(ingestRequests())->toHaveCount(1)
        ->and($run->metric('http_out'))->toBe(0);
});

it('caps the buffer at 500 runs', function (): void {
    $buffer = new Buffer;

    foreach (range(1, 510) as $i) {
        $buffer->add(fakeRun());
    }

    expect($buffer->count())->toBe(500)
        ->and($buffer->dropped())->toBe(10);
});
