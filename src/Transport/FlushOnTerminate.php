<?php

declare(strict_types=1);

namespace Thud\Transport;

use Illuminate\Contracts\Foundation\Application;
use Thud\Recording\RunStack;
use Thud\Support\Safely;

/**
 * Decides when buffered runs are sent, so sending never delays a response or a running job:
 *
 * - web requests and one-off commands: after the response, in app()->terminating();
 * - queue workers: right after each top-level job finishes;
 * - console processes: as soon as 50 runs are waiting and no run is in flight.
 */
final class FlushOnTerminate
{
    private bool $inWorker = false;

    public function __construct(
        private readonly Application $app,
        private readonly RunStack $stack,
        private readonly Buffer $buffer,
    ) {}

    public function register(): void
    {
        $this->app->terminating(fn () => $this->flush());
    }

    /**
     * Called on every worker loop (Illuminate\Queue\Events\Looping), between jobs.
     */
    public function workerLooping(): void
    {
        $this->inWorker = true;

        // Nothing can be in flight between jobs: drop anything a crashed job left behind.
        $this->stack->reset();
    }

    /**
     * Called after a finished run was added to the buffer.
     */
    public function runFinished(): void
    {
        if (! $this->stack->isEmpty()) {
            return;
        }

        if ($this->inWorker || ($this->buffer->isFull() && $this->app->runningInConsole())) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        Safely::run(function (): void {
            $runs = $this->buffer->drain();

            if ($runs !== []) {
                // Pause recording so Thud's own HTTP call is never counted as a side effect.
                $client = $this->app->make(Transport::class);
                $this->stack->paused(fn () => $client->sendRuns($runs));
            }
        }, key: 'flush');
    }
}
