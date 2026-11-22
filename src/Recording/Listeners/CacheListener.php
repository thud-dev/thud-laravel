<?php

declare(strict_types=1);

namespace Thud\Recording\Listeners;

use Illuminate\Cache\Events\KeyWritten;
use Thud\Recording\RunStack;

/**
 * Counts cache writes on the current run. Count only: keys and values are never kept.
 */
final class CacheListener
{
    public function __construct(private readonly RunStack $stack) {}

    public function handle(KeyWritten $event): void
    {
        $this->stack->current()?->counter->increment('cache_write');
    }
}
