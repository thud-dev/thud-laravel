<?php

declare(strict_types=1);

namespace Thud\Tests\Disabled;

use Thud\Tests\TestCase;

abstract class DisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('thud.enabled', false);
    }
}
