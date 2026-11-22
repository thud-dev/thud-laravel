<?php

declare(strict_types=1);

namespace Thud\Recording\Listeners;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Thud\Recording\RunStack;

/**
 * Counts outgoing HTTP client calls on the current run.
 *
 * Keeps the host and the status class (2xx/3xx/4xx/5xx, or "failed" when no response arrived)
 * only: never URL paths, query strings or bodies.
 */
final class HttpListener
{
    public function __construct(private readonly RunStack $stack) {}

    public function handle(ResponseReceived|ConnectionFailed $event): void
    {
        $run = $this->stack->current();

        if ($run === null) {
            return;
        }

        $statusClass = $event instanceof ResponseReceived
            ? intdiv($event->response->status(), 100).'xx'
            : 'failed';

        $run->counter->httpCall(self::host($event->request->url()), $statusClass);
    }

    private static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : 'unknown';
    }
}
