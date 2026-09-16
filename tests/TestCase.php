<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use Creagia\Redsys\Support\Signature;
use MarioDevv\RedsysSubscriptions\ChargeResult;
use MarioDevv\RedsysSubscriptions\RedsysGateway;
use MarioDevv\RedsysSubscriptions\RedsysSubscriptionsServiceProvider;
use MarioDevv\RedsysSubscriptions\Sha512Signature;
use MarioDevv\RedsysSubscriptions\Subscription;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Un Redsys de mentira, ya registrado en el contenedor. Contesta siempre lo
     * mismo, o lanza lo que se le pase, y cuenta las llamadas.
     *
     * Contar es lo unico que dice si se ha cobrado: chargeStoredCard() no puede
     * ejecutarse de verdad en los tests, asi que sin el contador un cobro de
     * mas pasaria desapercibido.
     */
    protected function fakeGateway(ChargeResult|\Throwable $answer): object
    {
        $gateway = new class ('999', 'k', 1) extends RedsysGateway {
            public int $calls = 0;

            public ChargeResult|\Throwable $answer;

            public function chargeStoredCard(Subscription $subscription, string $order): ChargeResult
            {
                $this->calls++;

                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };

        $gateway->answer = $answer;

        $this->app->instance(RedsysGateway::class, $gateway);

        return $gateway;
    }

    /**
     * El cuerpo de un POST de Redsys, firmado como lo firma el TPV.
     *
     * La clave se pasa porque no siempre es la buena: hay tests que firman con
     * una distinta, o con la cadena vacia, para comprobar que se rechaza.
     */
    protected function signedPayload(array $params, string $key, string $version = 'HMAC_SHA256_V1'): array
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode($params)), '+/', '-_'), '=');
        $order   = (string) ($params['Ds_Order'] ?? $params['DS_ORDER']);

        return [
            'Ds_SignatureVersion'   => $version,
            'Ds_MerchantParameters' => $encoded,
            'Ds_Signature'          => $version === 'HMAC_SHA512_V2'
                ? Sha512Signature::sign($encoded, $order, $key)
                : Signature::calculateSignature($encoded, $order, $key),
        ];
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
