<?php

declare(strict_types=1);

use Thud\Facades\Thud;

it('merges the package config', function (): void {
    expect(config('thud.record'))->toBe(['jobs' => true, 'scheduled' => true, 'webhooks' => true])
        ->and(config('thud.ignore_tables'))->toContain('jobs', 'failed_jobs');
});

it('resolves the facade root', function (): void {
    expect(Thud::enabled())->toBeTrue();
});

it('reports disabled when the kill switch is off', function (): void {
    config(['thud.enabled' => false]);

    expect(Thud::enabled())->toBeFalse();
});
