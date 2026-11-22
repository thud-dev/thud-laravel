<?php

declare(strict_types=1);

namespace Thud\Expectations;

use Closure;
use Thud\Expectations\Concerns\Labelled;
use Thud\Recording\RunContext;

/**
 * A closure that must return true. Evaluated right after the run, or with ->within(seconds: n)
 * n seconds later by a queued VerifyOutcome job (for state that settles asynchronously).
 */
final class StateCheck implements Expectation
{
    use Labelled;

    private int $delaySeconds = 0;

    /**
     * @param  Closure(): mixed  $check
     */
    public function __construct(private readonly Closure $check) {}

    public function within(int $seconds): self
    {
        $this->delaySeconds = max(0, $seconds);

        return $this;
    }

    public function delaySeconds(): int
    {
        return $this->delaySeconds;
    }

    public function evaluate(RunContext $run): Result
    {
        $result = ($this->check)();

        if (! is_bool($result)) {
            return Result::error('State check must return true or false');
        }

        return Result::when($result, 'State check returned false');
    }
}
