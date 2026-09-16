<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Billable
{
    public function redsysSubscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'billable');
    }

    public function subscription(): ?Subscription
    {
        return $this->redsysSubscriptions()->latest('id')->first();
    }

    /** Incluye a quien se dio de baja pero aun tiene periodo pagado. */
    public function subscribed(): bool
    {
        return (bool) $this->subscription()?->valid();
    }

    /**
     * Crea la suscripcion en estado incomplete. Se activa cuando vuelve el
     * pago inicial con la referencia de tarjeta.
     */
    public function newSubscription(int $amountInCents, string $interval = 'monthly'): Subscription
    {
        return $this->redsysSubscriptions()->create([
            'amount_in_cents' => $amountInCents,
            'interval'        => $interval,
            'status'          => Subscription::INCOMPLETE,
        ]);
    }
}
