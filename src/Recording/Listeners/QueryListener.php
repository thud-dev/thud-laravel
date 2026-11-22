<?php

declare(strict_types=1);

namespace Thud\Recording\Listeners;

/**
 * Listens to QueryExecuted. Classifies insert/update/delete by the first SQL keyword
 * and skips writes to config('thud.ignore_tables'). Never reads query bindings.
 *
 * Phase 0 stub: not implemented yet. See docs/BUILD.md.
 */
final class QueryListener {}
