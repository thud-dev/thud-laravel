<?php

declare(strict_types=1);

namespace Thud\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 *
 * @see \Thud\Thud
 */
final class Thud extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Thud\Thud::class;
    }
}
