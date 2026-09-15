<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

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

    /**
     * Como se llaman los estados de cara a quien mira el panel. El valor que se
     * guarda no cambia: esto es solo la etiqueta.
     *
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::ACTIVE       => 'Activa',
            self::PAST_DUE     => 'Reintentando',
            self::PAST_DUE_SCA => 'Pendiente del titular',
            self::CANCELED     => 'Cancelada',
            self::INCOMPLETE   => 'Sin terminar',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    /** El importe como se enseña y como se dice por telefono: «19,00 €». */
    public function amountLabel(): string
    {
        return number_format($this->amount_in_cents / 100, 2, ',', '.') . ' €';
    }

    public function active(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * Formulario del alta de tarjeta. El pedido se guarda aqui porque es el
     * unico hilo que une esta suscripcion con lo que Redsys devuelva despues:
     * si lo genera quien llama, la notificacion no sabe a quien activar.
     */
    public function cardRegistrationForm(): string
    {
        $this->update(['checkout_order' => $order = $this->newOrder()]);

        return app(RedsysGateway::class)->cardRegistrationForm(
            amountInCents: $this->amount_in_cents,
            order:         $order,
            urlOk:         route('redsys.subscriptions.return', $this),
            urlKo:         route('redsys.subscriptions.return', [$this, 'ko' => 1]),
            notifyUrl:     route('redsys.subscriptions.notify'),
        );
    }

    /**
     * Alta confirmada por Redsys. Los parametros llegan ya verificados: quien
     * llama a esto ha comprobado la firma antes.
     *
     * @param array<string, mixed> $params Ds_* en MAYUSCULAS.
     */
    public function completeCheckout(array $params): void
    {
        // Un alta denegada no cancela nada: sigue incomplete y el titular puede
        // reintentar. Sin referencia de tarjeta tampoco se activa, o quedaria
        // activa sin poder cobrar e invisible para el scope due(). Y si ya esta
        // activa, no se toca: la notificacion y la vuelta del navegador pueden
        // llegar las dos, y la segunda no debe mover la fecha de cobro.
        if (
            ChargeOutcome::fromRedsys($params['DS_RESPONSE'] ?? null) !== ChargeOutcome::Authorized
            || empty($params['DS_MERCHANT_IDENTIFIER'])
            || $this->active()
        ) {
            return;
        }

        $this->fill([
            'card_token'         => $params['DS_MERCHANT_IDENTIFIER'],
            'cof_transaction_id' => $params['DS_MERCHANT_COF_TXNID'] ?? null,
            'card_last_four'     => substr((string) ($params['DS_CARD_NUMBER'] ?? ''), -4) ?: null,
            'card_expiry'        => $params['DS_EXPIRYDATE'] ?? null,
        ]);

        $this->recordCharge(ChargeOutcome::Authorized);
    }

    /**
     * Baja al final del periodo ya pagado. Deja de cobrarse desde ya, pero el
     * titular conserva el acceso hasta la fecha que le quedaba: le cobraste el
     * mes entero y cortarle el dia que se da de baja es quedarse su dinero.
     */
    public function cancel(): void
    {
        $this->update([
            'status'  => self::CANCELED,
            'ends_at' => $this->next_charge_at ?? now(),
        ]);
    }

    /** Baja inmediata, sin el resto del periodo. */
    public function cancelNow(): void
    {
        $this->update(['status' => self::CANCELED, 'ends_at' => now()]);
    }

    /** Cancelada, pero todavia dentro de lo que ya pago. */
    public function onGracePeriod(): bool
    {
        return $this->status === self::CANCELED && (bool) $this->ends_at?->isFuture();
    }

    /** Si da derecho al servicio. Es lo que hay que mirar para dar acceso. */
    public function valid(): bool
    {
        return $this->active() || $this->onGracePeriod();
    }

    /**
     * Fecha para leer de un vistazo: «mañana» dice mas que «15/10/2026» cuando
     * lo que buscas es un cobro que se te ha pasado. La fecha exacta va en el
     * title del elemento, que es donde se mira cuando importa.
     */
    public static function humanDate(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        $days = (int) now()->startOfDay()->diffInDays(
            \Illuminate\Support\Carbon::instance($date)->startOfDay(),
            false,
        );

        return match (true) {
            $days === 0  => 'hoy',
            $days === 1  => 'mañana',
            $days === -1 => 'ayer',
            $days > 1 && $days <= 30   => "en {$days} días",
            $days < -1 && $days >= -30 => 'hace ' . abs($days) . ' días',
            default => $date->format('j/n/Y'),
        };
    }

    /**
     * Como llamar al titular en el panel, sea cual sea el modelo.
     *
     * Tolera que la clase ya no exista: un modelo renombrado deja filas
     * apuntando a la nada, y eso no puede tumbar el panel entero.
     */
    public function billableName(): string
    {
        $fallback = class_basename($this->billable_type) . ' #' . $this->billable_id;

        if (! class_exists($this->billable_type)) {
            return $fallback;
        }

        $billable = $this->billable;

        return $billable?->name ?? $billable?->email ?? $fallback;
    }

    /** Pedido nuevo. Redsys los quiere de 12 caracteres como mucho, los 4
     *  primeros numericos, y rechaza los repetidos con SIS0051. */
    public function newOrder(): string
    {
        return substr((string) time(), -8) . str_pad((string) ($this->id % 100), 2, '0', STR_PAD_LEFT);
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
