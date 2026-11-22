<?php

declare(strict_types=1);

namespace Thud\Transport;

/**
 * In-memory buffer of finished runs waiting to be sent. Capped so a long request or a stuck
 * flush can never grow memory without bound: past the cap, new runs are dropped.
 */
final class Buffer
{
    public const MAX_ITEMS = 500;

    public const FLUSH_AT = 50;

    /** @var list<array<string, mixed>> */
    private array $runs = [];

    private int $dropped = 0;

    /**
     * @param  array<string, mixed>  $run
     */
    public function add(array $run): void
    {
        if (count($this->runs) >= self::MAX_ITEMS) {
            $this->dropped++;

            return;
        }

        $this->runs[] = $run;
    }

    /**
     * Returns every buffered run and empties the buffer.
     *
     * @return list<array<string, mixed>>
     */
    public function drain(): array
    {
        $runs = $this->runs;
        $this->runs = [];

        return $runs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->runs;
    }

    public function count(): int
    {
        return count($this->runs);
    }

    public function isFull(): bool
    {
        return count($this->runs) >= self::FLUSH_AT;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }
}
