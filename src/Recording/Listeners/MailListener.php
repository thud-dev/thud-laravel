<?php

declare(strict_types=1);

namespace Thud\Recording\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Thud\Recording\RunStack;

/**
 * Counts sent mail on the current run, per mailable class.
 *
 * Mail sent by a mail-channel notification is attributed to the notification class.
 */
final class MailListener
{
    public function __construct(private readonly RunStack $stack) {}

    public function handle(MessageSent $event): void
    {
        $run = $this->stack->current();

        if ($run === null) {
            return;
        }

        $run->counter->mailSent(self::mailableClass($event->data));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function mailableClass(array $data): string
    {
        foreach (['__laravel_mailable', '__laravel_notification'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        return 'unknown';
    }
}
