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
        // Segmento propio bajo redsys/: creagia/laravel-redsys registra sus
        // rutas en el mismo prefijo. Sin middleware 'web' a proposito, que
        // Redsys no trae cookie ni token CSRF.
        Route::post('redsys/subscriptions/notify', [RedsysCallbacks::class, 'notify'])
            ->name('redsys.subscriptions.notify');

        // GET y POST: con 'Enviar parametros en las URLs' en NO, Redsys devuelve
        // al titular con un GET pelado, sin parametros que postear.
        Route::match(['get', 'post'], 'redsys/subscriptions/return/{subscription}', [RedsysCallbacks::class, 'back'])
            ->name('redsys.subscriptions.return');

        // El panel: datos reales, asi que va detras del Gate de Authorize.
        Route::prefix((string) config('redsys-subscriptions.panel.path', 'redsys-subscriptions'))
            ->middleware([...(array) config('redsys-subscriptions.panel.middleware', ['web']), Authorize::class])
            ->name('redsys.subscriptions.panel.')
            ->group(function () {
                Route::get('/', [SubscriptionsPanel::class, 'overview'])->name('index');
                Route::get('suscripciones', [SubscriptionsPanel::class, 'index'])->name('list');
                Route::get('ajustes', [SubscriptionsPanel::class, 'settings'])->name('settings');
                // Cuelga de 'suscripciones/' a proposito: en la raiz del panel,
                // un {subscription} comodin se tragaria 'ajustes'.
                Route::get('suscripciones/{subscription}', [SubscriptionsPanel::class, 'show'])->name('show');
                Route::post('cobrar', [SubscriptionsPanel::class, 'run'])->name('run');
                Route::post('{subscription}/cancel', [SubscriptionsPanel::class, 'cancel'])->name('cancel');
                Route::post('{subscription}/charge', [SubscriptionsPanel::class, 'charge'])->name('charge');
            });

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'redsys-subscriptions');

        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
            $this->publishes([__DIR__ . '/../config/redsys-subscriptions.php' => config_path('redsys-subscriptions.php')], 'redsys-subscriptions-config');
            $this->publishes([__DIR__ . '/../resources/views' => resource_path('views/vendor/redsys-subscriptions')], 'redsys-subscriptions-views');
            $this->commands([ChargeDueSubscriptions::class]);
        }
    }
}
