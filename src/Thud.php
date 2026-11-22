<?php

declare(strict_types=1);

namespace Thud;

/**
 * Facade root: fake(), flush(), enabled().
 *
 * Phase 0 stub: only enabled() is implemented, so the kill switch has a single source of truth
 * from day one. See docs/BUILD.md.
 */
final class Thud
{
    public function enabled(): bool
    {
        return (bool) config('thud.enabled', true);
    }
}
