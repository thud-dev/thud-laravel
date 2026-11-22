<?php

declare(strict_types=1);

namespace Thud\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Thud\Expectations\Concerns\HasExpectations;
use Thud\Expectations\Expect;
use Thud\Tests\Fixtures\FixtureMail;

/**
 * Activates an order and sends a welcome mail, unless told to "succeed" without doing the work.
 */
final class ActivateSubscription implements ShouldQueue
{
    use Dispatchable, HasExpectations, Queueable;

    public function __construct(public int $orderId, public bool $doTheWork = true) {}

    public function handle(): void
    {
        if (! $this->doTheWork) {
            return; // the early return that makes a job "succeed" while doing nothing
        }

        DB::table('orders')->where('id', $this->orderId)->update(['status' => 'active']);
        Mail::to('Customer@Example.com')->send(new FixtureMail);
    }

    public function expectations(): array
    {
        return [
            Expect::mailSent(FixtureMail::class)->to('customer@example.com')->label('Welcome mail sent'),
            Expect::state(fn () => $this->isActive())->label('Order is active'),
            Expect::state(fn () => $this->isActive())->within(seconds: 60)->label('Order is still active'),
            Expect::atLeast(1)->rowsWritten(),
        ];
    }

    private function isActive(): bool
    {
        return DB::table('orders')->where('id', $this->orderId)->value('status') === 'active';
    }
}
