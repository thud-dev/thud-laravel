<?php

declare(strict_types=1);

namespace Thud\Expectations;

use Thud\Recording\RunContext;

/**
 * A developer-declared outcome of a run. Implementations may throw from evaluate(): the caller
 * records that as an `error` result and never lets it reach the host app.
 */
interface Expectation
{
    /**
     * The label set with ->label(), or null to use the default (`ClassName::expectations()#index`).
     */
    public function getLabel(): ?string;

    public function evaluate(RunContext $run): Result;
}
