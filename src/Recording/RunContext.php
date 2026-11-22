<?php

declare(strict_types=1);

namespace Thud\Recording;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One in-flight run: a queued job, a scheduled task or a webhook request.
 */
final class RunContext
{
    public const TYPE_JOB = 'job';

    public const TYPE_SCHEDULED = 'scheduled';

    public const TYPE_WEBHOOK = 'webhook';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public readonly string $runId;

    public readonly CarbonImmutable $startedAt;

    public readonly SideEffectCounter $counter;

    public string $status = self::STATUS_SUCCEEDED;

    public ?string $queue = null;

    public int $attempt = 1;

    /** @var array{provider: string, id: string, type: string|null}|null */
    public ?array $providerEvent = null;

    public ?int $httpStatus = null;

    /** @var list<array{label: string, result: string, message?: string, due_at?: string}> */
    public array $expectations = [];

    private readonly int $startedAtNs;

    private ?int $durationMs = null;

    /**
     * @param  object|null  $owner  The object that started this run (queue job, scheduled event, request),
     *                              used to close the right context when nested runs end out of order.
     * @param  bool  $recordable  false for ignored runs: they still absorb side effects so a parent
     *                            run isn't inflated, but they are never sent.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly ?object $owner = null,
        public readonly bool $recordable = true,
        ?string $runId = null,
    ) {
        $this->runId = $runId ?? (string) Str::ulid();
        $this->startedAt = CarbonImmutable::now('UTC');
        $this->startedAtNs = hrtime(true);
        $this->counter = new SideEffectCounter;
    }

    public function finish(string $status): void
    {
        if ($this->durationMs !== null) {
            return;
        }

        $this->status = $status;
        $this->durationMs = (int) round((hrtime(true) - $this->startedAtNs) / 1_000_000);
    }

    public function isFinished(): bool
    {
        return $this->durationMs !== null;
    }

    public function metric(string $metric): int
    {
        return $this->counter->metric($metric);
    }

    /**
     * The run as sent to POST /v1/runs (see "Ingest API contract" in docs/BUILD.md).
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'run_id' => $this->runId,
            'type' => $this->type,
            'name' => $this->name,
            'queue' => $this->queue,
            'status' => $this->status,
            'started_at' => $this->startedAt->format('Y-m-d\TH:i:s.v\Z'),
            'duration_ms' => $this->durationMs ?? 0,
            'attempt' => $this->attempt,
            'metrics' => $this->counter->metrics(),
            'breakdown' => (object) $this->counter->breakdown(),
            'expectations' => $this->expectations,
            'provider_event' => $this->providerEvent,
        ];

        if ($this->type === self::TYPE_WEBHOOK) {
            $payload['http_status'] = $this->httpStatus;
        }

        return $payload;
    }
}
