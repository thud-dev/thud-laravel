<?php

declare(strict_types=1);

namespace Thud\Recording;

/**
 * Integer counters per side-effect metric, plus per-class breakdowns.
 *
 * Holds only class names, hosts and counts: never addresses, payloads or query bindings.
 */
final class SideEffectCounter
{
    public const METRICS = [
        'db_insert',
        'db_update',
        'db_delete',
        'mail_sent',
        'notification_sent',
        'http_out',
        'cache_write',
        'jobs_dispatched',
    ];

    /** @var array<string, int> */
    private array $metrics;

    /** @var array<string, int> */
    private array $mail = [];

    /** @var array<string, int> */
    private array $notifications = [];

    /** @var array<string, array<string, int>> */
    private array $httpHosts = [];

    /** @var array<string, int> */
    private array $jobs = [];

    public function __construct()
    {
        $this->metrics = array_fill_keys(self::METRICS, 0);
    }

    public function increment(string $metric, int $by = 1): void
    {
        if (isset($this->metrics[$metric])) {
            $this->metrics[$metric] += $by;
        }
    }

    public function mailSent(string $mailable): void
    {
        $this->metrics['mail_sent']++;
        $this->mail[$mailable] = ($this->mail[$mailable] ?? 0) + 1;
    }

    public function notificationSent(string $notification): void
    {
        $this->metrics['notification_sent']++;
        $this->notifications[$notification] = ($this->notifications[$notification] ?? 0) + 1;
    }

    /**
     * @param  string  $statusClass  2xx, 3xx, 4xx, 5xx or failed (no response)
     */
    public function httpCall(string $host, string $statusClass): void
    {
        $this->metrics['http_out']++;
        $this->httpHosts[$host][$statusClass] = ($this->httpHosts[$host][$statusClass] ?? 0) + 1;
    }

    public function jobDispatched(string $job): void
    {
        $this->metrics['jobs_dispatched']++;
        $this->jobs[$job] = ($this->jobs[$job] ?? 0) + 1;
    }

    public function metric(string $metric): int
    {
        return $this->metrics[$metric] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function metrics(): array
    {
        return $this->metrics;
    }

    public function mailCount(string $mailable): int
    {
        return $this->mail[$mailable] ?? 0;
    }

    public function notificationCount(string $notification): int
    {
        return $this->notifications[$notification] ?? 0;
    }

    /**
     * Per-class and per-host detail. Empty groups are left out.
     *
     * @return array<string, array<string, int|array<string, int>>>
     */
    public function breakdown(): array
    {
        return array_filter([
            'mail' => $this->mail,
            'notifications' => $this->notifications,
            'http_hosts' => $this->httpHosts,
            'jobs' => $this->jobs,
        ], static fn (array $group): bool => $group !== []);
    }
}
