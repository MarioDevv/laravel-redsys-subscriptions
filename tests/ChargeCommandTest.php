<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use MarioDevv\RedsysSubscriptions\ChargeResult;
use MarioDevv\RedsysSubscriptions\RedsysGateway;
use MarioDevv\RedsysSubscriptions\Subscription;

/**
 * Dos pases solapados cobrando al mismo cliente es el fallo mas caro que puede
 * tener un paquete de suscripciones: el cliente lo ve en su extracto.
 */
final class ChargeCommandTest extends TestCase
{
    use RefreshDatabase;

    private function due(): Subscription
    {
        return Subscription::create([
            'billable_type'   => 'App\\Models\\User',
            'billable_id'     => 1,
            'amount_in_cents' => 1900,
            'status'          => Subscription::ACTIVE,
            'card_token'      => 'ref',
            'next_charge_at'  => now()->subDay(),
        ]);
    }

    /** Cuenta cuantas veces se ha llamado a Redsys. */
    private function countingGateway(): object
    {
        $gateway = new class ('999', 'k', 1) extends RedsysGateway {
            public int $calls = 0;

            public function chargeStoredCard(Subscription $subscription, string $order): ChargeResult
            {
                $this->calls++;

                return new ChargeResult(ChargeOutcome::Authorized, '0000');
            }
        };

        $this->app->instance(RedsysGateway::class, $gateway);

        return $gateway;
    }

    public function test_it_charges_what_is_due(): void
    {
        $this->due();
        $gateway = $this->countingGateway();

        $this->artisan('redsys:charge-subscriptions')->assertSuccessful();

        $this->assertSame(1, $gateway->calls);
    }

    public function test_a_second_pass_does_not_charge_while_the_first_is_running(): void
    {
        $this->due();
        $gateway = $this->countingGateway();

        // Lo que hace el cron cuando el pase anterior aun no ha terminado.
        Cache::lock('redsys-subscriptions:charging', 600)->get();

        $this->artisan('redsys:charge-subscriptions')
            ->expectsOutputToContain('Ya hay un pase de cobro en marcha')
            ->assertSuccessful();

        $this->assertSame(0, $gateway->calls);
    }

    public function test_the_lock_is_released_so_the_next_pass_can_run(): void
    {
        $this->due();
        $gateway = $this->countingGateway();

        $this->artisan('redsys:charge-subscriptions')->assertSuccessful();
        $this->artisan('redsys:charge-subscriptions')->assertSuccessful();

        // El segundo pase no encuentra nada vencido, pero ha podido entrar:
        // si el lock se quedara cogido, no volveria a cobrarse nunca.
        $this->assertSame(1, $gateway->calls);
        $this->assertTrue(Cache::lock('redsys-subscriptions:charging', 1)->get());
    }

    public function test_a_failing_pass_still_frees_the_lock(): void
    {
        $this->due();

        $this->app->instance(RedsysGateway::class, new class ('999', 'k', 1) extends RedsysGateway {
            public function chargeStoredCard(Subscription $subscription, string $order): ChargeResult
            {
                throw new \RuntimeException('Redsys no responde');
            }
        });

        try {
            $this->artisan('redsys:charge-subscriptions')->run();
        } catch (\RuntimeException) {
            // Da igual como acabe: lo que no puede es dejar el cobro bloqueado
            // hasta que caduque el lock diez minutos despues.
        }

        $this->assertTrue(Cache::lock('redsys-subscriptions:charging', 1)->get());
    }

    public function test_dry_run_does_not_touch_redsys(): void
    {
        $this->due();
        $gateway = $this->countingGateway();

        $this->artisan('redsys:charge-subscriptions', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, $gateway->calls);
    }
}
