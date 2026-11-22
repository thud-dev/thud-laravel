<?php

declare(strict_types=1);

namespace Thud\Expectations;

use LogicException;
use Thud\Expectations\Concerns\Labelled;
use Thud\Recording\RunContext;

/**
 * During-run check: a side effect happened at least N times.
 *
 *     Expect::atLeast(10)->mailsSent(DigestMail::class)
 *     Expect::atLeast(1)->rowsWritten()
 */
final class AtLeast implements Expectation
{
    use Labelled;

    private ?string $what = null;

    private ?string $filter = null;

    public function __construct(private readonly int $minimum) {}

    public function mailsSent(?string $mailable = null): self
    {
        return $this->counting('mails', $mailable);
    }

    public function notificationsSent(?string $notification = null): self
    {
        return $this->counting('notifications', $notification);
    }

    public function httpCalls(?string $host = null): self
    {
        return $this->counting('http calls', $host);
    }

    public function jobsDispatched(?string $job = null): self
    {
        return $this->counting('jobs', $job);
    }

    /**
     * Inserted or updated rows (deletes don't count).
     */
    public function rowsWritten(): self
    {
        return $this->counting('rows');
    }

    public function evaluate(RunContext $run): Result
    {
        $counter = $run->counter;

        $count = match ($this->what) {
            'mails' => $this->filter === null ? $counter->metric('mail_sent') : $counter->mailCount($this->filter),
            'notifications' => $this->filter === null ? $counter->metric('notification_sent') : $counter->notificationCount($this->filter),
            'http calls' => $counter->httpCount($this->filter),
            'jobs' => $counter->jobCount($this->filter),
            'rows' => $counter->metric('db_insert') + $counter->metric('db_update'),
            default => throw new LogicException('Say what to count, e.g. Expect::atLeast(1)->mailsSent().'),
        };

        $subject = $this->filter === null ? $this->what : class_basename($this->filter).' '.$this->what;

        return Result::when($count >= $this->minimum, "Expected at least {$this->minimum} {$subject}, got {$count}");
    }

    private function counting(string $what, ?string $filter = null): self
    {
        $this->what = $what;
        $this->filter = $filter;

        return $this;
    }
}
