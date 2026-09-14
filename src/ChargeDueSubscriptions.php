<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

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

            // Cada intento necesita su propio pedido, reintentos incluidos.
            $outcome = $gateway->chargeStoredCard($subscription, $subscription->newOrder());
            $subscription->recordCharge($outcome);

            $this->line("#{$subscription->id} · {$outcome->value} → {$subscription->status}");
        }

        return self::SUCCESS;
    }
}
