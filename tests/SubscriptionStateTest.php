<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use Illuminate\Support\Carbon;
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

    /**
     * addMonth() sobre un 31 de enero se iba al 3 de marzo: al sumar un mes a
     * una fecha que no existe en febrero, Carbon sigue contando. El dia de
     * cobro del titular se muda solo y ya no vuelve.
     */
    public function test_a_monthly_charge_does_not_jump_over_the_short_month(): void
    {
        Carbon::setTestNow('2026-01-31 10:00');
        $s = $this->subscription(['next_charge_at' => Carbon::parse('2026-01-31 10:00')]);

        $s->recordCharge(ChargeOutcome::Authorized, 'enero', '0000');

        $this->assertSame('2026-02-28', $s->refresh()->next_charge_at->toDateString());
    }

    /**
     * En el ciclo recurrente next_charge_at siempre esta en el pasado, que es
     * justo por lo que la suscripcion entra en due(). Anclar en now() alargaba
     * cada periodo lo que tardara el pase: doce ciclos, casi dos semanas de
     * servicio regalado.
     */
    public function test_the_charge_date_keeps_its_hour_when_the_pass_runs_late(): void
    {
        $s = $this->subscription(['next_charge_at' => Carbon::parse('2026-03-10 14:00')]);

        // El cron pasa a las 03:00 del dia siguiente, trece horas tarde.
        Carbon::setTestNow('2026-03-11 03:00');

        $s->recordCharge(ChargeOutcome::Authorized, 'marzo', '0000');

        $this->assertSame('2026-04-10 14:00', $s->refresh()->next_charge_at->format('Y-m-d H:i'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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

    public function test_a_decline_waits_days_before_trying_again(): void
    {
        $s = $this->subscription(['next_charge_at' => now()->subDay()]);

        $s->recordCharge(ChargeOutcome::Declined);

        // Sin esto la suscripcion sigue vencida y el siguiente pase del cron
        // la vuelve a cobrar: tres intentos contra el mismo saldo vacio.
        $this->assertTrue($s->next_charge_at->isFuture());
        $this->assertTrue($s->next_charge_at->isSameDay(now()->addDays(Subscription::RETRY_DAYS)));
    }

    public function test_a_subscription_waiting_for_its_retry_is_not_due(): void
    {
        $s = $this->subscription(['next_charge_at' => now()->subDay()]);

        $this->assertSame(1, Subscription::query()->due()->count());

        $s->recordCharge(ChargeOutcome::Declined);

        $this->assertSame(0, Subscription::query()->due()->count());
    }

    public function test_the_retry_comes_back_when_the_wait_is_over(): void
    {
        $s = $this->subscription(['next_charge_at' => now()->subDay()]);
        $s->recordCharge(ChargeOutcome::Declined);

        $this->travel(Subscription::RETRY_DAYS + 1)->days();

        $this->assertSame(1, Subscription::query()->due()->count());
    }

    public function test_a_merchant_error_never_touches_the_subscription(): void
    {
        $s = $this->subscription(['failures' => 2, 'next_charge_at' => now()->subDay()]);
        $before = $s->next_charge_at;

        // Tu clave mal puesta le sale igual a todas. Si contara como fallo,
        // este tercero cancelaria a un cliente que paga.
        $s->recordCharge(ChargeOutcome::MerchantError, '1', 'SIS0042');

        $s->refresh();
        $this->assertSame(Subscription::ACTIVE, $s->status);
        $this->assertSame(2, $s->failures);
        $this->assertTrue($before->equalTo($s->next_charge_at));
    }

    public function test_an_unavailable_issuer_does_not_burn_an_attempt(): void
    {
        $s = $this->subscription(['failures' => 1, 'next_charge_at' => now()->subDay()]);

        $s->recordCharge(ChargeOutcome::Unavailable, '1', '0912');

        $s->refresh();
        $this->assertSame(1, $s->failures);
        // Sigue vencida: se reintenta en el siguiente pase, sin esperar tres dias.
        $this->assertSame(1, Subscription::query()->due()->count());
    }

    public function test_an_expired_card_is_not_retried_for_nine_days(): void
    {
        $s = $this->subscription();

        $s->recordCharge(ChargeOutcome::fromRedsys('0101'), '1', '0101');

        $s->refresh();
        $this->assertSame(Subscription::CANCELED, $s->status);
        $this->assertNull($s->card_token);
    }

    public function test_both_still_leave_a_row_in_the_history(): void
    {
        $s = $this->subscription();

        $s->recordCharge(ChargeOutcome::MerchantError, '1', 'SIS0042');
        $s->recordCharge(ChargeOutcome::Unavailable, '2', '0912');

        // No cuentan contra el cliente, pero hay que poder verlos.
        $this->assertSame(2, $s->charges()->count());
    }
}
