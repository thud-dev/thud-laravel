<?php

declare(strict_types=1);

namespace Thud;

use Illuminate\Contracts\Container\Container;
use Thud\Transport\FlushOnTerminate;

/**
 * Facade root: enabled() and flush(). Thud::fake() swaps it for Testing\ThudFake.
 */
final class Thud
{
    public function __construct(private readonly Container $container) {}

    public function enabled(): bool
    {
        return (bool) config('thud.enabled', true);
    }

    /**
     * Sends buffered runs now instead of at the end of the request or job.
     */
    public function flush(): void
    {
        if ($this->container->bound(FlushOnTerminate::class)) {
            $this->container->make(FlushOnTerminate::class)->flush();
        }
    }
}
