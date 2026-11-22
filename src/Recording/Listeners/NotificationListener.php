<?php

declare(strict_types=1);

namespace Thud\Recording\Listeners;

use Illuminate\Notifications\Events\NotificationSent;
use Thud\Recording\RunStack;

/**
 * Counts sent notifications on the current run, per notification class (one per channel delivery).
 */
final class NotificationListener
{
    public function __construct(private readonly RunStack $stack) {}

    public function handle(NotificationSent $event): void
    {
        $this->stack->current()?->counter->notificationSent($event->notification::class);
    }
}
