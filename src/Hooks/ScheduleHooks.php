<?php

declare(strict_types=1);

namespace Thud\Hooks;

use Closure;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use ReflectionFunction;
use ReflectionProperty;
use stdClass;
use Thud\Expectations\Evaluator;
use Thud\Expectations\VerifyOutcome;
use Thud\Recording\RunContext;
use Thud\Recording\RunStack;
use Thud\Schedule\ScheduleRegistry;
use Thud\Support\Safely;
use Thud\Transport\Buffer;
use Thud\Transport\FlushOnTerminate;

/**
 * Records scheduled tasks.
 *
 * Callbacks (closures, Schedule::job, Schedule::call) run inside the scheduler process and are
 * recorded there. Artisan commands run in a child process, which is where their side effects
 * happen: the scheduler hands the run ID and name to the child through the THUD_SCHEDULED_RUN
 * environment variable, and the child records the run when that command starts.
 */
final class ScheduleHooks
{
    public const ENV = 'THUD_SCHEDULED_RUN';

    /**
     * Artisan commands handed to a child process, keyed by spl_object_id of the event.
     *
     * @var array<int, array{id: string, name: string, mutex: string}>
     */
    private array $handedOff = [];

    /** Marks the run this process adopted from its scheduler parent. */
    private ?object $adoptedOwner = null;

    private ?string $adoptedCommand = null;

    private ?string $adoptedMutex = null;

    public function __construct(
        private readonly RunStack $stack,
        private readonly Buffer $buffer,
        private readonly FlushOnTerminate $flusher,
        private readonly Evaluator $evaluator,
        private readonly ScheduleRegistry $registry,
    ) {}

    // ---- Scheduler process -------------------------------------------------------------------

    public function starting(ScheduledTaskStarting $event): void
    {
        $task = $event->task;
        $name = self::nameFor($task);

        if (self::runsArtisanInChildProcess($task)) {
            $run = new RunContext(RunContext::TYPE_SCHEDULED, $name);
            $handoff = ['id' => $run->runId, 'name' => $name, 'mutex' => $task->mutexName()];
            $this->handedOff[spl_object_id($task)] = $handoff;
            self::setEnv(json_encode($handoff, JSON_THROW_ON_ERROR));

            return;
        }

        $this->stack->push(new RunContext(RunContext::TYPE_SCHEDULED, $name, owner: $task));
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $task = $event->task;

        if ($this->wasHandedOff($task)) {
            // The child process recorded the run itself.
            unset($this->handedOff[spl_object_id($task)]);
            self::setEnv(null);

            return;
        }

        $exitCode = $task->exitCode;

        $this->end($task, $exitCode === null || $exitCode === 0
            ? RunContext::STATUS_SUCCEEDED
            : RunContext::STATUS_FAILED, $task);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $task = $event->task;

        if ($this->wasHandedOff($task)) {
            // The process never started (or failed to), so no child recorded anything.
            $handoff = $this->handedOff[spl_object_id($task)];
            unset($this->handedOff[spl_object_id($task)]);
            self::setEnv(null);
            $run = new RunContext(RunContext::TYPE_SCHEDULED, $handoff['name'], runId: $handoff['id']);
            $run->failWith($event->exception);
            $this->record($run, RunContext::STATUS_FAILED);

            return;
        }

        $this->end($task, RunContext::STATUS_FAILED, exception: $event->exception);
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->record(new RunContext(RunContext::TYPE_SCHEDULED, self::nameFor($event->task)), RunContext::STATUS_SKIPPED);
    }

    // ---- Child process (the artisan command the scheduler started) ---------------------------

    public function commandStarting(CommandStarting $event): void
    {
        if ($this->adoptedOwner !== null || ! $this->stack->isEmpty() || $event->command === '') {
            return;
        }

        $handoff = self::readEnv();

        if ($handoff === null) {
            return;
        }

        // Grandchild processes must not adopt the same run.
        self::setEnv(null);

        $this->adoptedOwner = new stdClass;
        $this->adoptedCommand = $event->command;
        $this->adoptedMutex = $handoff['mutex'];
        $this->stack->push(new RunContext(RunContext::TYPE_SCHEDULED, $handoff['name'], owner: $this->adoptedOwner, runId: $handoff['id']));
    }

    public function commandFinished(CommandFinished $event): void
    {
        if ($this->adoptedOwner === null || $event->command !== $this->adoptedCommand) {
            return;
        }

        $owner = $this->adoptedOwner;
        $mutex = $this->adoptedMutex;
        $this->adoptedOwner = null;
        $this->adoptedCommand = null;
        $this->adoptedMutex = null;

        // The scheduler's tasks are defined in this process too (routes/console.php), so the
        // task's ->expect() declarations can be found by its mutex name.
        $task = $mutex === null ? null : $this->registry->findByMutex($mutex);

        $this->end($owner, $event->exitCode === 0 ? RunContext::STATUS_SUCCEEDED : RunContext::STATUS_FAILED, $task);
    }

    // ---- Naming -----------------------------------------------------------------------------

    /**
     * Stable, human-readable name for a scheduled task. Also used by thud:sync, so missed-run
     * detection matches runs to tasks by the same name.
     *
     * - artisan commands: the command without the PHP binary and artisan path ("invoices:send");
     * - jobs and named callbacks: their description (Schedule::job sets the job class);
     * - unnamed closures: "Closure at routes/console.php:12";
     * - shell commands: the command string.
     */
    public static function nameFor(Event $task): string
    {
        if ($task instanceof CallbackEvent) {
            if (is_string($task->description) && $task->description !== '') {
                return $task->description;
            }

            return self::callbackName($task);
        }

        $command = (string) $task->command;
        $prefix = Artisan::formatCommandString('');

        return str_starts_with($command, $prefix) ? trim(substr($command, strlen($prefix))) : $command;
    }

    private static function callbackName(CallbackEvent $task): string
    {
        return Safely::run(static function () use ($task): string {
            $callback = (new ReflectionProperty($task, 'callback'))->getValue($task);

            if (is_string($callback)) {
                return $callback;
            }

            if ($callback instanceof Closure) {
                $reflection = new ReflectionFunction($callback);
                $file = (string) $reflection->getFileName();
                $base = rtrim(base_path(), '/').'/';

                return 'Closure at '.(str_starts_with($file, $base) ? substr($file, strlen($base)) : $file).':'.$reflection->getStartLine();
            }

            return 'Callback';
        }, 'Callback', 'schedule-name') ?? 'Callback';
    }

    private static function runsArtisanInChildProcess(Event $task): bool
    {
        return ! $task instanceof CallbackEvent
            && str_starts_with((string) $task->command, Artisan::formatCommandString(''));
    }

    // ---- Internals --------------------------------------------------------------------------

    private function wasHandedOff(Event $task): bool
    {
        return isset($this->handedOff[spl_object_id($task)]);
    }

    private function end(object $owner, string $status, ?Event $task = null, ?\Throwable $exception = null): void
    {
        $run = $this->stack->popOwnedBy($owner);

        if ($run === null) {
            return;
        }

        $run->failWith($exception);

        if ($status === RunContext::STATUS_SUCCEEDED && $task !== null) {
            $this->evaluateExpectations($run, $task);
        }

        $this->record($run, $status);
    }

    private function evaluateExpectations(RunContext $run, Event $task): void
    {
        $expectations = $this->registry->expectationsFor($task);

        if ($expectations === []) {
            return;
        }

        $mutex = $task->mutexName();

        $this->evaluator->evaluate($run, $expectations, $run->name, function (int $index, string $label, int $delay) use ($run, $mutex): void {
            dispatch(VerifyOutcome::forSchedule($run->runId, $label, $index, $mutex)
                ->onQueue(self::verifyQueue())
                ->delay($delay));
        });
    }

    public static function verifyQueue(): ?string
    {
        $queue = config('thud.verify_queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    private function record(RunContext $run, string $status): void
    {
        $run->finish($status);
        $this->buffer->add($run->toPayload());
        $this->flusher->runFinished();
    }

    /**
     * Child processes inherit variables present in both getenv() and $_SERVER (Symfony Process).
     */
    private static function setEnv(?string $value): void
    {
        if ($value === null) {
            putenv(self::ENV);
            unset($_SERVER[self::ENV], $_ENV[self::ENV]);

            return;
        }

        putenv(self::ENV.'='.$value);
        $_SERVER[self::ENV] = $value;
        $_ENV[self::ENV] = $value;
    }

    /**
     * @return array{id: string, name: string, mutex: string|null}|null
     */
    private static function readEnv(): ?array
    {
        $raw = $_SERVER[self::ENV] ?? getenv(self::ENV);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_string($data['name'] ?? null)) {
            return null;
        }

        return ['id' => $data['id'], 'name' => $data['name'], 'mutex' => is_string($data['mutex'] ?? null) ? $data['mutex'] : null];
    }
}
