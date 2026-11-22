<?php

declare(strict_types=1);

namespace Thud\Transport;

/**
 * POSTs gzip JSON to {endpoint}/runs with a 2-second timeout and no retries. On failure, drops the
 * batch and logs once per 10 minutes at debug. Never throws.
 *
 * Phase 0 stub: not implemented yet. See docs/BUILD.md.
 */
final class Client {}
