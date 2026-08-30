<?php

namespace Dallanj\PiniaHydrate\Tests;

use Dallanj\PiniaHydrate\PiniaHydrateServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PiniaHydrateServiceProvider::class];
    }
}
