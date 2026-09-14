<?php

declare(strict_types=1);

namespace MarioDevv\CashierRedsys;

use Illuminate\Console\Command;

class ChargeDueSubscriptions extends Command
{
    protected $signature = 'redsys:charge-subscriptions {--dry-run}';

    protected $description = 'Cobra las suscripciones Redsys vencidas';

    public function handle(RedsysGateway $gateway): int
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

            // Redsys rechaza un numero de pedido repetido (SIS0051), asi que
            // cada intento necesita el suyo, tambien los reintentos.
            $order = substr((string) time(), -8) . str_pad((string) ($subscription->id % 100), 2, '0', STR_PAD_LEFT);

            $outcome = $gateway->chargeStoredCard($subscription, $order);
            $subscription->recordCharge($outcome);

            $this->line("#{$subscription->id} · {$outcome->value} → {$subscription->status}");
        }

        return self::SUCCESS;
    }
}
