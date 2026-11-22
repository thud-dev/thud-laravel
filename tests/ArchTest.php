<?php

declare(strict_types=1);

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
