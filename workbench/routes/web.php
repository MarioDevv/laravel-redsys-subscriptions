<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use MarioDevv\RedsysSubscriptions\Subscription;
use Workbench\App\Models\User;

/**
 * Demo del paquete. No forma parte de el: vive en workbench/ y solo la ve quien
 * clona el repositorio y arranca `vendor/bin/testbench serve`.
 */

$demoUser = fn (): User => User::firstOrCreate(
    ['email' => 'titular@example.test'],
    ['name' => 'Titular de prueba', 'password' => ''],
);

Route::get('/', fn () => view('demo', [
    'subscriptions' => $demoUser()->redsysSubscriptions()->latest('id')->get(),
    'configured'    => (bool) config('redsys-subscriptions.secret_key'),
    'output'        => session('output'),
]));

// Alta real: crea la suscripcion y devuelve el formulario que se autoenvia a Redsys.
Route::post('/subscribe', function (Request $request) use ($demoUser) {
    $subscription = $demoUser()->newSubscription(
        amountInCents: (int) $request->input('cents', 1500),
        interval:      (string) $request->input('interval', 'monthly'),
    );

    return response($subscription->cardRegistrationForm());
});

// Alta simulada: mismo camino que la de verdad, pero con lo que devolveria
// Redsys. Permite recorrer la maquina de estados sin comercio ni red.
Route::post('/subscribe-fake', function (Request $request) use ($demoUser) {
    $demoUser()->newSubscription(
        amountInCents: (int) $request->input('cents', 1500),
        interval:      (string) $request->input('interval', 'monthly'),
    )->completeCheckout([
        'DS_RESPONSE'            => '0000',
        'DS_MERCHANT_IDENTIFIER' => Str::random(40),
        'DS_MERCHANT_COF_TXNID'  => '26' . now()->format('mdHis') . '0',
        'DS_CARD_NUMBER'         => '454881******' . random_int(1000, 9999),
        'DS_EXPIRYDATE'          => '4912',
    ]);

    return back();
});

// El simulador: aplica un resultado de cobro sin llamar a Redsys. Es la misma
// funcion que usa el comando, asi que lo que se ve aqui es lo que pasa de verdad.
Route::post('/simulate/{subscription}/{outcome}', function (Subscription $subscription, string $outcome) {
    $subscription->recordCharge(ChargeOutcome::from($outcome));

    return back();
})->whereIn('outcome', array_column(ChargeOutcome::cases(), 'value'));

Route::post('/cancel/{subscription}', function (Subscription $subscription) {
    $subscription->cancel();

    return back();
});

// Adelanta el vencimiento: sin esto habria que esperar un mes para ver un cobro.
Route::post('/due/{subscription}', function (Subscription $subscription) {
    $subscription->update(['next_charge_at' => now()->subMinute()]);

    return back();
});

Route::post('/charge', function () {
    Artisan::call('redsys:charge-subscriptions');

    return back()->with('output', trim(Artisan::output()));
});

Route::post('/reset', function () {
    Subscription::query()->delete();

    return back();
});
