<?php

declare(strict_types=1);

namespace Thud\Hooks;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Thud\Recording\RunContext;
use Thud\Recording\RunStack;
use Thud\Transport\Buffer;
use Thud\Transport\FlushOnTerminate;

/**
 * Starts a run on JobProcessing and ends it on JobProcessed / JobFailed / JobExceptionOccurred.
 *
 * A failing job can fire several end events (JobFailed then JobExceptionOccurred, or JobFailed
 * then JobProcessed); runs are matched to the queue job object, so only the first one counts.
 */
final class QueueHooks
{
    /** @var array<string, true> */
    private array $ignoredJobs;

    /**
     * @param  list<string>  $ignoredJobs
     */
    public function __construct(
        private readonly RunStack $stack,
        private readonly Buffer $buffer,
        private readonly FlushOnTerminate $flusher,
        array $ignoredJobs,
    ) {
        $this->ignoredJobs = array_fill_keys($ignoredJobs, true);
    }

    public function processing(JobProcessing $event): void
    {
        $job = $event->job;
        $name = $job->resolveName();

        $run = new RunContext(
            RunContext::TYPE_JOB,
            $name,
            owner: $job,
            recordable: ! isset($this->ignoredJobs[$name]),
        );
        $run->queue = $job->getQueue();
        $run->attempt = max(1, $job->attempts());

        $this->stack->push($run);
    }

    public function processed(JobProcessed $event): void
    {
        $this->end($event->job, match (true) {
            $event->job->hasFailed() => RunContext::STATUS_FAILED,
            // Released back onto the queue without failing (rate limited, overlapping...):
            // the work hasn't happened yet, so it is not judged as a success.
            $event->job->isReleased() => RunContext::STATUS_SKIPPED,
            default => RunContext::STATUS_SUCCEEDED,
        });
    }

    public function failed(JobFailed $event): void
    {
        $this->end($event->job, RunContext::STATUS_FAILED);
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->end($event->job, RunContext::STATUS_FAILED);
    }

    private function end(Job $job, string $status): void
    {
        $run = $this->stack->popOwnedBy($job);

        if ($run === null) {
            return;
        }

        $run->finish($status);

        if ($run->recordable) {
            $this->buffer->add($run->toPayload());
        }

        $this->flusher->runFinished();
    }
}
