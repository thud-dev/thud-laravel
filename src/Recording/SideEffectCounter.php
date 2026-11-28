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

    /**
     * Write statements per table and kind: table names are schema, never data.
     *
     * @var array<string, array<string, int>>
     */
    private array $tables = [];

    /** Bounds the payload for a run that writes to an unusual number of tables. */
    public const MAX_TABLES = 50;

    /**
     * sha256 of lower-cased "to" addresses per mailable. Kept in memory for Expect::mailSent()->to()
     * and never sent.
     *
     * @var array<string, array<string, true>>
     */
    private array $mailRecipients = [];

    /**
     * Exact status codes per host, for Expect::httpCalled()->withStatus(). Never sent.
     *
     * @var array<string, array<int, int>>
     */
    private array $httpStatuses = [];

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

    /**
     * @param  list<string>  $recipients  email addresses; only their hashes are kept
     */
    public function mailSent(string $mailable, array $recipients = []): void
    {
        $this->metrics['mail_sent']++;
        $this->mail[$mailable] = ($this->mail[$mailable] ?? 0) + 1;

        foreach ($recipients as $address) {
            $this->mailRecipients[$mailable][self::hashAddress($address)] = true;
        }
    }

    public static function hashAddress(string $address): string
    {
        return hash('sha256', strtolower(trim($address)));
    }

    public function mailSentTo(string $mailable, string $addressHash): bool
    {
        return isset($this->mailRecipients[$mailable][$addressHash]);
    }

    public function notificationSent(string $notification): void
    {
        $this->metrics['notification_sent']++;
        $this->notifications[$notification] = ($this->notifications[$notification] ?? 0) + 1;
    }

    /**
     * @param  string  $statusClass  2xx, 3xx, 4xx, 5xx or failed (no response)
     */
    public function httpCall(string $host, string $statusClass, ?int $status = null): void
    {
        $this->metrics['http_out']++;
        $this->httpHosts[$host][$statusClass] = ($this->httpHosts[$host][$statusClass] ?? 0) + 1;

        if ($status !== null) {
            $this->httpStatuses[$host][$status] = ($this->httpStatuses[$host][$status] ?? 0) + 1;
        }
    }

    /**
     * Calls to $host (null = any host), optionally only those answered with $status.
     */
    public function httpCount(?string $host = null, ?int $status = null): int
    {
        $hosts = $host === null ? array_keys($this->httpHosts) : [strtolower($host)];
        $count = 0;

        foreach ($hosts as $name) {
            $count += $status === null
                ? array_sum($this->httpHosts[$name] ?? [])
                : ($this->httpStatuses[$name][$status] ?? 0);
        }

        return $count;
    }

    public function jobCount(?string $job = null): int
    {
        return $job === null ? $this->metrics['jobs_dispatched'] : ($this->jobs[$job] ?? 0);
    }

    /**
     * @param  'insert'|'update'|'delete'  $kind
     */
    public function tableWritten(string $table, string $kind): void
    {
        if (! isset($this->tables[$table]) && count($this->tables) >= self::MAX_TABLES) {
            return;
        }

        $this->tables[$table][$kind] = ($this->tables[$table][$kind] ?? 0) + 1;
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
            'tables' => $this->tables,
        ], static fn (array $group): bool => $group !== []);
    }
}
