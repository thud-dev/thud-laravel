<?php

declare(strict_types=1);

namespace Thud\Testing;

/**
 * A run as Thud would have sent it, for assertions in tests.
 */
final class RecordedRun
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $payload) {}

    public function name(): string
    {
        return (string) $this->payload['name'];
    }

    public function status(): string
    {
        return (string) $this->payload['status'];
    }

    public function metric(string $metric): int
    {
        $metrics = (array) ($this->payload['metrics'] ?? []);

        return (int) ($metrics[$metric] ?? 0);
    }

    /**
     * @return list<array{label: string, result: string, message?: string, due_at?: string}>
     */
    public function expectations(): array
    {
        /** @var list<array{label: string, result: string, message?: string, due_at?: string}> */
        return array_values((array) ($this->payload['expectations'] ?? []));
    }

    /**
     * The result of the expectation with this label: passed, failed, error, pending, or null.
     */
    public function expectation(string $label): ?string
    {
        foreach ($this->expectations() as $expectation) {
            if ($expectation['label'] === $label) {
                return $expectation['result'];
            }
        }

        return null;
    }
}
