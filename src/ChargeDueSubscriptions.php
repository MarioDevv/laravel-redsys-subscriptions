<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ChargeDueSubscriptions extends Command
{
    protected $signature = 'redsys:charge-subscriptions {--dry-run}';

    protected $description = 'Cobra las suscripciones Redsys vencidas';

    /** Nadie deberia tardar tanto, pero si el proceso muere el lock caduca solo. */
    private const LOCK_SECONDS = 600;

    public function handle(RedsysGateway $gateway): int
    {
        // El lock vive aqui dentro y no en quien programa el comando. Laravel
        // ofrece withoutOverlapping() y --isolated, pero los dos dependen de
        // que el que escribe el cron se acuerde; olvidarse cuesta cobrar dos
        // veces al mismo cliente.
        //
        // ponytail: un lock para todo el pase. Si algun dia hace falta cobrar
        // en paralelo, uno por suscripcion.
        $lock = Cache::lock('redsys-subscriptions:charging', self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->warn('Ya hay un pase de cobro en marcha. Este se salta.');

            return self::SUCCESS;
        }

        try {
            return $this->chargeDue($gateway);
        } finally {
            $lock->release();
        }
    }

    private function chargeDue(RedsysGateway $gateway): int
    {
        $due = Subscription::query()->due()->get();

        if ($due->isEmpty()) {
            $this->info('No hay suscripciones vencidas.');

            return self::SUCCESS;
        }

        foreach ($due as $subscription) {
            if ($this->option('dry-run')) {
                $this->line("[dry-run] #{$subscription->id} · {$subscription->amount_in_cents} cent.");
                continue;
            }

            // Cada intento necesita su propio pedido, reintentos incluidos.
            $result = $gateway->chargeStoredCard($subscription, $order = $subscription->newOrder());
            $subscription->recordCharge($result->outcome, $order, $result->code);

            $this->line("#{$subscription->id} · {$result->outcome->value} → {$subscription->status}");

            // Un fallo de configuracion le sale igual a todas: seguir es
            // machacar a Redsys y llenar el historial de ruido con un error
            // que no es de nadie mas que tuyo.
            if ($result->outcome === ChargeOutcome::MerchantError) {
                $this->error("Redsys rechaza la peticion por configuracion ({$result->code}). Se detiene el pase.");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
