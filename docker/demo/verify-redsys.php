<?php

declare(strict_types=1);

/**
 * Contrasta nuestra clasificacion de errores contra el Redsys de verdad.
 *
 * No forma parte del paquete: es la herramienta para comprobar que la tabla de
 * ChargeOutcome dice lo que Redsys dice, y no lo que creemos que dice.
 *
 *   docker compose exec lab php /demo/verify-redsys.php
 *
 * Solo prueba lo que se puede forzar sin un titular delante. Lo que necesita
 * 3DS (autorizado, 0195, denegacion real del emisor) pide una tarjeta
 * registrada de verdad y se comprueba a mano desde el laboratorio.
 */

require '/package/vendor/autoload.php';

use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use MarioDevv\RedsysSubscriptions\RedsysGateway;
use MarioDevv\RedsysSubscriptions\Subscription;

$merchant = getenv('REDSYS_MERCHANT_CODE') ?: '';
$terminal = (int) (getenv('REDSYS_TERMINAL') ?: 1);
$key      = getenv('REDSYS_SECRET_KEY') ?: '';

if ($merchant === '' || $key === '') {
    fwrite(STDERR, "Faltan REDSYS_MERCHANT_CODE o REDSYS_SECRET_KEY.\n");
    exit(1);
}

$gateway = fn (string $secret) => new RedsysGateway($merchant, $secret, $terminal, production: false);

/** Una suscripcion de mentira: no toca base de datos, solo lleva los datos que mira el gateway. */
$subscription = function (string $token) {
    $s = new Subscription(['amount_in_cents' => 100]);
    $s->card_token = $token;
    $s->cof_transaction_id = '260101000000000';

    return $s;
};

$order = fn () => substr((string) time(), -8) . random_int(10, 99);

/** @return array{0: string, 1: ?string} */
$attempt = function (RedsysGateway $g, Subscription $s, string $order): array {
    try {
        $result = $g->chargeStoredCard($s, $order);

        return [$result->outcome->value, $result->code];
    } catch (\Throwable $e) {
        return ['EXCEPCION: ' . class_basename($e), null];
    }
};

$scenarios = [
    'Firma incorrecta (clave mala)' => [
        'run'      => fn () => $attempt($gateway('CLAVEQUENOESLABUENA0000000000000'), $subscription('loquesea'), $order()),
        'expected' => ChargeOutcome::MerchantError,
        'why'      => 'tu configuracion, no la tarjeta: no puede cancelar a nadie',
    ],
    'Referencia de tarjeta inventada' => [
        'run'      => fn () => $attempt($gateway($key), $subscription('referencia-que-no-existe-0001'), $order()),
        'expected' => ChargeOutcome::TokenDead,
        'why'      => 'la referencia no vale: hay que pedir tarjeta nueva',
    ],
];

printf("Comercio %s · terminal %d · entorno de pruebas\n\n", $merchant, $terminal);
printf("%-34s %-10s %-16s %s\n", 'ESCENARIO', 'CODIGO', 'CLASIFICADO', 'ESPERADO');
echo str_repeat('-', 92), "\n";

$mismatches = 0;

foreach ($scenarios as $name => $case) {
    [$outcome, $code] = ($case['run'])();
    $expected = $case['expected'];
    $ok = $expected === null || $outcome === $expected->value;

    if (! $ok) {
        $mismatches++;
    }

    printf(
        "%-34s %-10s %-16s %s\n",
        $name,
        $code ?? '—',
        $outcome,
        $expected === null ? '(preparacion)' : ($ok ? 'ok · ' . $expected->value : 'DISTINTO · esperaba ' . $expected->value),
    );
    printf("%-34s %s\n", '', $case['why']);
}

echo "\n";
echo "Sin comprobar aqui, porque necesitan una tarjeta registrada con 3DS:\n";
echo "  - autorizado (0000-0099), 0195 SCA y las denegaciones reales del emisor.\n";
echo "  - el pedido repetido (SIS0051). Comprobado que Redsys valida la referencia\n";
echo "    ANTES que el pedido: con una referencia inventada devuelve SIS0321 las dos\n";
echo "    veces, asi que forzarlo pide una tarjeta buena.\n";
echo "  Se prueban dando de alta una tarjeta en el laboratorio y cobrandola.\n\n";

exit($mismatches === 0 ? 0 : 1);
