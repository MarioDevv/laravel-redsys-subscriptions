<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use MarioDevv\RedsysSubscriptions\Subscription;

/**
 * La maquina de estados es el producto. Las transiciones salen de lo observado
 * contra el sandbox de Redsys, no de suposiciones.
 */
final class SubscriptionStateTest extends TestCase
{
    private function subscription(array $attributes = []): Subscription
    {
        return Subscription::create(array_merge([
            'billable_type'    => 'App\\Models\\User',
            'billable_id'      => 1,
            'card_token'       => 'abc123',
            'amount_in_cents'  => 1500,
            'status'           => Subscription::ACTIVE,
            'next_charge_at'   => now()->subDay(),
        ], $attributes));
    }

    public function test_authorized_charge_keeps_it_active_and_moves_the_date(): void
    {
        $s = $this->subscription(['failures' => 2]);
        $before = $s->next_charge_at;

        $s->recordCharge(ChargeOutcome::Authorized);

        $this->assertSame(Subscription::ACTIVE, $s->status);
        $this->assertSame(0, $s->failures, 'un cobro bueno limpia los fallos');
        $this->assertTrue($s->next_charge_at->greaterThan($before));
    }

    public function test_sca_does_not_cancel_the_subscription(): void
    {
        // Comprobado en el sandbox: tras un 0195 la tarjeta sigue cobrando.
        $s = $this->subscription();

        $s->recordCharge(ChargeOutcome::ScaRequired);

        $this->assertSame(Subscription::PAST_DUE_SCA, $s->status);
        $this->assertNotNull($s->card_token, 'la tarjeta sigue viva');
        $this->assertNull($s->ends_at);
    }

    public function test_dead_token_cancels_and_drops_the_card(): void
    {
        $s = $this->subscription();

        $s->recordCharge(ChargeOutcome::TokenDead);

        $this->assertSame(Subscription::CANCELED, $s->status);
        $this->assertNull($s->card_token, 'reintentar con una referencia muerta es tirar peticiones');
    }

    public function test_declines_accumulate_until_the_limit(): void
    {
        $s = $this->subscription();

        $s->recordCharge(ChargeOutcome::Declined);
        $this->assertSame(Subscription::PAST_DUE, $s->status);
        $this->assertSame(1, $s->failures);

        $s->recordCharge(ChargeOutcome::Declined);
        $this->assertSame(Subscription::PAST_DUE, $s->status);

        $s->recordCharge(ChargeOutcome::Declined);
        $this->assertSame(Subscription::CANCELED, $s->status, 'al tercer fallo se corta');
    }

    public function test_due_scope_ignores_sca_and_cancelled(): void
    {
        $this->subscription(['status' => Subscription::ACTIVE]);
        $this->subscription(['status' => Subscription::PAST_DUE]);
        $this->subscription(['status' => Subscription::PAST_DUE_SCA]);
        $this->subscription(['status' => Subscription::CANCELED]);
        $this->subscription(['status' => Subscription::ACTIVE, 'card_token' => null]);
        $this->subscription(['status' => Subscription::ACTIVE, 'next_charge_at' => now()->addWeek()]);

        $this->assertCount(2, Subscription::query()->due()->get());
    }
}
