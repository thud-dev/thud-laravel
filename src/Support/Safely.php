<?php

declare(strict_types=1);

namespace Thud\Support;

use Closure;
use Throwable;

/**
 * Runs package code so it can never throw into the host app.
 *
 * Any Throwable is swallowed and logged at debug level, at most once per 10 minutes per key.
 */
final class Safely
{
    private const LOG_INTERVAL_SECONDS = 600;

    /** @var array<string, int> */
    private static array $lastLoggedAt = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  T|null  $fallback
     * @return T|null
     */
    public static function run(Closure $callback, mixed $fallback = null, string $key = 'thud'): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            self::log($key, $e->getMessage());

            return $fallback;
        }
    }

    public static function log(string $key, string $message): void
    {
        try {
            $now = time();

            if (isset(self::$lastLoggedAt[$key]) && $now - self::$lastLoggedAt[$key] < self::LOG_INTERVAL_SECONDS) {
                return;
            }

            self::$lastLoggedAt[$key] = $now;

            if (function_exists('logger')) {
                logger()->debug("[thud] {$key}: {$message}");
            }
        } catch (Throwable) {
            // Logging must never break the host app either.
        }
    }

    /**
     * For tests: forget the log throttle.
     */
    public static function resetLogThrottle(): void
    {
        self::$lastLoggedAt = [];
    }
}
