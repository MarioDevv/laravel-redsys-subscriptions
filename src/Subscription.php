<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\URL;
use MarioDevv\RedsysSubscriptions\Events\SubscriptionCharged;

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

    /**
     * Dias entre reintentos. Con tres intentos da una ventana de nueve dias,
     * que es lo que hace falta para que entre una nomina. Reintentar el mismo
     * dia es gastar los tres intentos contra el mismo saldo vacio.
     */
    public const RETRY_DAYS = 3;

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

    /** Cada intento de cobro, el mas reciente primero. */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'subscription_id')->latest('created_at')->latest('id');
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
        $order = $params['DS_ORDER'] ?? $this->checkout_order;

        // Un alta denegada no cancela nada: sigue incomplete y el titular puede
        // reintentar. Sin referencia de tarjeta tampoco se activa, o quedaria
        // activa sin poder cobrar e invisible para el scope due().
        //
        // Y un pedido ya procesado no se repite: la notificacion y la vuelta
        // del navegador pueden llegar las dos, y la segunda no debe mover la
        // fecha de cobro. Se mira el pedido y no si esta activa, porque una
        // suscripcion viva tiene que poder registrar otra tarjeta: es lo que
        // hace falta cuando Redsys mata la referencia con SIS0321.
        if (
            ChargeOutcome::fromRedsys($params['DS_RESPONSE'] ?? null) !== ChargeOutcome::Authorized
            || empty($params['DS_MERCHANT_IDENTIFIER'])
            || $this->charges()->where('order', $order)->exists()
        ) {
            return;
        }

        $this->fill([
            'card_token'         => $params['DS_MERCHANT_IDENTIFIER'],
            'cof_transaction_id' => $params['DS_MERCHANT_COF_TXNID'] ?? null,
            'card_last_four'     => substr((string) ($params['DS_CARD_NUMBER'] ?? ''), -4) ?: null,
            'card_expiry'        => $params['DS_EXPIRYDATE'] ?? null,
        ]);

        // El alta tambien es un cobro, y es la primera linea del historial.
        $this->recordCharge(ChargeOutcome::Authorized, $order, $params['DS_RESPONSE'] ?? null);
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

    /**
     * Enlace para que el titular registre otra tarjeta sobre esta misma
     * suscripcion, conservando su numero y su historial.
     *
     * Va firmado y caduca: sin firma seria una direccion que deja a cualquiera
     * meter una tarjeta en la suscripcion de otro.
     */
    public function cardUpdateLink(int $days = 7): string
    {
        return URL::temporarySignedRoute(
            'redsys.subscriptions.card',
            now()->addDays($days),
            $this,
        );
    }

    /** Pedido nuevo. Redsys los quiere de 12 caracteres como mucho, los 4
     *  primeros numericos, y rechaza los repetidos con SIS0051. */
    public function newOrder(): string
    {
        return substr((string) time(), -8) . str_pad((string) ($this->id % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Aplica el resultado de un cobro. Aqui vive toda la logica de estados.
     *
     * Deja ademas constancia del intento. El estado dice como esta ahora la
     * suscripcion; el historial dice como ha llegado hasta ahi, que es lo que
     * hace falta cuando un cliente pregunta por un cargo.
     *
     * @param string|null $order        Pedido de ese intento, si se conoce.
     * @param string|null $responseCode Ds_Response o SISxxxx, si se conoce.
     */
    public function recordCharge(ChargeOutcome $outcome, ?string $order = null, ?string $responseCode = null): void
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

            // La tarjeta ya no vale. Reintentar es tirar peticiones: lo unico
            // que arregla esto es que el titular registre otra.
            ChargeOutcome::TokenDead => $this->fill([
                'status'     => self::CANCELED,
                'card_token' => null,
                'ends_at'    => now(),
            ]),

            // Culpa de la configuracion del comercio, no del titular. Le sale
            // igual a todas las suscripciones, asi que ni suma fallo ni mueve
            // la fecha: se queda vencida y se cobrara cuando esto se arregle.
            ChargeOutcome::MerchantError,

            // Transitorio. El titular no ha hecho nada; no gasta intento.
            ChargeOutcome::Unavailable => $this->fill([]),

            // Reintentar manana, no dentro de una hora. Sin mover la fecha la
            // suscripcion sigue vencida y el siguiente pase la vuelve a cobrar:
            // con cron horario los tres intentos se gastan en tres horas y el
            // cliente se queda cancelado el mismo dia que le fallo la tarjeta.
            ChargeOutcome::Declined => $this->fill([
                'status'         => ++$this->failures >= self::MAX_FAILURES ? self::CANCELED : self::PAST_DUE,
                'failures'       => $this->failures,
                'ends_at'        => $this->failures >= self::MAX_FAILURES ? now() : null,
                'next_charge_at' => now()->addDays(self::RETRY_DAYS),
            ]),
        };

        $this->save();

        $charge = $this->charges()->create([
            'order'           => $order,
            'outcome'         => $outcome,
            'response_code'   => $responseCode,
            'amount_in_cents' => $this->amount_in_cents,
            'created_at'      => now(),
        ]);

        // Se dispara con el estado ya aplicado: quien escuche puede mirar
        // $subscription->status y saber si ademas se ha quedado cancelada.
        event(new SubscriptionCharged($this, $charge));
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
