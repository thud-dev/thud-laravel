<?php

declare(strict_types=1);

namespace Thud\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

final class SwallowsException implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        try {
            throw new RuntimeException('Payment API timed out');
        } catch (RuntimeException) {
            // Swallowed: the job still "succeeds".
        }
    }
}
