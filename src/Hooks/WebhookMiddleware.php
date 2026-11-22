<?php

declare(strict_types=1);

namespace Thud\Hooks;

/**
 * Route middleware, alias `thud`, optional provider param (`thud:stripe`). Records a webhook run
 * named after the route and, for known providers, reads only the event ID and type from the payload.
 *
 * Phase 0 stub: not implemented yet. See docs/BUILD.md.
 */
final class WebhookMiddleware {}
