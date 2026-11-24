<?php

declare(strict_types=1);

namespace Thud\Hooks;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Throwable;
use Thud\Expectations\Concerns\HasExpectations;
use Thud\Expectations\Evaluator;
use Thud\Expectations\VerifyOutcome;
use Thud\Recording\RunContext;
use Thud\Recording\RunStack;
use Thud\Support\Safely;
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
        private readonly Evaluator $evaluator,
        private readonly Container $container,
        array $ignoredJobs,
    ) {
        $this->ignoredJobs = array_fill_keys($ignoredJobs, true);
    }

    /** The key Thud adds to every queued job's payload, holding the run that queued it. */
    public const PARENT_KEY = 'thud_parent_run_id';

    public function processing(JobProcessing $event): void
    {
        $job = $event->job;
        $name = $job->resolveName();

        // The sync driver never fires JobQueued: a job it runs inline was dispatched by the run
        // that's in flight, so count it there, as a real queue would.
        if ($job instanceof SyncJob) {
            $this->stack->current()?->counter->jobDispatched($name);
        }

        $run = new RunContext(
            RunContext::TYPE_JOB,
            $name,
            owner: $job,
            recordable: ! isset($this->ignoredJobs[$name]),
        );
        $run->queue = $job->getQueue();
        $run->attempt = max(1, $job->attempts());
        $parent = $job->payload()[self::PARENT_KEY] ?? null;
        $run->parentRunId = is_string($parent) && $parent !== '' ? substr($parent, 0, 26) : null;

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
        $this->end($event->job, RunContext::STATUS_FAILED, $event->exception);
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->end($event->job, RunContext::STATUS_FAILED, $event->exception);
    }

    /**
     * Payload hook for every queued job: the ID of the run that's queuing it, if any.
     *
     * @return array<string, string>
     */
    public static function parentPayload(RunStack $stack): array
    {
        $run = $stack->current();

        return $run === null ? [] : [self::PARENT_KEY => $run->runId];
    }

    private function end(Job $job, string $status, ?Throwable $exception = null): void
    {
        $run = $this->stack->popOwnedBy($job);

        if ($run === null) {
            return;
        }

        $run->failWith($exception);

        if ($status === RunContext::STATUS_SUCCEEDED && $run->recordable) {
            $this->evaluateExpectations($run, $job);
        }

        $run->finish($status);

        if ($run->recordable) {
            $this->buffer->add($run->toPayload());
        }

        $this->flusher->runFinished();
    }

    /**
     * Runs the job's expectations() if its class uses HasExpectations. Expectations are only
     * evaluated after a successful run: a failed job is already visible elsewhere.
     */
    private function evaluateExpectations(RunContext $run, Job $job): void
    {
        $command = Safely::run(fn () => $this->command($job), key: 'expectations');

        if ($command === null) {
            return;
        }

        $expectations = Safely::run(static function () use ($command): mixed {
            $declare = [$command, 'expectations'];

            return is_callable($declare) ? $declare() : [];
        }, [], 'expectations');

        if (! is_array($expectations) || $expectations === []) {
            return;
        }

        $this->evaluator->evaluate($run, $expectations, $command::class, function (int $index, string $label, int $delay) use ($run, $command): void {
            dispatch(VerifyOutcome::forJob($run->runId, $label, $index, $command)
                ->onQueue(ScheduleHooks::verifyQueue())
                ->delay($delay));
        });
    }

    /**
     * The job instance, rebuilt from its payload, when its class declares expectations.
     */
    private function command(Job $job): ?object
    {
        $class = $job->payload()['data']['commandName'] ?? null;

        if (! is_string($class) || ! class_exists($class) || ! in_array(HasExpectations::class, class_uses_recursive($class), true)) {
            return null;
        }

        $serialized = $job->payload()['data']['command'] ?? null;

        if (! is_string($serialized)) {
            return null;
        }

        // ShouldBeEncrypted jobs store the command encrypted. The encrypter is only resolved
        // here, so apps without an APP_KEY still record everything else.
        if (! str_starts_with($serialized, 'O:')) {
            $serialized = $this->container->make(Encrypter::class)->decrypt($serialized);
        }

        $command = is_string($serialized) ? unserialize($serialized) : null;

        return is_object($command) && method_exists($command, 'expectations') ? $command : null;
    }
}
