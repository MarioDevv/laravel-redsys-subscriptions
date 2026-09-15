<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use Illuminate\Support\Facades\Gate;
use MarioDevv\RedsysSubscriptions\Authorize;
use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use MarioDevv\RedsysSubscriptions\ChargeResult;
use MarioDevv\RedsysSubscriptions\RedsysGateway;
use MarioDevv\RedsysSubscriptions\Subscription;

/**
 * El panel enseña las suscripciones de todos los clientes y cobra de verdad.
 * Lo que se prueba aquí antes que nada es quién puede entrar.
 */
final class PanelTest extends TestCase
{
    private const KEY = 'clave-de-pruebas-solo-para-el-test';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('redsys-subscriptions.secret_key', self::KEY);
        $app['config']->set('redsys-subscriptions.merchant_code', '999008881');
    }

    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::create(array_merge([
            'billable_type'   => 'App\\Models\\User',
            'billable_id'     => 1,
            'amount_in_cents' => 1500,
            'card_token'      => 'ref-guardada',
            'card_last_four'  => '0004',
            'card_expiry'     => '4912',
            'status'          => Subscription::ACTIVE,
            'next_charge_at'  => now()->addWeek(),
        ], $attributes));
    }

    private function allowEveryone(): void
    {
        Gate::define(Authorize::GATE, fn ($user = null) => true);
    }

    public function test_it_is_closed_when_nobody_defined_the_gate(): void
    {
        // El entorno de tests no es 'local', asi que manda el Gate. Si esto se
        // rompe, un panel con los datos de pago de todos queda al aire.
        $this->subscription();

        $this->get(route('redsys.subscriptions.panel.index'))->assertForbidden();
    }

    public function test_the_gate_decides_who_gets_in(): void
    {
        Gate::define(Authorize::GATE, fn ($user = null) => false);

        $this->get(route('redsys.subscriptions.panel.index'))->assertForbidden();

        $this->allowEveryone();

        $this->get(route('redsys.subscriptions.panel.index'))->assertOk();
    }

    public function test_the_overview_normalises_every_period_to_a_month(): void
    {
        $this->allowEveryone();
        $this->subscription(['amount_in_cents' => 1500]);                         // 15 €/mes
        $this->subscription(['amount_in_cents' => 12000, 'interval' => 'yearly']); // 10 €/mes
        $this->subscription(['status' => Subscription::CANCELED]);                // no cuenta

        $this->get(route('redsys.subscriptions.panel.index'))
            ->assertOk()
            ->assertSee('25,00');    // el anual llevado a mes, no sumado entero
    }

    public function test_the_list_shows_the_card_behind_each_subscription(): void
    {
        $this->allowEveryone();
        $this->subscription();

        $this->get(route('redsys.subscriptions.panel.list'))
            ->assertOk()
            ->assertSee('···· 0004');
    }

    public function test_the_settings_page_never_prints_the_secret_key(): void
    {
        $this->allowEveryone();

        $this->get(route('redsys.subscriptions.panel.settings'))
            ->assertOk()
            ->assertSee('Configurada')
            ->assertDontSee(self::KEY);
    }

    public function test_it_filters_by_status_and_searches(): void
    {
        $this->allowEveryone();
        $this->subscription(['card_last_four' => '1111']);
        $this->subscription(['card_last_four' => '2222', 'status' => Subscription::PAST_DUE]);

        $this->get(route('redsys.subscriptions.panel.list', ['status' => Subscription::PAST_DUE]))
            ->assertSee('2222')->assertDontSee('1111');

        $this->get(route('redsys.subscriptions.panel.list', ['q' => '1111']))
            ->assertSee('1111')->assertDontSee('2222');
    }

    public function test_it_sorts_by_the_columns_it_allows_and_ignores_the_rest(): void
    {
        $this->allowEveryone();
        $cheap = $this->subscription(['amount_in_cents' => 500]);
        $dear  = $this->subscription(['amount_in_cents' => 9000]);

        $asc = $this->get(route('redsys.subscriptions.panel.list', ['orden' => 'importe', 'dir' => 'asc']))->getContent();
        $this->assertLessThan(strpos($asc, "Nº {$dear->id}"), strpos($asc, "Nº {$cheap->id}"));

        // El orden llega por la URL y acaba en la consulta: solo pasa la lista blanca.
        $this->get(route('redsys.subscriptions.panel.list', ['orden' => 'amount_in_cents) --']))
            ->assertOk();
    }

    public function test_cancelling_from_the_panel_respects_the_paid_period(): void
    {
        $this->allowEveryone();
        $s = $this->subscription();

        $this->post(route('redsys.subscriptions.panel.cancel', $s))->assertRedirect();

        $s->refresh();
        $this->assertSame(Subscription::CANCELED, $s->status);
        $this->assertTrue($s->onGracePeriod(), 'ya habia pagado el periodo');
    }

    public function test_cancelling_needs_the_gate_too(): void
    {
        $s = $this->subscription();

        $this->post(route('redsys.subscriptions.panel.cancel', $s))->assertForbidden();

        $this->assertSame(Subscription::ACTIVE, $s->refresh()->status);
    }

    public function test_charging_from_the_panel_applies_the_outcome(): void
    {
        $this->allowEveryone();
        $s = $this->subscription(['status' => Subscription::PAST_DUE, 'failures' => 1]);

        $this->app->instance(RedsysGateway::class, new class ('999', 'k', 1) extends RedsysGateway {
            public function chargeStoredCard(Subscription $subscription, string $order): ChargeResult
            {
                return new ChargeResult(ChargeOutcome::Authorized, '0000');
            }
        });

        $this->post(route('redsys.subscriptions.panel.charge', $s))->assertRedirect();

        $s->refresh();
        $this->assertSame(Subscription::ACTIVE, $s->status);
        $this->assertSame(0, $s->failures);
    }

    public function test_it_does_not_try_to_charge_a_subscription_without_a_card(): void
    {
        $this->allowEveryone();
        $s = $this->subscription(['card_token' => null, 'status' => Subscription::INCOMPLETE]);

        // Sin gateway registrado: si lo intentara, el test reventaria al llamarlo.
        $this->post(route('redsys.subscriptions.panel.charge', $s))->assertRedirect();

        $this->assertSame(Subscription::INCOMPLETE, $s->refresh()->status);
    }
}
