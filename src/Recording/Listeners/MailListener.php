<?php

declare(strict_types=1);

namespace Thud\Recording\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Address;
use Thud\Recording\RunStack;

/**
 * Counts sent mail on the current run, per mailable class.
 *
 * Mail sent by a mail-channel notification is attributed to the notification class.
 */
final class MailListener
{
    private static bool $taggingMailables = false;

    public function __construct(private readonly RunStack $stack) {}

    /**
     * Laravel 11+ puts the mailable class in the message data as `__laravel_mailable`. Laravel 9 and 10
     * don't, so add it through the view data hook, keeping any callback the app registered.
     */
    public static function tagMailablesOn(string $laravelVersion): void
    {
        if (self::$taggingMailables || version_compare($laravelVersion, '11.0.0', '>=')) {
            return;
        }

        self::$taggingMailables = true;
        $previous = Mailable::$viewDataCallback;

        Mailable::buildViewDataUsing(static function (Mailable $mailable) use ($previous): array {
            // @phpstan-ignore identical.alwaysFalse (documented as callable, but null until an app sets one)
            $data = $previous === null ? [] : (array) $previous($mailable);

            return [...$data, '__laravel_mailable' => $mailable::class];
        });
    }

    public function handle(MessageSent $event): void
    {
        $run = $this->stack->current();

        if ($run === null) {
            return;
        }

        $run->counter->mailSent(self::mailableClass($event->data), self::recipients($event));
    }

    /**
     * @return list<string>
     */
    private static function recipients(MessageSent $event): array
    {
        return array_values(array_map(static fn (Address $address): string => $address->getAddress(), $event->message->getTo()));
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
