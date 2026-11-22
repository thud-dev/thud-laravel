<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Thud\Tests\Disabled\DisabledTestCase;
use Thud\Tests\TestCase;
use Thud\Transport\Buffer;

uses(TestCase::class)->in('Feature');
uses(DisabledTestCase::class)->in('Disabled');

/**
 * Runs recorded so far: those already sent to the (faked) ingest API, then those still buffered.
 *
 * @return list<array<string, mixed>>
 */
function recordedRuns(): array
{
    $sent = [];

    foreach (ingestRequests() as $body) {
        array_push($sent, ...$body['runs']);
    }

    return [...$sent, ...app(Buffer::class)->all()];
}

/**
 * Decoded bodies of every request sent to the faked ingest API.
 *
 * @return list<array<string, mixed>>
 */
function ingestRequests(): array
{
    return Http::recorded(fn (Request $request) => str_starts_with($request->url(), 'https://ingest.thud.test/'))
        ->map(fn (array $pair) => json_decode((string) gzdecode($pair[0]->body()), true, flags: JSON_THROW_ON_ERROR))
        ->values()
        ->all();
}

/**
 * The single recorded run with the given name.
 *
 * @return array<string, mixed>
 */
function recordedRun(string $name): array
{
    $runs = array_values(array_filter(recordedRuns(), fn (array $run): bool => $run['name'] === $name));

    expect($runs)->toHaveCount(1, "Expected exactly one run named {$name}");

    return $runs[0];
}
