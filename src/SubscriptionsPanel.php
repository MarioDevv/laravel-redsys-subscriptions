<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Panel de suscripciones. Datos reales, clientes reales: aqui no se simula nada.
 */
class SubscriptionsPanel
{
    private const PER_PAGE = 25;

    /** Resumen: como va el negocio y que necesita atencion hoy. */
    public function overview()
    {
        return view('redsys-subscriptions::overview', [
            'stats'    => $this->stats(),
            'upcoming' => Subscription::query()
                ->whereIn('status', [Subscription::ACTIVE, Subscription::PAST_DUE])
                ->whereNotNull('card_token')
                ->whereNotNull('next_charge_at')
                ->orderBy('next_charge_at')
                ->limit(6)
                ->get(),
            'attention' => Subscription::query()
                ->whereIn('status', [Subscription::PAST_DUE, Subscription::PAST_DUE_SCA])
                ->latest('updated_at')
                ->limit(6)
                ->get(),
            // Hoja de ruta: sin tabla de intentos esto llega vacio. La vista ya
            // dibuja la tabla, asi que cuando exista solo cambia esta linea.
            'charges'   => collect(),
        ]);
    }

    public function index(Request $request)
    {
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('q', ''));

        // Lista blanca: el orden llega por la URL y va directo a la consulta.
        $columns = ['numero' => 'id', 'importe' => 'amount_in_cents', 'cobro' => 'next_charge_at'];
        $sort = (string) $request->query('orden', 'numero');
        $sort = isset($columns[$sort]) ? $sort : 'numero';
        $descending = $request->query('dir', 'desc') !== 'asc';

        // ponytail: sin eager load del billable a proposito. Es polimorfico, y
        // with() revienta si algun billable_type apunta a una clase borrada.
        // Son 25 consultas por pagina en un panel; si molesta, cargar por tipo.
        $subscriptions = Subscription::query()
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('id', $search)
                    ->orWhere('card_last_four', $search)
                    ->orWhere('checkout_order', $search)
                    ->orWhere('card_token', $search);
            }))
            ->orderBy($columns[$sort], $descending ? 'desc' : 'asc')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('redsys-subscriptions::subscriptions', [
            'subscriptions' => $subscriptions,
            'stats'         => $this->stats(),
            'status'        => $status,
            'search'        => $search,
            'sort'          => $sort,
            'descending'    => $descending,
        ]);
    }

    /**
     * La ficha de una suscripcion: todo lo que se sabe de ella en una pantalla.
     * Es donde el soporte mira cuando alguien llama, y donde caben el historial
     * de cobros y el cambio de tarjeta cuando existan.
     */
    public function show(Subscription $subscription)
    {
        return view('redsys-subscriptions::show', [
            'stats'        => $this->stats(),
            'subscription' => $subscription,
            // Hoja de ruta: todavia no se guarda cada intento, asi que la tabla
            // llega vacia. La vista ya sabe dibujarla cuando haya filas.
            'charges'      => collect(),
        ]);
    }

    /**
     * Lanza el pase del comando a mano, sin esperar al cron.
     *
     * ponytail: sincrono, dentro de la peticion. Con pocas suscripciones
     * vencidas se nota poco; si el pase empieza a tardar, a una cola.
     */
    public function run(): RedirectResponse
    {
        Artisan::call('redsys:charge-subscriptions');

        return back()->with('redsys_message', trim(Artisan::output()));
    }

    /** Que configuracion esta cogiendo el paquete ahora mismo. */
    public function settings()
    {
        return view('redsys-subscriptions::settings', [
            'stats'    => $this->stats(),
            'settings' => [
                'Comercio'       => config('redsys-subscriptions.merchant_code') ?: null,
                'Terminal'       => config('redsys-subscriptions.terminal'),
                'Entorno'        => config('redsys-subscriptions.production') ? 'Producción' : 'Pruebas',
                'Clave de firma' => config('redsys-subscriptions.secret_key') ? 'Configurada' : null,
            ],
            'urls' => [
                'Notificación de Redsys' => route('redsys.subscriptions.notify'),
                'Vuelta del titular'     => url((string) config('redsys-subscriptions.return_url', '/')),
            ],
            'gateDefined' => Gate::has(Authorize::GATE),
        ]);
    }

    public function cancel(Request $request, Subscription $subscription): RedirectResponse
    {
        $request->boolean('now') ? $subscription->cancelNow() : $subscription->cancel();

        return back()->with('redsys_message', "{$subscription->billableName()} ya no tiene cobros programados.");
    }

    /**
     * Cobra ahora, de verdad. Es lo que se pide cuando un cliente llama para
     * decir que ya tiene saldo, y ahorra esperar al siguiente pase del comando.
     */
    public function charge(Subscription $subscription, RedsysGateway $gateway): RedirectResponse
    {
        if ($subscription->card_token === null) {
            return back()->with('redsys_message', "{$subscription->billableName()} no tiene tarjeta guardada.");
        }

        $outcome = $gateway->chargeStoredCard($subscription, $subscription->newOrder());
        $subscription->recordCharge($outcome);

        return back()->with('redsys_message', match ($outcome) {
            ChargeOutcome::Authorized  => "Cobro hecho. {$subscription->billableName()} vuelve a estar al día.",
            ChargeOutcome::ScaRequired => "El banco pide que {$subscription->billableName()} autentique el pago.",
            ChargeOutcome::TokenDead   => "La tarjeta de {$subscription->billableName()} ya no vale. Hay que pedir una nueva.",
            ChargeOutcome::Declined    => "El banco ha rechazado el cobro de {$subscription->billableName()}.",
        });
    }

    /**
     * Lo que va en la barra lateral y en el resumen.
     *
     * @return array{mrr: int, counts: array<string, int>, all: int}
     */
    private function stats(): array
    {
        $counts = Subscription::query()
            ->groupBy('status')
            ->pluck(DB::raw('count(*)'), 'status');

        // Todo a mensual, que es como se mira un negocio de suscripciones.
        $mrr = Subscription::query()
            ->where('status', Subscription::ACTIVE)
            ->get(['amount_in_cents', 'interval'])
            ->sum(fn ($s) => match ($s->interval) {
                'yearly' => $s->amount_in_cents / 12,
                'weekly' => $s->amount_in_cents * 52 / 12,
                default  => $s->amount_in_cents,
            });

        // Todos los estados, aunque esten a cero: la barra lateral ensena
        // siempre las mismas entradas y en el mismo orden.
        $byStatus = [];

        foreach (Subscription::statusLabels() as $status => $label) {
            $byStatus[$status] = (int) ($counts[$status] ?? 0);
        }

        return [
            'mrr'    => (int) $mrr,
            'counts' => $byStatus,
            'all'    => (int) $counts->sum(),
        ];
    }
}
