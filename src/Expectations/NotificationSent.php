<?php

declare(strict_types=1);

namespace Thud\Expectations;

use Thud\Expectations\Concerns\Labelled;
use Thud\Recording\RunContext;

/**
 * During-run check: a notification of the given class was sent.
 */
final class NotificationSent implements Expectation
{
    use Labelled;

    public function __construct(private readonly string $notification) {}

    public function evaluate(RunContext $run): Result
    {
        return Result::when(
            $run->counter->notificationCount($this->notification) > 0,
            'No '.class_basename($this->notification).' was sent',
        );
    }
}
