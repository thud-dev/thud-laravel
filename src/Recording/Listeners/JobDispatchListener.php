<?php

declare(strict_types=1);

namespace Thud\Recording\Listeners;

use Closure;
use Illuminate\Queue\Events\JobQueued;
use Thud\Recording\RunStack;

/**
 * Counts jobs pushed to a queue from the current run, per job class.
 */
final class JobDispatchListener
{
    public function __construct(private readonly RunStack $stack) {}

    public function handle(JobQueued $event): void
    {
        $this->stack->current()?->counter->jobDispatched(self::jobName($event->job));
    }

    private static function jobName(mixed $job): string
    {
        return match (true) {
            $job instanceof Closure => 'Closure',
            is_object($job) && method_exists($job, 'displayName') && is_string($name = $job->displayName()) => $name,
            is_object($job) => $job::class,
            is_string($job) => $job,
            default => 'unknown',
        };
    }
}
