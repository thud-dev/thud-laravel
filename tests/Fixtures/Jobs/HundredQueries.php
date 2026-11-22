<?php

declare(strict_types=1);

namespace Thud\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

final class HundredQueries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        for ($i = 0; $i < 90; $i++) {
            DB::table('orders')->where('id', $i)->first();
        }

        for ($i = 0; $i < 10; $i++) {
            DB::table('orders')->insert(['status' => 'pending']);
        }
    }
}
