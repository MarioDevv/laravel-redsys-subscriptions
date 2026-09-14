<?php

declare(strict_types=1);

namespace MarioDevv\CashierRedsys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $status
 * @property int $failures
 */
class Subscription extends Model
{
    public const ACTIVE      = 'active';
    public const PAST_DUE    = 'past_due';      // fallo reintentable
    public const PAST_DUE_SCA = 'past_due_sca'; // necesita al titular
    public const CANCELED    = 'canceled';
    public const INCOMPLETE  = 'incomplete';    // aun sin tarjeta

    /** Reintentos antes de darla por perdida. */
    public const MAX_FAILURES = 3;

    protected $table = 'redsys_subscriptions';

    protected $guarded = [];

    protected $casts = [
        'amount_in_cents' => 'integer',
        'failures'        => 'integer',
        'next_charge_at'  => 'datetime',
        'ends_at'         => 'datetime',
    ];

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    public function active(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * Aplica el resultado de un cobro. Aqui vive toda la logica de estados.
     */
    public function recordCharge(ChargeOutcome $outcome): void
    {
        match ($outcome) {
            ChargeOutcome::Authorized => $this->fill([
                'status'         => self::ACTIVE,
                'failures'       => 0,
                'next_charge_at' => $this->nextChargeDate(),
            ]),

            // El titular tiene que volver y autenticar. Reintentar solo no sirve
            // de nada, pero la tarjeta sigue viva: no se cancela.
            ChargeOutcome::ScaRequired => $this->fill([
                'status' => self::PAST_DUE_SCA,
            ]),

            // La referencia ya no existe. Reintentar es tirar peticiones.
            ChargeOutcome::TokenDead => $this->fill([
                'status'     => self::CANCELED,
                'card_token' => null,
                'ends_at'    => now(),
            ]),

            ChargeOutcome::Declined => $this->fill([
                'status'   => ++$this->failures >= self::MAX_FAILURES ? self::CANCELED : self::PAST_DUE,
                'failures' => $this->failures,
                'ends_at'  => $this->failures >= self::MAX_FAILURES ? now() : null,
            ]),
        };

        $this->save();
    }

    public function nextChargeDate(): \DateTimeInterface
    {
        $from = $this->next_charge_at?->isFuture() ? $this->next_charge_at : now();

        return match ($this->interval) {
            'yearly'  => $from->copy()->addYear(),
            'weekly'  => $from->copy()->addWeek(),
            default   => $from->copy()->addMonth(),
        };
    }

    public function scopeDue($query)
    {
        return $query->whereIn('status', [self::ACTIVE, self::PAST_DUE])
            ->whereNotNull('card_token')
            ->where('next_charge_at', '<=', now());
    }
}
