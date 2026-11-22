<?php

declare(strict_types=1);

namespace Thud\Expectations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;
use Thud\Expectations\Concerns\HasExpectations;
use Thud\Recording\RunContext;
use Thud\Schedule\ScheduleRegistry;
use Thud\Transport\Transport;

/**
 * Evaluates one within(n) state check n seconds after its run, and reports the result for the
 * same run_id (POST /v1/runs/{run_id}/results).
 *
 * For a queued job it rebuilds the job from its serialized form and calls expectations() again;
 * for a scheduled task it finds the task by mutex name. Never recorded as a run itself.
 */
final class VerifyOutcome implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private function __construct(
        public readonly string $runId,
        public readonly string $label,
        public readonly int $index,
        public readonly ?string $serializedJob = null,
        public readonly ?string $scheduleMutex = null,
    ) {}

    public static function forJob(string $runId, string $label, int $index, object $job): self
    {
        return new self($runId, $label, $index, serializedJob: serialize($job));
    }

    public static function forSchedule(string $runId, string $label, int $index, string $mutex): self
    {
        return new self($runId, $label, $index, scheduleMutex: $mutex);
    }

    public function handle(Transport $transport, ScheduleRegistry $registry): void
    {
        try {
            $expectation = $this->expectation($registry);
            $result = $expectation === null
                ? Result::error('Expectation no longer exists')
                : Evaluator::check($expectation, new RunContext(RunContext::TYPE_JOB, 'verify', recordable: false));
        } catch (Throwable $e) {
            $result = Result::error('Could not rebuild the job: '.class_basename($e));
        }

        $transport->sendResults($this->runId, [$result->toPayload($this->label)]);
    }

    private function expectation(ScheduleRegistry $registry): ?Expectation
    {
        if ($this->serializedJob !== null) {
            $job = unserialize($this->serializedJob);

            if (! is_object($job) || ! in_array(HasExpectations::class, class_uses_recursive($job), true) || ! method_exists($job, 'expectations')) {
                return null;
            }

            $expectations = array_values($job->expectations());
        } else {
            $event = $registry->findByMutex((string) $this->scheduleMutex);
            $expectations = $event === null ? [] : $registry->expectationsFor($event);
        }

        $expectation = $expectations[$this->index] ?? null;

        return $expectation instanceof Expectation ? $expectation : null;
    }
}
