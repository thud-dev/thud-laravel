<?php

declare(strict_types=1);

namespace Thud\Expectations;

/**
 * A scheduled task registered with ->expectRun(): the cloud raises a missed-run finding when it
 * doesn't start within its grace period. Sent to the cloud by `php artisan thud:sync`.
 */
final class RunHappened
{
    public function __construct(public readonly int $graceMinutes = 10) {}
}
