<?php

declare(strict_types=1);

namespace Thud\Expectations;

use Thud\Expectations\Concerns\Labelled;
use Thud\Recording\RunContext;

/**
 * During-run check: an outgoing HTTP call was made to the given host, optionally answered with a
 * given status code.
 */
final class HttpCalled implements Expectation
{
    use Labelled;

    private ?int $status = null;

    public function __construct(private readonly string $host) {}

    public function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function evaluate(RunContext $run): Result
    {
        if ($run->counter->httpCount($this->host) === 0) {
            return Result::failed("No HTTP call was made to {$this->host}");
        }

        return Result::when(
            $this->status === null || $run->counter->httpCount($this->host, $this->status) > 0,
            "No call to {$this->host} returned {$this->status}",
        );
    }
}
