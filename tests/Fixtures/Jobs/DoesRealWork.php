<?php

declare(strict_types=1);

namespace Thud\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Thud\Tests\Fixtures\FixtureMail;
use Thud\Tests\Fixtures\FixtureNotification;

final class DoesRealWork implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        DB::table('orders')->insert(['status' => 'pending']);
        DB::table('orders')->insert(['status' => 'pending']);
        DB::table('orders')->where('id', 1)->update(['status' => 'active']);
        DB::table('orders')->where('id', 2)->delete();
        Mail::to('customer@example.com')->send(new FixtureMail);
        Notification::route('mail', 'customer@example.com')->notify(new FixtureNotification);
        Http::get('https://api.stripe.com/v1/customers/cus_123?expand=secret');
        Cache::put('report', 'ready');
    }
}
