<?php

declare(strict_types=1);

namespace Thud\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use Thud\Expectations\Evaluator;
use Thud\Testing\RecordedRun;
use Thud\Testing\ThudFake;
use Thud\Transport\Transport;

/**
 * @method static bool enabled()
 * @method static void flush()
 * @method static list<RecordedRun> runs(?string $name = null)
 * @method static void assertRunRecorded(string $name, ?Closure $callback = null)
 * @method static void assertNothingRecorded()
 * @method static void assertExpectationsMet(string $name)
 * @method static void assertExpectationFailed(string $name, string $label)
 * @method static list<array<string, mixed>>|null syncedSchedule()
 *
 * @see \Thud\Thud
 * @see ThudFake
 */
final class Thud extends Facade
{
    /**
     * Record runs in memory instead of sending them, and evaluate within() checks immediately.
     */
    public static function fake(): ThudFake
    {
        $app = self::getFacadeApplication();

        if ($app === null) {
            throw new RuntimeException('Thud::fake() needs a booted Laravel application.');
        }

        $fake = new ThudFake($app);

        $app->instance(Transport::class, $fake);

        if ($app->bound(Evaluator::class)) {
            $app->make(Evaluator::class)->evaluateDeferredNow = true;
        }

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Thud\Thud::class;
    }
}
