<?php

declare(strict_types=1);

namespace MarioDevv\CashierRedsys\Tests;

use Illuminate\Database\Eloquent\Model;
use MarioDevv\CashierRedsys\CashierRedsysServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [CashierRedsysServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }
}
