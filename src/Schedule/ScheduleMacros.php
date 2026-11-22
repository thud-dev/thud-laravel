<?php

declare(strict_types=1);

namespace Thud\Schedule;

use Illuminate\Console\Scheduling\Event;
use Thud\Expectations\Expectation;

/**
 * Registers ->expectRun() and ->expect() on scheduled events:
 *
 *     Schedule::command('invoices:send')->dailyAt('06:00')
 *         ->expectRun()                                          // alert if it doesn't run at all
 *         ->expect(Expect::atLeast(1)->mailsSent(InvoiceMail::class));
 *
 * Registered even when Thud is disabled, so schedules using them keep working.
 */
final class ScheduleMacros
{
    public static function register(): void
    {
        Event::macro('expectRun', function (int $graceMinutes = 10): Event {
            /** @var Event $this */
            app(ScheduleRegistry::class)->expectRun($this, $graceMinutes);

            return $this;
        });

        Event::macro('expect', function (Expectation $expectation): Event {
            /** @var Event $this */
            app(ScheduleRegistry::class)->expect($this, $expectation);

            return $this;
        });
    }
}
