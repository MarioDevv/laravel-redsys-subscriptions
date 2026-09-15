<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use MarioDevv\RedsysSubscriptions\Events\SubscriptionCharged;
use MarioDevv\RedsysSubscriptions\Subscription;

/**
 * Sin evento, la aplicacion no se entera de lo que hizo el cron a las tres de
 * la manana, y el cliente descubre que no le han cobrado cuando pierde acceso.
 */
final class EventsTest extends TestCase
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

    public function test_a_failed_charge_tells_the_application(): void
    {
        Event::fake([SubscriptionCharged::class]);
        $s = $this->subscription();

        $s->recordCharge(ChargeOutcome::Declined, '90714301', '0180');

        Event::assertDispatched(SubscriptionCharged::class, function ($event) use ($s) {
            return $event->subscription->is($s)
                && $event->charge->outcome === ChargeOutcome::Declined
                && $event->charge->response_code === '0180';
        });
    }

    public function test_it_also_fires_when_everything_goes_well(): void
    {
        Event::fake([SubscriptionCharged::class]);

        $this->subscription()->recordCharge(ChargeOutcome::Authorized, '90714301', '0000');

        Event::assertDispatched(SubscriptionCharged::class);
    }

    public function test_the_state_is_already_applied_when_the_event_arrives(): void
    {
        $seen = null;
        Event::listen(SubscriptionCharged::class, function ($event) use (&$seen) {
            $seen = $event->subscription->status;
        });

        $s = $this->subscription(['failures' => Subscription::MAX_FAILURES - 1]);
        $s->recordCharge(ChargeOutcome::Declined, '90714301', '0180');

        // Quien escucha tiene que poder decidir si ademas hay que cortar el
        // acceso, y para eso el estado ya tiene que estar guardado.
        $this->assertSame(Subscription::CANCELED, $seen);
    }

    public function test_the_dead_reference_arrives_as_such(): void
    {
        Event::fake([SubscriptionCharged::class]);

        $this->subscription()->recordCharge(ChargeOutcome::TokenDead, '90714301', 'SIS0321');

        // Es el caso en el que hay que mandarle un cardUpdateLink().
        Event::assertDispatched(SubscriptionCharged::class, fn ($e) => $e->charge->outcome === ChargeOutcome::TokenDead);
    }

    public function test_registering_a_card_is_also_a_charge(): void
    {
        Event::fake([SubscriptionCharged::class]);
        $s = $this->subscription(['status' => Subscription::INCOMPLETE, 'card_token' => null]);

        $s->completeCheckout([
            'DS_RESPONSE'            => '0000',
            'DS_ORDER'               => '90521004',
            'DS_MERCHANT_IDENTIFIER' => 'ref-nueva',
        ]);

        Event::assertDispatched(SubscriptionCharged::class);
    }

    public function test_a_denied_registration_says_nothing(): void
    {
        Event::fake([SubscriptionCharged::class]);
        $s = $this->subscription(['status' => Subscription::INCOMPLETE, 'card_token' => null]);

        $s->completeCheckout(['DS_RESPONSE' => '0190', 'DS_ORDER' => '90521004']);

        // No hubo cobro, asi que no hay nada que contar.
        Event::assertNotDispatched(SubscriptionCharged::class);
    }
}
