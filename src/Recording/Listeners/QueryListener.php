<?php

declare(strict_types=1);

namespace Thud\Recording\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Thud\Recording\RunStack;

/**
 * Counts insert / update / delete queries on the current run, in total and per table.
 *
 * Classifies by the first SQL keyword and skips writes to config('thud.ignore_tables') so queue
 * and framework bookkeeping (jobs, cache, sessions...) never looks like real work.
 * Never reads query bindings.
 */
final class QueryListener
{
    /** @var array<string, true> */
    private array $ignoredTables;

    /**
     * @param  list<string>  $ignoredTables
     */
    public function __construct(private readonly RunStack $stack, array $ignoredTables)
    {
        $this->ignoredTables = array_fill_keys(array_map('strtolower', $ignoredTables), true);
    }

    public function handle(QueryExecuted $event): void
    {
        $run = $this->stack->current();

        if ($run === null) {
            return;
        }

        $kind = self::classify($event->sql);

        if ($kind === null) {
            return;
        }

        $table = self::table($kind, $event->sql);

        if ($table !== null) {
            $prefix = strtolower($event->connection->getTablePrefix());

            if ($prefix !== '' && str_starts_with($table, $prefix)) {
                $table = substr($table, strlen($prefix));
            }

            if (isset($this->ignoredTables[$table])) {
                return;
            }
        }

        $run->counter->increment('db_'.$kind);

        if ($table !== null) {
            $run->counter->tableWritten($table, $kind);
        }
    }

    /**
     * @return 'insert'|'update'|'delete'|null
     */
    public static function classify(string $sql): ?string
    {
        // Cheap check first: this runs for every query, and most are selects.
        $head = strtolower(substr(ltrim($sql), 0, 7));

        return match (true) {
            str_starts_with($head, 'insert'), str_starts_with($head, 'replace') => 'insert',
            str_starts_with($head, 'update') => 'update',
            str_starts_with($head, 'delete') => 'delete',
            default => null,
        };
    }

    /**
     * The lower-cased table name a write targets, without schema or quotes, or null if unknown.
     */
    public static function table(string $kind, string $sql): ?string
    {
        $pattern = match ($kind) {
            'insert' => '/^\s*(?:insert|replace)\s+(?:(?:ignore|or\s+\w+)\s+)?into\s+([^\s(]+)/i',
            'update' => '/^\s*update\s+(?:ignore\s+)?([^\s(]+)/i',
            default => '/^\s*delete\s+(?:\S+\s+)?from\s+([^\s(]+)/i',
        };

        if (preg_match($pattern, $sql, $matches) !== 1) {
            return null;
        }

        $identifier = str_replace(['`', '"', '[', ']'], '', $matches[1]);
        $dot = strrpos($identifier, '.');

        return strtolower($dot === false ? $identifier : substr($identifier, $dot + 1));
    }
}
