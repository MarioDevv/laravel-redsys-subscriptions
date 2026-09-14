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

    public function subscription(string $name = 'default'): ?Subscription
    {
        return $this->redsysSubscriptions()->where('name', $name)->latest('id')->first();
    }

    public function subscribed(string $name = 'default'): bool
    {
        return (bool) $this->subscription($name)?->active();
    }

    /**
     * Crea la suscripcion en estado incomplete. Se activa cuando vuelve el
     * pago inicial con la referencia de tarjeta.
     */
    public function newSubscription(int $amountInCents, string $interval = 'monthly', string $name = 'default'): Subscription
    {
        return $this->redsysSubscriptions()->create([
            'name'            => $name,
            'amount_in_cents' => $amountInCents,
            'interval'        => $interval,
            'status'          => Subscription::INCOMPLETE,
        ]);
    }
}
