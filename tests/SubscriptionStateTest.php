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

    public function test_cancelling_keeps_the_access_already_paid_for(): void
    {
        $s = $this->subscription(['next_charge_at' => now()->addDays(12)]);

        $s->cancel();

        $this->assertSame(Subscription::CANCELED, $s->status);
        $this->assertTrue($s->onGracePeriod());
        $this->assertTrue($s->valid(), 'pago el mes entero: el acceso le dura lo pagado');
        $this->assertTrue($s->ends_at->isSameDay(now()->addDays(12)));
        $this->assertCount(0, Subscription::query()->due()->get(), 'y no se le vuelve a cobrar');
    }

    public function test_cancelling_now_cuts_the_access_immediately(): void
    {
        $s = $this->subscription(['next_charge_at' => now()->addDays(12)]);

        $s->cancelNow();

        $this->assertFalse($s->onGracePeriod());
        $this->assertFalse($s->valid());
    }

    public function test_billable_still_counts_a_cancelled_subscription_in_its_grace_period(): void
    {
        $user = new class extends \Illuminate\Database\Eloquent\Model {
            use \MarioDevv\RedsysSubscriptions\Billable;

            protected $table = 'users';
        };
        $user->id = 7;
        $user->exists = true;

        $s = $this->subscription([
            'billable_type'  => $user::class,
            'billable_id'    => 7,
            'next_charge_at' => now()->addWeek(),
        ]);
        $s->cancel();

        $this->assertTrue($user->subscribed(), 'cortarle el acceso el dia de la baja es quedarse su dinero');
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
