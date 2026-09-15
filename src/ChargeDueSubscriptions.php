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
            $result = $gateway->chargeStoredCard($subscription, $order = $subscription->newOrder());
            $subscription->recordCharge($result->outcome, $order, $result->code);

            $this->line("#{$subscription->id} · {$result->outcome->value} → {$subscription->status}");
        }

        return self::SUCCESS;
    }
}
