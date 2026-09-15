<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redsys_subscription_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')
                ->constrained('redsys_subscriptions')
                ->cascadeOnDelete();

            // El pedido de ese intento. Redsys los quiere distintos entre si,
            // asi que es lo que permite buscar un cobro concreto en su panel.
            $table->string('order', 12)->nullable();

            // Que hacer: el valor de ChargeOutcome.
            $table->string('outcome');

            // Por que: Ds_Response de cuatro digitos, o SISxxxx si fallo la
            // peticion. Es la diferencia entre «denegada» y «0180 por fondos».
            $table->string('response_code', 12)->nullable();

            $table->unsignedInteger('amount_in_cents');

            // Un intento no se modifica nunca, asi que no lleva updated_at.
            $table->timestamp('created_at');

            $table->index(['subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redsys_subscription_charges');
    }
};
