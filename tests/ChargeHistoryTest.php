<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MarioDevv\RedsysSubscriptions\Charge;
use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use MarioDevv\RedsysSubscriptions\Subscription;

/**
 * El estado dice como esta la suscripcion ahora; el historial dice como ha
 * llegado hasta ahi. Es lo que se mira cuando un cliente pregunta por un cargo.
 */
final class ChargeHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::create(array_merge([
            'billable_type'   => 'App\\Models\\User',
            'billable_id'     => 1,
            'amount_in_cents' => 1900,
            'status'          => Subscription::ACTIVE,
            'card_token'      => 'ref',
            'next_charge_at'  => now(),
        ], $attributes));
    }

    public function test_every_attempt_leaves_a_row_win_or_lose(): void
    {
        $s = $this->subscription();

        $s->recordCharge(ChargeOutcome::Authorized, '90714301', '0000');
        $s->recordCharge(ChargeOutcome::Declined, '90714302', '0180');

        $this->assertSame(2, $s->charges()->count());
    }

    public function test_it_keeps_the_code_that_explains_the_failure(): void
    {
        $s = $this->subscription();

        // Sin el codigo, el panel solo puede decir «denegada»: no distingue
        // unos fondos insuficientes de una firma mal configurada.
        $s->recordCharge(ChargeOutcome::Declined, '90714302', '0180');

        $charge = $s->charges()->first();

        $this->assertSame('0180', $charge->response_code);
        $this->assertSame(ChargeOutcome::Declined, $charge->outcome);
        $this->assertSame('0180 · Denegada', $charge->responseLabel());
    }

    public function test_the_newest_attempt_comes_first(): void
    {
        $s = $this->subscription();

        $s->recordCharge(ChargeOutcome::Declined, 'viejo', '0180');
        $this->travel(1)->minutes();
        $s->recordCharge(ChargeOutcome::Authorized, 'nuevo', '0000');

        $this->assertSame('nuevo', $s->charges()->first()->order);
    }

    public function test_the_amount_charged_is_frozen_on_the_row(): void
    {
        $s = $this->subscription(['amount_in_cents' => 1900]);
        $s->recordCharge(ChargeOutcome::Authorized, '90714301', '0000');

        // Si manana sube el precio, lo que se cobro aquel dia no cambia.
        $s->update(['amount_in_cents' => 2900]);

        $this->assertSame(1900, $s->charges()->first()->amount_in_cents);
    }

    public function test_the_card_registration_is_the_first_line_of_the_history(): void
    {
        $s = $this->subscription(['status' => Subscription::INCOMPLETE, 'card_token' => null]);
        $s->update(['checkout_order' => '90521004']);

        $s->completeCheckout([
            'DS_RESPONSE'            => '0000',
            'DS_ORDER'               => '90521004',
            'DS_MERCHANT_IDENTIFIER' => 'ref-nueva',
            'DS_CARD_NUMBER'         => '454881******4921',
            'DS_EXPIRYDATE'          => '2903',
        ]);

        $charge = $s->charges()->first();

        $this->assertSame('90521004', $charge->order);
        $this->assertSame(ChargeOutcome::Authorized, $charge->outcome);
    }

    public function test_a_denied_registration_does_not_invent_a_charge(): void
    {
        $s = $this->subscription(['status' => Subscription::INCOMPLETE, 'card_token' => null]);

        $s->completeCheckout(['DS_RESPONSE' => '0190', 'DS_ORDER' => '90521004']);

        $this->assertSame(0, $s->charges()->count());
    }

    public function test_deleting_a_subscription_takes_its_history_with_it(): void
    {
        $s = $this->subscription();
        $s->recordCharge(ChargeOutcome::Authorized, '90714301', '0000');

        $s->delete();

        $this->assertSame(0, Charge::query()->count());
    }

    public function test_the_tone_separates_waiting_from_failing(): void
    {
        $s = $this->subscription();

        // 0195 no es un fallo de tarjeta: no se pinta como una denegacion.
        $s->recordCharge(ChargeOutcome::ScaRequired, '1', '0195');
        $s->recordCharge(ChargeOutcome::Declined, '2', '0180');

        $tones = $s->charges()->get()->map(fn (Charge $c) => $c->tone())->all();

        $this->assertSame(['warn', 'wait'], $tones);
    }
}
