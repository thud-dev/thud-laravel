<?php

declare(strict_types=1);

namespace Thud\Recording;

use Closure;

/**
 * Stack of in-flight runs. A sync-dispatched job inside a job pushes a new context, and side
 * effects are counted only on the top one, so a nested run never inflates its parent.
 */
final class RunStack
{
    /** @var list<RunContext> */
    private array $contexts = [];

    private bool $paused = false;

    public function push(RunContext $context): void
    {
        $this->contexts[] = $context;
    }

    /**
     * The run that side effects should be counted on, or null when nothing should be counted:
     * no run in flight, the top run is ignored, or recording is paused (Thud's own HTTP calls).
     */
    public function current(): ?RunContext
    {
        if ($this->paused || $this->contexts === []) {
            return null;
        }

        $top = $this->contexts[array_key_last($this->contexts)];

        return $top->recordable ? $top : null;
    }

    /**
     * Pops the run started by $owner. Any runs above it were abandoned (their end event never
     * fired) and are discarded. Returns null when $owner has no run on the stack.
     */
    public function popOwnedBy(object $owner): ?RunContext
    {
        for ($i = count($this->contexts) - 1; $i >= 0; $i--) {
            if ($this->contexts[$i]->owner === $owner) {
                $context = $this->contexts[$i];
                $this->contexts = array_slice($this->contexts, 0, $i);

                return $context;
            }
        }

        return null;
    }

    public function contains(object $owner): bool
    {
        foreach ($this->contexts as $context) {
            if ($context->owner === $owner) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->contexts === [];
    }

    public function depth(): int
    {
        return count($this->contexts);
    }

    public function reset(): void
    {
        $this->contexts = [];
        $this->paused = false;
    }

    /**
     * Runs $callback with recording paused, so Thud's own work is never counted as a side effect.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function paused(Closure $callback): mixed
    {
        $wasPaused = $this->paused;
        $this->paused = true;

        try {
            return $callback();
        } finally {
            $this->paused = $wasPaused;
        }
    }
}
