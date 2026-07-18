<?php

namespace Noo\LaravelFrontendBuildCache\Tests;

use Noo\LaravelFrontendBuildCache\LaravelFrontendBuildCacheServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaravelFrontendBuildCacheServiceProvider::class];
    }
}
