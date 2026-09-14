<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Illuminate\Support\ServiceProvider;

class RedsysSubscriptionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/redsys.php', 'redsys');

        $this->app->singleton(RedsysGateway::class, fn ($app) => new RedsysGateway(
            merchantCode: (string) config('redsys.merchant_code'),
            secretKey:    (string) config('redsys.secret_key'),
            terminal:     (int) config('redsys.terminal'),
            production:   (bool) config('redsys.production'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
            $this->publishes([__DIR__ . '/../config/redsys.php' => config_path('redsys.php')], 'redsys-config');
            $this->commands([ChargeDueSubscriptions::class]);
        }
    }
}
