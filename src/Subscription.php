<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
        // El pedido solo se estrena si no hay uno esperando respuesta. Se
        // machacaba en cada render, y como el alta cobra de verdad, pagar desde
        // un formulario viejo —una recarga, otra pestana, el enlace de cambio
        // de tarjeta abierto en el movil y luego en el escritorio— cargaba el
        // importe con un pedido que ya no estaba guardado: dinero cobrado y
        // nadie a quien activar. Pagando desde los dos, dos cargos. Repitiendo
        // el pedido es Redsys quien deduplica, con SIS0051.
        //
        // Con tarjeta ya guardada siempre estrena: ese pedido esta consumido y
        // repetirlo daria SIS0051. Es el caso del cambio de tarjeta.
        $order = $this->card_token === null && $this->checkout_order
            ? $this->checkout_order
            : $this->newOrder();

        $this->update(['checkout_order' => $order]);

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

        // Un pedido ya procesado no se repite: la notificacion y la vuelta del
        // navegador pueden llegar las dos, y la segunda no debe mover la fecha
        // de cobro. Se mira el pedido y no si esta activa, porque una
        // suscripcion viva tiene que poder registrar otra tarjeta: es lo que
        // hace falta cuando Redsys mata la referencia con SIS0321.
        if ($this->charges()->where('order', $order)->exists()) {
            return;
        }

        // Un alta denegada no cancela nada: sigue incomplete y el titular puede
        // reintentar. Suelta el pedido para que el proximo formulario estrene
        // uno: Redsys ya tiene este, y repetirlo seria SIS0051.
        if (ChargeOutcome::fromRedsys($params['DS_RESPONSE'] ?? null) !== ChargeOutcome::Authorized) {
            $this->update(['checkout_order' => null]);

            return;
        }

        // Autorizado pero sin referencia de tarjeta: no se activa, o quedaria
        // activa sin poder cobrar e invisible para el scope due(). El pedido se
        // conserva, que ese cobro si ha salido y hay que poder reconciliarlo.
        if (empty($params['DS_MERCHANT_IDENTIFIER'])) {
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

    /**
     * Pedido nuevo. Redsys los quiere de 12 caracteres como mucho, los 4
     * primeros numericos, y rechaza los repetidos con SIS0051.
     *
     * Los cuatro ultimos son aleatorios y no el id de la suscripcion: con el id
     * modulo 100 dos intentos dentro del mismo segundo salian identicos, y eso
     * es un pedido que Redsys ya tiene. Se notaba al reintentar un alta
     * denegada, que es justo cuando el titular esta delante mirando.
     */
    public function newOrder(): string
    {
        return substr((string) time(), -8) . strtoupper(bin2hex(random_bytes(2)));
    }

    /**
     * El pedido con el que se va a cobrar, guardado antes de enviarlo.
     *
     * Si un intento anterior se quedo sin respuesta fiable, su pedido sigue
     * aqui y se reutiliza: puede que Redsys lo autorizase y el dinero este
     * cobrado, y mandar uno nuevo seria cobrar otra vez. Repitiendolo, Redsys
     * hace de guardia y contesta SIS0051, que ya se trata como transitorio.
     */
    public function beginCharge(): string
    {
        $order = $this->pending_order ?? $this->newOrder();

        $this->update(['pending_order' => $order]);

        return $order;
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
        try {
            $charge = DB::transaction(fn () => $this->applyCharge($outcome, $order, $responseCode));
        } catch (UniqueConstraintViolationException) {
            // Ese pedido ya estaba anotado en esta suscripcion: gana quien
            // llego primero y el segundo no toca nada. Es el caso de cada alta,
            // donde la notificacion de Redsys y la vuelta del navegador llegan
            // a la vez; sin esto la segunda sumaria otro periodo por un pago.
            return;
        }

        // Se dispara con el estado ya aplicado: quien escuche puede mirar
        // $subscription->status y saber si ademas se ha quedado cancelada.
        event(new SubscriptionCharged($this, $charge));
    }

    /**
     * El intento se anota primero, y es el indice unico (subscription_id,
     * order) quien decide si ya estaba. Mirarlo antes con un select y escribir
     * despues es una carrera: los dos caminos de vuelta de Redsys la pasan.
     */
    private function applyCharge(ChargeOutcome $outcome, ?string $order, ?string $responseCode): Charge
    {
        $charge = $this->charges()->create([
            'order'           => $order,
            'outcome'         => $outcome,
            'response_code'   => $responseCode,
            'amount_in_cents' => $this->amount_in_cents,
            'created_at'      => now(),
        ]);

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

        // Redsys ha contestado sobre este pedido, asi que deja de estar en el
        // aire y el siguiente intento estrenara uno. Salvo con SIS0051: ahi lo
        // unico que ha dicho es que ya lo tenia, no como acabo, asi que se
        // conserva para seguir reintentando ese mismo y que siga deduplicando.
        //
        // ponytail: una suscripcion puede quedarse dando vueltas en SIS0051 si
        // Redsys autorizo el cobro y nadie lo mira. Es a proposito: no cobrar
        // dos veces vale mas que desatascarla sola. Si molesta, consultar la
        // operacion en Redsys y cerrarla con su desenlace de verdad.
        if ($responseCode !== 'SIS0051') {
            $this->pending_order = null;
        }

        $this->save();

        return $charge;
    }

    /**
     * Cuando toca el siguiente cobro.
     *
     * Se ancla en la fecha que tocaba y no en cuando corre el cron. En el ciclo
     * recurrente next_charge_at siempre esta en el pasado —por eso la
     * suscripcion estaba vencida—, asi que anclar en now() alargaba cada
     * periodo lo que tardara el pase en llegar: con el cron a las 03:00 y un
     * alta a las 14:00, un mes y trece horas. Doce ciclos son casi dos semanas
     * de servicio regalado al año.
     *
     * Y avanza sin desbordar: addMonth() sobre un 31 de enero se iba al 3 de
     * marzo, porque al sumar un mes a una fecha que no existe en febrero Carbon
     * sigue contando. Eso muda la fecha de cobro del titular y no vuelve.
     *
     * ponytail: sin dia de anclaje guardado. Un 31 de enero pasa a 28 de
     * febrero y ahi se queda, en vez de volver al 31 en marzo. Si alguien se
     * queja, guardar el dia original y recuperarlo cuando el mes de para tanto.
     */
    public function nextChargeDate(): \DateTimeInterface
    {
        $next = $this->next_charge_at ?? now();

        // Si estuvo vencida varios periodos, salta hasta el proximo que quede
        // por delante: se cobra una cuota, no todas las que se perdieron.
        do {
            $next = match ($this->interval) {
                'yearly'  => $next->copy()->addYearNoOverflow(),
                'weekly'  => $next->copy()->addWeek(),
                default   => $next->copy()->addMonthNoOverflow(),
            };
        } while ($next->isPast());

        return $next;
    }

    public function scopeDue($query)
    {
        return $query->whereIn('status', [self::ACTIVE, self::PAST_DUE])
            ->whereNotNull('card_token')
            ->where('next_charge_at', '<=', now());
    }
}
