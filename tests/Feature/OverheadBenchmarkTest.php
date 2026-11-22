<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Thud\Tests\Fixtures\Jobs\HundredQueries;
use Thud\Transport\Buffer;

/**
 * Average wall time of one HundredQueries job, best of 5 rounds of 40 jobs.
 */
function averageJobMilliseconds(): float
{
    $best = INF;

    for ($round = 0; $round < 5; $round++) {
        $start = hrtime(true);

        for ($i = 0; $i < 40; $i++) {
            HundredQueries::dispatch();
        }

        $best = min($best, (hrtime(true) - $start) / 1e6 / 40);
        app(Buffer::class)->drain();
    }

    return $best;
}

it('slows a job doing 100 queries by less than 1 ms on average', function (): void {
    averageJobMilliseconds(); // warm up

    $withThud = averageJobMilliseconds();

    Event::forget(QueryExecuted::class);
    foreach ([JobProcessing::class, JobProcessed::class, JobFailed::class, JobExceptionOccurred::class, JobQueued::class] as $event) {
        Event::forget($event);
    }

    $withoutThud = averageJobMilliseconds();
    $overhead = $withThud - $withoutThud;

    fwrite(STDERR, sprintf("\n  Overhead per 100-query job: %.3f ms (%.3f ms with Thud, %.3f ms without)\n", $overhead, $withThud, $withoutThud));

    expect($overhead)->toBeLessThan(1.0);
})->group('benchmark');
