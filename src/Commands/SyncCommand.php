<?php

declare(strict_types=1);

namespace Thud\Commands;

use Illuminate\Console\Command;
use Thud\Schedule\ScheduleRegistry;
use Thud\Transport\Transport;

/**
 * php artisan thud:sync: sends your scheduled tasks (every task by default) so Thud can tell
 * you when one doesn't run. Run it on deploy.
 */
final class SyncCommand extends Command
{
    protected $signature = 'thud:sync {--dry-run : Show the tasks without sending them}';

    protected $description = 'Send your watched scheduled tasks to Thud';

    public function handle(ScheduleRegistry $registry, Transport $transport): int
    {
        $tasks = $registry->watchedTasks();

        if ($tasks === []) {
            $this->components->warn('No scheduled tasks to watch.');
        } else {
            $this->table(
                ['Task', 'Schedule', 'Timezone', 'Grace'],
                array_map(fn (array $task) => [$task['name'], $task['cron'], $task['timezone'], $task['grace_minutes'].' min'], $tasks),
            );
        }

        $conditional = $registry->unwatchedConditionalTasks();

        if ($conditional !== []) {
            $this->components->info('Not watched, because they can skip runs on purpose: '.implode(', ', $conditional).'. Add ->expectRun() to watch them.');
        }

        $unnamed = array_values(array_filter(
            [...array_column($tasks, 'name'), ...$conditional],
            static fn (string $name): bool => str_starts_with($name, 'Closure at '),
        ));

        if ($unnamed !== []) {
            $this->components->warn('Unnamed closures are named after their file and line, so moving the code loses their history and can look like a missed run: '.implode(', ', $unnamed).". Add ->name('...') to each.");
        }

        if ((bool) $this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! $transport->sendSchedule($tasks)) {
            $this->components->error('Could not send the schedule to Thud. Run `php artisan thud:test` to check the connection.');

            return self::FAILURE;
        }

        $this->components->info(count($tasks).' scheduled '.(count($tasks) === 1 ? 'task' : 'tasks').' synced.');

        return self::SUCCESS;
    }
}
