<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Illuminate\Support\Facades\Route;
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
        // Sin middleware 'web' a proposito: Redsys no trae cookie ni token CSRF.
        Route::post('redsys/subscriptions/notify', [RedsysCallbacks::class, 'notify'])
            ->name('redsys.subscriptions.notify');

        // GET y POST: con 'Enviar parametros en las URLs' en NO, Redsys devuelve
        // al titular con un GET pelado, sin parametros que postear.
        Route::match(['get', 'post'], 'redsys/subscriptions/return/{subscription}', [RedsysCallbacks::class, 'back'])
            ->name('redsys.subscriptions.return');

        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
            $this->publishes([__DIR__ . '/../config/redsys-subscriptions.php' => config_path('redsys-subscriptions.php')], 'redsys-subscriptions-config');
            $this->commands([ChargeDueSubscriptions::class]);
        }
    }
}
