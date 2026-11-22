<?php

declare(strict_types=1);

namespace Thud\Recording;

/**
 * Stack of RunContexts. A sync-dispatched job inside a job pushes a new context;
 * counters increment only on the top context. Reset on every request and job (Octane, workers).
 *
 * Phase 0 stub: not implemented yet. See docs/BUILD.md.
 */
final class RunStack {}
