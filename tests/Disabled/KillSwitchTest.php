<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Thud\Facades\Thud;
use Thud\Recording\RunStack;
use Thud\Tests\Fixtures\Jobs\ChildJob;

it('registers no listeners when THUD_ENABLED=false', function (): void {
    expect(Thud::enabled())->toBeFalse()
        ->and(Event::hasListeners(QueryExecuted::class))->toBeFalse()
        ->and(Event::hasListeners(MessageSent::class))->toBeFalse()
        ->and(Event::hasListeners(JobProcessed::class))->toBeFalse();

    // Laravel registers its own JobProcessing listener; none of them may be Thud's.
    foreach (Event::getListeners(JobProcessing::class) as $listener) {
        $used = (new ReflectionFunction($listener))->getClosureUsedVariables();
        expect(json_encode(array_map(fn ($v) => is_object($v) ? $v::class : $v, $used)))->not->toContain('Thud');
    }
});

it('keeps routes using the thud middleware working, and records nothing', function (): void {
    Route::post('/stripe/webhook', fn () => response('ok'))->middleware('thud:stripe');

    $this->postJson('/stripe/webhook', ['id' => 'evt_1'])->assertOk();
    ChildJob::dispatch();
    app()->terminate();

    expect(ingestRequests())->toBe([])
        ->and(app()->resolved(RunStack::class))->toBeFalse();
});
