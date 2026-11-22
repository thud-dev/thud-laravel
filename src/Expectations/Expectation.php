<?php

declare(strict_types=1);

namespace Thud\Expectations;

use Thud\Recording\RunContext;

/**
 * A developer-declared outcome of a run. Implementations must never throw out of evaluate():
 * a throwing check is recorded as an `error` result by the caller.
 */
interface Expectation
{
    /**
     * Human-readable label. Defaults to `Class::expectations()#index` when none is set.
     */
    public function label(): string;

    public function evaluate(RunContext $run): Result;
}
