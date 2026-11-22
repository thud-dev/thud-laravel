<?php

declare(strict_types=1);

namespace Thud\Recording\Listeners;

/**
 * Listens to the HTTP client's ResponseReceived. Counts http_out and keeps host + status class
 * (2xx/4xx/5xx) only, never URL paths or bodies.
 *
 * Phase 0 stub: not implemented yet. See docs/BUILD.md.
 */
final class HttpListener {}
