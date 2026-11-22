<?php

declare(strict_types=1);

namespace Thud\Expectations;

use Closure;

/**
 * Builds expectations:
 *
 *     Expect::mailSent(WelcomeMail::class)->to($email)
 *     Expect::notificationSent(InvoicePaid::class)
 *     Expect::httpCalled('api.paystack.co')->withStatus(200)
 *     Expect::atLeast(10)->mailsSent(DigestMail::class)
 *     Expect::state(fn () => $order->refresh()->isActive())->within(seconds: 60)->label('Order is active')
 */
final class Expect
{
    public static function mailSent(string $mailable): MailSent
    {
        return new MailSent($mailable);
    }

    public static function notificationSent(string $notification): NotificationSent
    {
        return new NotificationSent($notification);
    }

    public static function httpCalled(string $host): HttpCalled
    {
        return new HttpCalled($host);
    }

    public static function atLeast(int $minimum): AtLeast
    {
        return new AtLeast($minimum);
    }

    /**
     * @param  Closure(): mixed  $check
     */
    public static function state(Closure $check): StateCheck
    {
        return new StateCheck($check);
    }
}
