<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Events;

use MarioDevv\RedsysSubscriptions\Charge;
use MarioDevv\RedsysSubscriptions\Subscription;

/**
 * Se ha intentado un cobro. Sale siempre, salga bien o mal.
 *
 * Es un evento y no seis porque lo unico que tu aplicacion no sabe es lo que
 * hizo el cron a las tres de la manana: cuando ella misma llama a cancel() o a
 * newSubscription(), ya se ha enterado. Quien escuche mira el resultado:
 *
 *     match ($event->charge->outcome) {
 *         ChargeOutcome::Declined    => // avisar de que la tarjeta ha fallado
 *         ChargeOutcome::ScaRequired => // mandarle a autenticar
 *         ChargeOutcome::TokenDead   => // mandarle cardUpdateLink()
 *         ChargeOutcome::Authorized  => // recibo
 *     };
 *
 * El estado de la suscripcion ya esta aplicado cuando llega esto, asi que
 * $subscription->status dice si ademas se ha quedado cancelada.
 */
final readonly class SubscriptionCharged
{
    public function __construct(
        public Subscription $subscription,
        public Charge $charge,
    ) {}
}
