<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de cobro, tal y como lo contesto Redsys.
 *
 * Se escribe siempre, salga bien o mal: el estado de la suscripcion dice como
 * esta ahora, y esto dice como ha llegado hasta ahi. Sin ello, un cliente que
 * llama preguntando por un cargo no tiene respuesta.
 *
 * @property string $order
 * @property ?string $response_code
 * @property int $amount_in_cents
 */
class Charge extends Model
{
    protected $table = 'redsys_subscription_charges';

    /** Un intento no se modifica nunca. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'amount_in_cents' => 'integer',
        'outcome'         => ChargeOutcome::class,
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function amountLabel(): string
    {
        return number_format($this->amount_in_cents / 100, 2, ',', '.') . ' €';
    }

    /**
     * Lo que se lee en el panel. El codigo va delante porque es lo que se
     * teclea en el buscador de Redsys cuando hay que reclamar algo.
     */
    public function responseLabel(): string
    {
        $what = match ($this->outcome) {
            ChargeOutcome::Authorized  => 'Autorizado',
            ChargeOutcome::ScaRequired => 'El banco pide que el titular autentique',
            ChargeOutcome::TokenDead   => 'La referencia de la tarjeta ya no vale',
            ChargeOutcome::Declined    => 'Denegada',
        };

        return $this->response_code ? "{$this->response_code} · {$what}" : $what;
    }

    /**
     * El tono con el que se pinta la fila. No es el estado de la suscripcion:
     * un intento con SCA se pinta de espera aunque la suscripcion siga activa.
     */
    public function tone(): string
    {
        return match ($this->outcome) {
            ChargeOutcome::Authorized  => 'ok',
            ChargeOutcome::ScaRequired => 'wait',
            ChargeOutcome::TokenDead   => 'off',
            ChargeOutcome::Declined    => 'warn',
        };
    }
}
