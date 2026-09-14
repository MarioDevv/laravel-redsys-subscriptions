<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use Creagia\Redsys\Support\Signature;
use MarioDevv\RedsysSubscriptions\Sha512Signature;
use MarioDevv\RedsysSubscriptions\Subscription;

/**
 * El alta de tarjeta. La notificacion servidor-a-servidor manda; la vuelta del
 * navegador puede no traer nada y aun asi tiene que funcionar.
 */
final class CardRegistrationTest extends TestCase
{
    private const KEY = 'clave-de-pruebas-solo-para-el-test';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('redsys-subscriptions.secret_key', self::KEY);
        $app['config']->set('redsys-subscriptions.merchant_code', '999008881');
        $app['config']->set('redsys-subscriptions.terminal', 1);
        $app['config']->set('redsys-subscriptions.return_url', '/gracias');
    }

    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::create(array_merge([
            'billable_type'    => 'App\\Models\\User',
            'billable_id'      => 1,
            'amount_in_cents'  => 1500,
            'status'           => Subscription::INCOMPLETE,
            'checkout_order'   => '890123456701',
        ], $attributes));
    }

    /** Lo que devuelve Redsys tras un alta COF autorizada (capturado del sandbox). */
    private function params(string $order, array $overrides = []): array
    {
        return array_merge([
            'Ds_Date'                => '14/09/2026',
            'Ds_Amount'              => '1500',
            'Ds_Currency'            => '978',
            'Ds_Order'               => $order,
            'Ds_MerchantCode'        => '999008881',
            'Ds_Terminal'            => '001',
            'Ds_Response'            => '0000',
            'Ds_SecurePayment'       => '1',
            'Ds_TransactionType'     => '0',
            'Ds_AuthorisationCode'   => '195141',
            'Ds_Card_Number'         => '454881******0004',
            'Ds_ExpiryDate'          => '4912',
            'Ds_Merchant_Identifier' => '2261fd1a11acf23738ae3c40147cf24f81d889fc',
            'Ds_Merchant_Cof_Txnid'  => '2609141137280',
        ], $overrides);
    }

    /** El cuerpo del POST, firmado como lo firma el TPV. */
    private function payload(array $params, string $version = 'HMAC_SHA256_V1'): array
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode($params)), '+/', '-_'), '=');
        $order   = (string) ($params['Ds_Order'] ?? $params['DS_ORDER']);

        return [
            'Ds_SignatureVersion'   => $version,
            'Ds_MerchantParameters' => $encoded,
            'Ds_Signature'          => $version === 'HMAC_SHA512_V2'
                ? Sha512Signature::sign($encoded, $order, self::KEY)
                : Signature::calculateSignature($encoded, $order, self::KEY),
        ];
    }

    /** El pedido es el unico hilo entre la suscripcion y lo que Redsys
     *  devuelve: si el enviado no es el guardado, la notificacion llega y no
     *  encuentra a quien activar. */
    public function test_the_registration_form_sends_the_order_that_was_saved(): void
    {
        $s = $this->subscription(['checkout_order' => null]);

        preg_match('/name="Ds_MerchantParameters" value="([^"]+)"/', $s->cardRegistrationForm(), $form);
        $sent = json_decode(base64_decode($form[1]), true);

        $this->assertNotNull($s->refresh()->checkout_order);
        $this->assertSame($s->checkout_order, $sent['DS_MERCHANT_ORDER']);
        $this->assertStringContainsString('redsys/subscriptions/notify', $sent['DS_MERCHANT_MERCHANTURL']);
        $this->assertStringContainsString('redsys/subscriptions/return/' . $s->id, $sent['DS_MERCHANT_URLOK']);
        $this->assertStringContainsString('ko=1', $sent['DS_MERCHANT_URLKO']);
    }

    public function test_notification_stores_the_card_and_activates(): void
    {
        $s = $this->subscription();

        $this->post(route('redsys.subscriptions.notify'), $this->payload($this->params($s->checkout_order)))
            ->assertOk();

        $s->refresh();
        $this->assertSame(Subscription::ACTIVE, $s->status);
        $this->assertSame('2261fd1a11acf23738ae3c40147cf24f81d889fc', $s->card_token);
        $this->assertSame('2609141137280', $s->cof_transaction_id);
        $this->assertSame('0004', $s->card_last_four);
        $this->assertSame('4912', $s->card_expiry);
        $this->assertNotNull($s->next_charge_at, 'sin fecha no entra en el scope due()');
    }

    /** El TPV de cada comercio elige version de firma; y Redsys no es constante
     *  con las mayusculas de los Ds_ segun por donde llegue. */
    public function test_notification_signed_with_sha512_and_uppercase_keys_is_accepted(): void
    {
        $s = $this->subscription();
        $params = array_change_key_case($this->params($s->checkout_order), CASE_UPPER);

        $this->post(route('redsys.subscriptions.notify'), $this->payload($params, 'HMAC_SHA512_V2'))
            ->assertOk();

        $this->assertSame(Subscription::ACTIVE, $s->refresh()->status);
    }

    public function test_a_tampered_signature_is_rejected_and_nothing_is_stored(): void
    {
        $s = $this->subscription();
        $payload = $this->payload($this->params($s->checkout_order));
        $payload['Ds_Signature'] = 'AAAA' . substr($payload['Ds_Signature'], 4);

        $this->post(route('redsys.subscriptions.notify'), $payload)->assertForbidden();

        $s->refresh();
        $this->assertSame(Subscription::INCOMPLETE, $s->status);
        $this->assertNull($s->card_token);
        $this->assertSame(0, $s->failures, 'una firma falsa no es un pago fallido, es basura');
    }

    public function test_tampered_parameters_are_rejected(): void
    {
        $s = $this->subscription();
        $payload = $this->payload($this->params($s->checkout_order, ['Ds_Response' => '0190']));
        // La firma buena del rechazo, pero los parametros dicen ahora que autorizo.
        $payload['Ds_MerchantParameters'] = $this->payload($this->params($s->checkout_order))['Ds_MerchantParameters'];

        $this->post(route('redsys.subscriptions.notify'), $payload)->assertForbidden();

        $this->assertNull($s->refresh()->card_token);
    }

    public function test_an_unknown_signature_version_is_rejected(): void
    {
        $s = $this->subscription();
        $payload = $this->payload($this->params($s->checkout_order));
        $payload['Ds_SignatureVersion'] = 'HMAC_SHA1_V0';

        $this->post(route('redsys.subscriptions.notify'), $payload)->assertForbidden();

        $this->assertNull($s->refresh()->card_token);
    }

    /** 'Enviar parametros en las URLs' = NO: el titular vuelve con las manos
     *  vacias y quien activa la suscripcion es la notificacion. */
    public function test_the_browser_return_without_parameters_only_redirects(): void
    {
        $s = $this->subscription();

        $this->get(route('redsys.subscriptions.return', $s))->assertRedirect('/gracias');

        $s->refresh();
        $this->assertSame(Subscription::INCOMPLETE, $s->status);
        $this->assertNull($s->card_token);

        // Y cuando llega la notificacion, se activa igual.
        $this->post(route('redsys.subscriptions.notify'), $this->payload($this->params($s->checkout_order)))->assertOk();
        $this->assertSame(Subscription::ACTIVE, $s->refresh()->status);
    }

    /** Con los parametros activados, la vuelta del navegador vale por si sola:
     *  es lo unico que funciona en local, donde Redsys no puede notificar. */
    public function test_the_browser_return_with_signed_parameters_activates(): void
    {
        $s = $this->subscription();

        $this->post(route('redsys.subscriptions.return', $s), $this->payload($this->params($s->checkout_order)))
            ->assertRedirect('/gracias');

        $this->assertSame(Subscription::ACTIVE, $s->refresh()->status);
    }

    public function test_the_return_ignores_parameters_of_another_subscription(): void
    {
        $mine    = $this->subscription();
        $someone = $this->subscription(['checkout_order' => '890123456702']);

        // Parametros autenticos, pero de otro pedido, y el id de la URL cambiado.
        $this->post(route('redsys.subscriptions.return', $mine), $this->payload($this->params($someone->checkout_order)))
            ->assertRedirect('/gracias');

        $this->assertNull($mine->refresh()->card_token);
        $this->assertNull($someone->refresh()->card_token);
    }

    public function test_a_second_notification_does_not_move_the_charge_date(): void
    {
        $s = $this->subscription();
        $payload = $this->payload($this->params($s->checkout_order));

        $this->post(route('redsys.subscriptions.notify'), $payload)->assertOk();
        $first = $s->refresh()->next_charge_at;

        $this->post(route('redsys.subscriptions.notify'), $payload)->assertOk();

        $this->assertTrue($first->equalTo($s->refresh()->next_charge_at), 'un mes gratis por un reintento de Redsys');
    }

    public function test_a_denied_registration_leaves_it_incomplete(): void
    {
        $s = $this->subscription();

        $this->post(route('redsys.subscriptions.notify'), $this->payload($this->params($s->checkout_order, [
            'Ds_Response'            => '0190',
            'Ds_Merchant_Identifier' => '',
        ])))->assertOk();

        $s->refresh();
        $this->assertSame(Subscription::INCOMPLETE, $s->status, 'puede reintentar el alta');
        $this->assertNull($s->card_token);
    }

    public function test_an_authorized_payment_without_a_card_reference_does_not_activate(): void
    {
        // Pago bueno pero sin tokenizar: activarla la dejaria cobrando a nadie.
        $s = $this->subscription();

        $this->post(route('redsys.subscriptions.notify'), $this->payload($this->params($s->checkout_order, [
            'Ds_Merchant_Identifier' => '',
        ])))->assertOk();

        $this->assertSame(Subscription::INCOMPLETE, $s->refresh()->status);
    }
}
