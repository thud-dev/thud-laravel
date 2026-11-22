<?php

declare(strict_types=1);

namespace Thud\Schedule;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Thud\Expectations\Expectation;
use Thud\Expectations\RunHappened;
use Thud\Hooks\ScheduleHooks;
use Thud\Support\Safely;
use WeakMap;

/**
 * What each scheduled task declares with ->expectRun() and ->expect().
 *
 * Keyed by the Event object itself: an event's mutex name depends on its cron expression, which
 * may be set after ->expect() in the chain, so it is only read when a task needs to be found.
 */
final class ScheduleRegistry
{
    /** @var WeakMap<Event, list<Expectation>> */
    private WeakMap $expectations;

    /** @var WeakMap<Event, RunHappened> */
    private WeakMap $runs;

    public function __construct(private readonly Container $container)
    {
        $this->expectations = new WeakMap;
        $this->runs = new WeakMap;
    }

    public function expectRun(Event $event, int $graceMinutes): void
    {
        $this->runs[$event] = new RunHappened(max(0, $graceMinutes));
    }

    public function expect(Event $event, Expectation $expectation): void
    {
        $this->expectations[$event] = [...($this->expectations[$event] ?? []), $expectation];
    }

    /**
     * @return list<Expectation>
     */
    public function expectationsFor(Event $event): array
    {
        return $this->expectations[$event] ?? [];
    }

    public function findByMutex(string $mutex): ?Event
    {
        foreach ($this->events() as $event) {
            if (Safely::run(static fn () => $event->mutexName(), key: 'schedule-mutex') === $mutex) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Every task registered with ->expectRun(), as sent to POST /v1/schedule.
     *
     * @return list<array{name: string, mutex: string, cron: string, timezone: string, grace_minutes: int}>
     */
    public function watchedTasks(): array
    {
        $tasks = [];

        foreach ($this->events() as $event) {
            $run = $this->runs[$event] ?? null;

            if ($run === null) {
                continue;
            }

            $timezone = $event->timezone ?? $this->container->make('config')->get('app.timezone', 'UTC');

            $tasks[] = [
                'name' => ScheduleHooks::nameFor($event),
                'mutex' => $event->mutexName(),
                'cron' => $event->expression,
                'timezone' => $timezone instanceof \DateTimeZone ? $timezone->getName() : (string) $timezone,
                'grace_minutes' => $run->graceMinutes,
            ];
        }

        return $tasks;
    }

    /**
     * @return list<Event>
     */
    private function events(): array
    {
        return array_values($this->container->make(Schedule::class)->events());
    }
}
