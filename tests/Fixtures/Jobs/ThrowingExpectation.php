<?php

declare(strict_types=1);

namespace Thud\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;
use Thud\Expectations\Concerns\HasExpectations;
use Thud\Expectations\Expect;

final class ThrowingExpectation implements ShouldQueue
{
    use Dispatchable, HasExpectations, Queueable;

    public function handle(): void {}

    public function expectations(): array
    {
        return [
            Expect::state(fn () => throw new RuntimeException('database unreachable'))->label('Check that blows up'),
            Expect::state(fn () => 'yes')->label('Returns a string'),
        ];
    }
}
