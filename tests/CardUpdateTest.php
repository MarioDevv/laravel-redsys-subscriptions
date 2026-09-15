<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use MarioDevv\RedsysSubscriptions\Subscription;

/**
 * Tras un SIS0321 la referencia muere. Sin forma de poner otra tarjeta sobre la
 * misma suscripcion, al cliente hay que darlo de alta de cero y se pierden su
 * numero y su historial.
 */
final class CardUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::create(array_merge([
            'billable_type'   => 'App\\Models\\User',
            'billable_id'     => 1,
            'amount_in_cents' => 1900,
            'status'          => Subscription::ACTIVE,
            'card_token'      => 'vieja',
            'checkout_order'  => '900000000001',
            'next_charge_at'  => now()->addMonth(),
        ], $attributes));
    }

    /** @param array<string, mixed> $overrides */
    private function authorized(string $order, array $overrides = []): array
    {
        return array_merge([
            'DS_RESPONSE'            => '0000',
            'DS_ORDER'               => $order,
            'DS_MERCHANT_IDENTIFIER' => 'nueva-referencia',
            'DS_CARD_NUMBER'         => '454881******9999',
            'DS_EXPIRYDATE'          => '3012',
        ], $overrides);
    }

    public function test_an_active_subscription_can_register_another_card(): void
    {
        // Antes esto se rechazaba solo por estar activa, y era justo el caso de
        // la tarjeta caducada o revocada.
        $s = $this->subscription();

        $s->completeCheckout($this->authorized('900000000002'));

        $this->assertSame('nueva-referencia', $s->refresh()->card_token);
        $this->assertSame('9999', $s->card_last_four);
    }

    public function test_a_dead_reference_can_be_replaced(): void
    {
        $s = $this->subscription(['status' => Subscription::CANCELED, 'card_token' => null]);

        $s->completeCheckout($this->authorized('900000000002'));

        $s->refresh();
        $this->assertSame(Subscription::ACTIVE, $s->status);
        $this->assertSame('nueva-referencia', $s->card_token);
    }

    public function test_the_same_order_is_never_applied_twice(): void
    {
        $s = $this->subscription(['status' => Subscription::INCOMPLETE, 'card_token' => null]);

        $s->completeCheckout($this->authorized('900000000002'));
        $first = $s->refresh()->next_charge_at;

        // La notificacion y la vuelta del navegador pueden llegar las dos.
        $s->completeCheckout($this->authorized('900000000002'));

        $this->assertTrue($first->equalTo($s->refresh()->next_charge_at), 'un mes gratis por un reintento de Redsys');
        $this->assertSame(1, $s->charges()->count());
    }

    public function test_the_subscription_keeps_its_number_and_its_history(): void
    {
        $s = $this->subscription();
        $s->recordCharge(ChargeOutcome::Declined, '900000000001', '0180');
        $id = $s->id;

        $s->completeCheckout($this->authorized('900000000002'));

        $this->assertSame($id, $s->refresh()->id);
        $this->assertSame(2, $s->charges()->count());
    }

    public function test_the_link_is_signed_and_opens_the_form(): void
    {
        $s = $this->subscription();

        $this->get($s->cardUpdateLink())->assertOk();
    }

    public function test_an_unsigned_link_is_rejected(): void
    {
        $s = $this->subscription();

        // Sin firma, cualquiera podria dejar su tarjeta en la suscripcion de otro.
        $this->get(route('redsys.subscriptions.card', $s))->assertForbidden();
    }

    public function test_a_tampered_link_is_rejected(): void
    {
        $s = $this->subscription();
        $link = $s->cardUpdateLink();

        // Cambiar el id de la URL invalida la firma: no se puede colar tarjeta
        // en la suscripcion de otro reusando un enlace propio.
        $this->get(str_replace("/card/{$s->id}", "/card/{$s->id}0", $link))->assertForbidden();
    }

    public function test_the_link_expires(): void
    {
        $s = $this->subscription();
        $link = $s->cardUpdateLink(days: 7);

        $this->travel(8)->days();

        $this->get($link)->assertForbidden();
    }

    public function test_a_fresh_order_is_stored_for_each_attempt(): void
    {
        $s = $this->subscription();
        $before = $s->checkout_order;

        $this->get($s->cardUpdateLink());

        // Redsys rechaza los pedidos repetidos con SIS0051.
        $this->assertNotSame($before, $s->refresh()->checkout_order);
    }
}
