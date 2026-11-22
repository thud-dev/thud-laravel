<?php

declare(strict_types=1);

namespace Thud\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert as PHPUnit;
use Thud\Transport\Buffer;
use Thud\Transport\Transport;

/**
 * Replaces the ingest API under Thud::fake(): runs and results are kept in memory, state checks
 * with within() are evaluated immediately, and nothing leaves the test.
 *
 *     Thud::fake();
 *     ActivateSubscription::dispatchSync($order);
 *     Thud::assertExpectationsMet(ActivateSubscription::class);
 */
final class ThudFake implements Transport
{
    /** @var list<array<string, mixed>> */
    private array $sentRuns = [];

    /** @var array<string, list<array{label: string, result: string, message?: string}>> */
    private array $results = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $schedule = null;

    public function __construct(private readonly Container $container) {}

    public function enabled(): bool
    {
        return (bool) $this->container->make('config')->get('thud.enabled', true);
    }

    /**
     * Moves buffered runs into the fake, as a real flush would send them.
     */
    public function flush(): void
    {
        if ($this->container->bound(Buffer::class)) {
            $this->sendRuns($this->container->make(Buffer::class)->drain());
        }
    }

    // ---- Transport ------------------------------------------------------------------------------

    public function sendRuns(array $runs): void
    {
        array_push($this->sentRuns, ...$runs);
    }

    public function sendResults(string $runId, array $results): void
    {
        $this->results[$runId] = [...($this->results[$runId] ?? []), ...$results];
    }

    public function sendSchedule(array $tasks): bool
    {
        $this->schedule = $tasks;

        return true;
    }

    public function ping(): array
    {
        return ['ok' => true, 'app' => 'Fake app', 'environment' => 'testing'];
    }

    // ---- Inspection -----------------------------------------------------------------------------

    /**
     * Every run recorded so far, sent or still buffered, with late within() results applied.
     *
     * @return list<RecordedRun>
     */
    public function runs(?string $name = null): array
    {
        $buffered = $this->container->bound(Buffer::class) ? $this->container->make(Buffer::class)->all() : [];
        $runs = [];

        foreach ([...$this->sentRuns, ...$buffered] as $payload) {
            if ($name !== null && $payload['name'] !== $name) {
                continue;
            }

            $runs[] = new RecordedRun($this->withLateResults($payload));
        }

        return $runs;
    }

    /**
     * @return list<array<string, mixed>>|null the tasks sent by thud:sync, if any
     */
    public function syncedSchedule(): ?array
    {
        return $this->schedule;
    }

    // ---- Assertions -----------------------------------------------------------------------------

    /**
     * @param  (Closure(RecordedRun): bool)|null  $callback
     */
    public function assertRunRecorded(string $name, ?Closure $callback = null): void
    {
        $runs = $this->runs($name);

        PHPUnit::assertNotEmpty($runs, "No run of [{$name}] was recorded.");

        if ($callback !== null) {
            PHPUnit::assertTrue(
                array_filter($runs, $callback) !== [],
                "No run of [{$name}] matched the given callback.",
            );
        }
    }

    public function assertNothingRecorded(): void
    {
        PHPUnit::assertSame([], $this->runs(), 'Runs were recorded unexpectedly.');
    }

    /**
     * The latest run of $name declared expectations, and every one of them passed.
     */
    public function assertExpectationsMet(string $name): void
    {
        $run = $this->latest($name);
        $expectations = $run->expectations();

        PHPUnit::assertNotEmpty($expectations, "[{$name}] declared no expectations, or its run didn't succeed.");

        $unmet = array_filter($expectations, static fn (array $e): bool => $e['result'] !== 'passed');

        PHPUnit::assertSame([], array_values($unmet), "Not every expectation of [{$name}] was met:\n".self::describe($unmet));
    }

    public function assertExpectationFailed(string $name, string $label): void
    {
        $result = $this->latest($name)->expectation($label);

        PHPUnit::assertNotNull($result, "[{$name}] has no expectation labelled [{$label}].");
        PHPUnit::assertContains($result, ['failed', 'error'], "Expected [{$label}] to fail, but it {$result}.");
    }

    private function latest(string $name): RecordedRun
    {
        $runs = $this->runs($name);
        $latest = array_pop($runs);

        if ($latest === null) {
            PHPUnit::fail("No run of [{$name}] was recorded.");
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withLateResults(array $payload): array
    {
        $late = $this->results[(string) $payload['run_id']] ?? [];

        if ($late === []) {
            return $payload;
        }

        $expectations = (array) ($payload['expectations'] ?? []);

        foreach ($late as $result) {
            foreach ($expectations as $i => $expectation) {
                if (is_array($expectation) && ($expectation['label'] ?? null) === $result['label']) {
                    $expectations[$i] = $result;
                }
            }
        }

        $payload['expectations'] = array_values($expectations);

        return $payload;
    }

    /**
     * @param  array<array-key, array{label: string, result: string, message?: string}>  $expectations
     */
    private static function describe(array $expectations): string
    {
        return implode("\n", array_map(
            static fn (array $e): string => "- {$e['label']}: {$e['result']}".(isset($e['message']) ? " ({$e['message']})" : ''),
            $expectations,
        ));
    }
}
