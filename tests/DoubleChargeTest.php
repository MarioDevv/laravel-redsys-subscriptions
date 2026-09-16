<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use Creagia\Redsys\Support\Signature;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use MarioDevv\RedsysSubscriptions\Authorize;
use MarioDevv\RedsysSubscriptions\ChargeDueSubscriptions;
use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use MarioDevv\RedsysSubscriptions\ChargeResult;
use MarioDevv\RedsysSubscriptions\RedsysGateway;
use MarioDevv\RedsysSubscriptions\Subscription;

/**
 * Las tres formas de cobrar dos veces al mismo titular, o de no cobrar y
 * activar igual. Ninguna de las tres se ve mirando el camino feliz.
 */
final class DoubleChargeTest extends TestCase
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
            'status'          => Subscription::ACTIVE,
            'next_charge_at'  => now()->addWeek(),
            'checkout_order'  => '890123456701',
        ], $attributes));
    }

    /** Un alta autorizada, firmada con la clave que se le pase. */
    private function payload(string $order, string $key): array
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode([
            'Ds_Order'               => $order,
            'Ds_Response'            => '0000',
            'Ds_Amount'              => '1500',
            'Ds_MerchantCode'        => '999008881',
            'Ds_Merchant_Identifier' => 'referencia-que-pone-el-atacante',
            'Ds_Card_Number'         => '454881******0004',
            'Ds_ExpiryDate'          => '4912',
        ])), '+/', '-_'), '=');

        return [
            'Ds_SignatureVersion'   => 'HMAC_SHA256_V1',
            'Ds_MerchantParameters' => $encoded,
            'Ds_Signature'          => Signature::calculateSignature($encoded, $order, $key),
        ];
    }

    /**
     * openssl_encrypt no falla con una clave vacia: la rellena de ceros y firma
     * igual. Sin guarda, cualquiera calcula la firma de una notificacion y se
     * da de alta sin pagar, porque la firma es el unico control que hay aqui.
     */
    public function test_without_a_secret_key_nothing_is_accepted(): void
    {
        config(['redsys-subscriptions.secret_key' => null]);

        $s = $this->subscription(['status' => Subscription::INCOMPLETE, 'card_token' => null]);

        $this->post(route('redsys.subscriptions.notify'), $this->payload($s->checkout_order, ''))
            ->assertForbidden();

        $s->refresh();
        $this->assertSame(Subscription::INCOMPLETE, $s->status);
        $this->assertNull($s->card_token);
        $this->assertSame(0, $s->charges()->count());
    }

    /**
     * La notificacion servidor-a-servidor y la vuelta del navegador llegan las
     * dos en cada alta. Si las dos se anotan, la segunda vuelve a mover la
     * fecha y el titular se lleva dos periodos por un solo pago.
     */
    public function test_the_same_order_is_recorded_once_even_if_it_arrives_twice(): void
    {
        $s = $this->subscription(['next_charge_at' => now()]);

        $s->recordCharge(ChargeOutcome::Authorized, 'pedido-repetido', '0000');
        $primeraFecha = $s->refresh()->next_charge_at;

        $s->recordCharge(ChargeOutcome::Authorized, 'pedido-repetido', '0000');

        $this->assertSame(1, $s->charges()->count());
        $this->assertTrue($primeraFecha->equalTo($s->refresh()->next_charge_at), 'El segundo intento ha vuelto a mover la fecha de cobro.');
    }

    /**
     * Si la respuesta se pierde, puede que Redsys haya cobrado. Mandar un
     * pedido nuevo seria cobrar otra vez, y Redsys no puede deduplicar dos
     * pedidos distintos. Repitiendo el mismo, sí: lo rechaza con SIS0051.
     */
    public function test_a_charge_left_without_an_answer_reuses_its_order(): void
    {
        $s = $this->subscription();

        $primero = $s->beginCharge();

        // Nadie llama a recordCharge: es lo que pasa cuando la peticion se cae.
        $this->assertSame($primero, $s->refresh()->pending_order);
        $this->assertSame($primero, $s->beginCharge(), 'El reintento ha estrenado pedido y Redsys ya no puede deduplicar.');

        // Con respuesta de Redsys el pedido deja de estar en el aire.
        $s->recordCharge(ChargeOutcome::Authorized, $primero, '0000');
        $this->assertNull($s->refresh()->pending_order);
    }

    /**
     * SIS0051 solo dice que Redsys ya tenia ese pedido, no como acabo. Soltarlo
     * seria estrenar pedido y arriesgarse a cobrar encima de un cobro bueno.
     */
    public function test_a_repeated_order_stays_pending(): void
    {
        $s = $this->subscription();
        $order = $s->beginCharge();

        $s->recordCharge(ChargeOutcome::Unavailable, $order, 'SIS0051');

        $this->assertSame($order, $s->refresh()->pending_order);
    }

    /**
     * El alta cobra de verdad. Si cada render estrena pedido, el titular que
     * paga desde una pestaña vieja carga el importe con un pedido que ya no
     * está guardado: dinero cobrado y nadie a quien activar. Pagando desde las
     * dos pestañas, dos cargos y una sola suscripción.
     */
    public function test_rendering_the_form_twice_keeps_the_same_order(): void
    {
        $s = $this->subscription(['status' => Subscription::INCOMPLETE, 'card_token' => null, 'checkout_order' => null]);

        $s->cardRegistrationForm();
        $primero = $s->refresh()->checkout_order;

        $s->cardRegistrationForm();

        $this->assertSame($primero, $s->refresh()->checkout_order, 'El segundo formulario ha machacado el pedido del primero.');
    }

    /** Con tarjeta guardada el pedido anterior esta consumido: cambiar de
     *  tarjeta tiene que estrenar uno o Redsys responde SIS0051. */
    public function test_changing_the_card_always_gets_a_fresh_order(): void
    {
        $s = $this->subscription(['checkout_order' => 'viejo-000001']);

        $s->cardRegistrationForm();

        $this->assertNotSame('viejo-000001', $s->refresh()->checkout_order);
    }

    /** Un alta denegada suelta su pedido: Redsys ya lo tiene, y reintentar con
     *  el mismo seria SIS0051, o sea que el titular no podria reintentar. */
    public function test_a_denied_registration_releases_its_order(): void
    {
        $s = $this->subscription(['status' => Subscription::INCOMPLETE, 'card_token' => null, 'checkout_order' => null]);

        $s->cardRegistrationForm();
        $order = $s->refresh()->checkout_order;

        $s->completeCheckout(['DS_ORDER' => $order, 'DS_RESPONSE' => '0180']);

        $this->assertNull($s->refresh()->checkout_order);

        $s->cardRegistrationForm();

        $this->assertNotSame($order, $s->refresh()->checkout_order, 'El reintento repite un pedido que Redsys ya tiene.');
    }

    /**
     * El cron a las 03:00 y soporte pulsando «Cobrar ahora» son dos
     * autorizaciones con pedidos distintos sobre la misma tarjeta, y Redsys no
     * tiene forma de saber que son el mismo cobro.
     */
    public function test_the_panel_does_not_charge_while_a_pass_is_running(): void
    {
        Gate::define(Authorize::GATE, fn ($user = null) => true);

        $s = $this->subscription(['status' => Subscription::PAST_DUE, 'failures' => 1]);

        $this->app->instance(RedsysGateway::class, new class ('999', 'k', 1) extends RedsysGateway {
            public function chargeStoredCard(Subscription $subscription, string $order): ChargeResult
            {
                throw new \LogicException('Ha cobrado con un pase en marcha.');
            }
        });

        Cache::lock(ChargeDueSubscriptions::LOCK, 60)->get();

        $this->post(route('redsys.subscriptions.panel.charge', $s))->assertRedirect();

        $s->refresh();
        $this->assertSame(Subscription::PAST_DUE, $s->status);
        $this->assertSame(0, $s->charges()->count());
    }
}
