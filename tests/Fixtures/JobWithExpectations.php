<?php

declare(strict_types=1);

namespace Thud\Tests\Fixtures;

use Thud\Expectations\Concerns\HasExpectations;

/**
 * Minimal job that declares expectations. Filled in when the Expectations API lands (phase 3).
 */
final class JobWithExpectations
{
    use HasExpectations;

    public function handle(): void {}

    public function expectations(): array
    {
        return [];
    }
}
