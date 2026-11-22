<?php

declare(strict_types=1);

namespace Thud\Expectations\Concerns;

use Thud\Expectations\Expectation;

/**
 * Add to a queued job to declare what a successful run means.
 */
trait HasExpectations
{
    /**
     * @return list<Expectation>
     */
    abstract public function expectations(): array;
}
