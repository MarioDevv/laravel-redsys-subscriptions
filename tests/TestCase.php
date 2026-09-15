<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use Illuminate\Database\Eloquent\Model;
use MarioDevv\RedsysSubscriptions\RedsysSubscriptionsServiceProvider;
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
        return [RedsysSubscriptionsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        // Con las claves ajenas desactivadas, SQLite se traga un borrado en
        // cascada sin hacerlo, y el test pasaria mintiendo sobre lo que hace
        // MySQL en produccion.
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Laravel 13 ya no pone una clave por defecto en los tests, y el panel
        // va detras del grupo 'web': sesion y cookies cifradas la necesitan.
        // En una aplicacion de verdad siempre existe; aqui hay que ponerla.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // El driver por defecto de testbench es 'database', que para los locks
        // del comando de cobro pide la tabla cache_locks de Laravel. Aqui no
        // hace falta: array da locks atomicos dentro del proceso, que es lo
        // que prueban estos tests.
        $app['config']->set('cache.default', 'array');
    }
}
