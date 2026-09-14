<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Illuminate\Support\ServiceProvider;

class RedsysSubscriptionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/redsys-subscriptions.php', 'redsys-subscriptions');

        $this->app->singleton(RedsysGateway::class, fn ($app) => new RedsysGateway(
            merchantCode: (string) config('redsys-subscriptions.merchant_code'),
            secretKey:    (string) config('redsys-subscriptions.secret_key'),
            terminal:     (int) config('redsys-subscriptions.terminal'),
            production:   (bool) config('redsys-subscriptions.production'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
            $this->publishes([__DIR__ . '/../config/redsys-subscriptions.php' => config_path('redsys-subscriptions.php')], 'redsys-subscriptions-config');
            $this->commands([ChargeDueSubscriptions::class]);
        }
    }
}
