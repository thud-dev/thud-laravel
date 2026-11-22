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
 * What each scheduled task declares with ->expectRun(), ->dontWatch() and ->expect().
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

    /** @var WeakMap<Event, true> */
    private WeakMap $ignored;

    public function __construct(private readonly Container $container)
    {
        $this->expectations = new WeakMap;
        $this->runs = new WeakMap;
        $this->ignored = new WeakMap;
    }

    public function expectRun(Event $event, int $graceMinutes): void
    {
        $this->runs[$event] = new RunHappened(max(0, $graceMinutes));
    }

    public function dontWatch(Event $event): void
    {
        $this->ignored[$event] = true;
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
     * The tasks Thud watches for missed runs, as sent to POST /v1/schedule.
     *
     * With watch_scheduled_tasks on (the default), that is every task, except ones that can
     * legitimately not run (conditions like when(), skip() or between(), and sub-minute tasks),
     * which are only watched with ->expectRun(). ->dontWatch() leaves a task out.
     *
     * @return list<array{name: string, mutex: string, cron: string, timezone: string, grace_minutes: int}>
     */
    public function watchedTasks(): array
    {
        $config = $this->container->make('config');
        $watchAll = (bool) $config->get('thud.watch_scheduled_tasks', true);
        $defaultGrace = max(0, (int) $config->get('thud.scheduled_grace_minutes', 10));
        $tasks = [];

        foreach ($this->events() as $event) {
            if (isset($this->ignored[$event])) {
                continue;
            }

            $run = $this->runs[$event] ?? null;

            if ($run === null && (! $watchAll || ! self::alwaysRuns($event))) {
                continue;
            }

            $timezone = $event->timezone ?? $config->get('app.timezone', 'UTC');

            $tasks[] = [
                'name' => ScheduleHooks::nameFor($event),
                'mutex' => $event->mutexName(),
                'cron' => $event->expression,
                'timezone' => $timezone instanceof \DateTimeZone ? $timezone->getName() : (string) $timezone,
                'grace_minutes' => $run !== null ? $run->graceMinutes : $defaultGrace,
            ];
        }

        return $tasks;
    }

    /**
     * Tasks that aren't watched automatically because they can skip runs on purpose.
     *
     * @return list<string> task names
     */
    public function unwatchedConditionalTasks(): array
    {
        $names = [];

        foreach ($this->events() as $event) {
            if (! isset($this->ignored[$event]) && ! isset($this->runs[$event]) && ! self::alwaysRuns($event)) {
                $names[] = ScheduleHooks::nameFor($event);
            }
        }

        return $names;
    }

    /**
     * Whether the task runs on every slot of its cron expression: no when()/skip()/between()
     * conditions, and not a sub-minute task. A deliberately skipped run would otherwise look missed.
     */
    private static function alwaysRuns(Event $event): bool
    {
        if ($event->isRepeatable()) {
            return false;
        }

        $conditions = Safely::run(
            static fn () => \Closure::bind(fn (): int => count($this->filters) + count($this->rejects), $event, Event::class)(),
            key: 'schedule-conditions',
        );

        return $conditions === 0;
    }

    /**
     * @return list<Event>
     */
    private function events(): array
    {
        return array_values($this->container->make(Schedule::class)->events());
    }
}
