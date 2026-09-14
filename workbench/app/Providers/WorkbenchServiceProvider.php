<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Gate;
use MarioDevv\RedsysSubscriptions\Authorize;
use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // `artisan serve` filtra el entorno del subproceso con una lista blanca,
        // asi que las credenciales de la linea de arranque no llegarian a la
        // demo. Laravel expone la lista justo para esto.
        ServeCommand::$passthroughVariables = array_merge(ServeCommand::$passthroughVariables, [
            'REDSYS_MERCHANT_CODE',
            'REDSYS_TERMINAL',
            'REDSYS_SECRET_KEY',
            'REDSYS_PRODUCTION',
        ]);
    }

    public function boot(): void
    {
        // El panel esta cerrado salvo que lo abra un Gate. Aqui se abre porque
        // esto es el taller; en una aplicacion de verdad lo decide su dueño.
        Gate::define(Authorize::GATE, fn ($user = null) => true);

        // Y testbench desactiva el adaptador putenv de Laravel (Application.php),
        // asi que env() tampoco las veria. Para la demo se leen a mano.
        foreach ([
            'merchant_code' => 'REDSYS_MERCHANT_CODE',
            'terminal'      => 'REDSYS_TERMINAL',
            'secret_key'    => 'REDSYS_SECRET_KEY',
        ] as $key => $variable) {
            if ($value = getenv($variable)) {
                config(["redsys-subscriptions.{$key}" => $value]);
            }
        }
    }
}
