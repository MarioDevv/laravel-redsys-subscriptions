<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redsys_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('name')->default('default');

            // Pedido del alta de tarjeta. Con 'Enviar parametros en las URLs' en
            // NO, la notificacion servidor-a-servidor es lo unico que identifica
            // la suscripcion, y solo trae Ds_Order.
            $table->string('checkout_order', 12)->nullable()->unique();

            // Referencia de tarjeta devuelta por Redsys en el pago inicial (COF)
            $table->string('card_token')->nullable();
            $table->string('cof_transaction_id')->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->string('card_expiry', 4)->nullable();

            $table->unsignedInteger('amount_in_cents');
            $table->string('currency', 3)->default('EUR');
            $table->string('interval')->default('monthly');

            $table->string('status')->default('incomplete');
            $table->unsignedTinyInteger('failures')->default(0);
            $table->timestamp('next_charge_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();
            $table->index(['status', 'next_charge_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redsys_subscriptions');
    }
};
