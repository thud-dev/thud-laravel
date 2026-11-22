<?php

declare(strict_types=1);

// Arch tests need Pest 2 or later; the Laravel 9 test job runs Pest 1.
if (! function_exists('arch')) {
    return;
}

arch('package code uses strict types')
    ->expect('Thud')
    ->toUseStrictTypes();

arch('no debugging helpers ship in the package')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('classes are final by default')
    ->expect('Thud')
    ->classes()
    ->toBeFinal();
