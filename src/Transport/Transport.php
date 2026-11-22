<?php

declare(strict_types=1);

namespace Thud\Transport;

/**
 * Where finished runs and results go: the ingest API in production, an in-memory recorder
 * under Thud::fake().
 */
interface Transport
{
    /**
     * @param  list<array<string, mixed>>  $runs
     */
    public function sendRuns(array $runs): void;

    /**
     * Follow-up results for a run's within() expectations.
     *
     * @param  list<array{label: string, result: string, message?: string}>  $results
     */
    public function sendResults(string $runId, array $results): void;

    /**
     * Replaces the list of scheduled tasks registered with expectRun().
     *
     * @param  list<array<string, mixed>>  $tasks
     */
    public function sendSchedule(array $tasks): bool;

    /**
     * One reconciliation result (POST /v1/payments/checks). Returns the HTTP status, or null
     * when nothing could be sent.
     *
     * @param  array<string, mixed>  $check
     * @return array{status: int|null}
     */
    public function sendPaymentCheck(array $check): array;

    /**
     * Checks the token. Returns the app's name and environment, or an error message.
     *
     * @return array{ok: bool, app?: string, environment?: string, error?: string}
     */
    public function ping(): array;
}
