<?php

declare(strict_types=1);

namespace Thud;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Registers Thud's config, root service, listeners, schedule macros, webhook middleware and commands.
 *
 * Phase 0: only the config and the root service are registered. Listeners, macros, middleware
 * and commands arrive in later phases (see docs/BUILD.md) and must stay behind the
 * THUD_ENABLED kill switch.
 */
final class ThudServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('thud')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Thud::class);
    }
}
