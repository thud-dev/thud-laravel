<?php

declare(strict_types=1);

namespace Thud\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Thud\ThudServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ThudServiceProvider::class];
    }
}
