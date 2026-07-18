<?php

namespace Noo\LaravelFrontendBuildCache;

use Noo\LaravelFrontendBuildCache\Commands\BuildCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelFrontendBuildCacheServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('frontend-build-cache')
            ->hasConfigFile()
            ->hasCommand(BuildCommand::class);
    }
}
