<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Clasificacion verificada contra el sandbox real de Redsys el 14/09/2026. */
final class ChargeOutcomeTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'autorizada'            => ['0000', null, ChargeOutcome::Authorized],
            'autorizada 0099'       => ['0099', null, ChargeOutcome::Authorized],
            'exige SCA'             => ['0195', null, ChargeOutcome::ScaRequired],
            'denegada por emisor'   => ['0190', null, ChargeOutcome::Declined],
            'caducidad erronea'     => ['0191', null, ChargeOutcome::Declined],
            'referencia muerta'     => [null, 'SIS0321', ChargeOutcome::TokenDead],

            // Tarjeta muerta es tarjeta muerta, venga el codigo que venga:
            // reintentar nueve dias es regalar servicio sin cobrarlo.
            'tarjeta caducada'      => ['0101', null, ChargeOutcome::TokenDead],
            'caducidad erronea'     => ['0191', null, ChargeOutcome::TokenDead],
            'tarjeta no existente'  => ['9093', null, ChargeOutcome::TokenDead],

            // Culpa tuya, no del titular. Si esto contara como denegacion, una
            // clave mal puesta cancelaria la cartera entera en tres pases.
            'firma incorrecta'      => [null, 'SIS0042', ChargeOutcome::MerchantError],
            'comercio de baja'      => [null, 'SIS0028', ChargeOutcome::MerchantError],
            'comercio sin FUC'      => ['0904', null, ChargeOutcome::MerchantError],

            // Pasajero: el titular no ha hecho nada y no gasta intento.
            'emisor no disponible'  => ['0912', null, ChargeOutcome::Unavailable],
            'error de sistema'      => ['0909', null, ChargeOutcome::Unavailable],
            'pedido repetido'       => [null, 'SIS0051', ChargeOutcome::Unavailable],
        ];
    }

    #[DataProvider('cases')]
    public function test_classifies_redsys_responses(?string $response, ?string $error, ChargeOutcome $expected): void
    {
        $this->assertSame($expected, ChargeOutcome::fromRedsys($response, $error));
    }

    public function test_sca_is_not_confused_with_a_plain_decline(): void
    {
        // Confundirlos cancela suscripciones vivas o reintenta lo irreintentable.
        $this->assertNotSame(
            ChargeOutcome::fromRedsys('0195'),
            ChargeOutcome::fromRedsys('0190'),
        );
    }

    public function test_a_merchant_error_is_never_the_cardholders_fault(): void
    {
        // Es la diferencia entre revisar tu configuracion y cancelar a un
        // cliente que paga.
        $this->assertFalse(ChargeOutcome::fromRedsys(null, 'SIS0042')->blamesTheCardholder());
        $this->assertFalse(ChargeOutcome::fromRedsys('0912')->blamesTheCardholder());
        $this->assertTrue(ChargeOutcome::fromRedsys('0190')->blamesTheCardholder());
    }

    public function test_a_sis_code_is_never_read_as_an_authorisation(): void
    {
        // 'SIS0051' no es numerico, pero un descuido en el orden de las ramas
        // podria colar un codigo corto como si fuera un 00xx autorizado.
        foreach (['SIS0051', 'SIS0042', 'SIS0321', 'SIS0001'] as $code) {
            $this->assertNotSame(ChargeOutcome::Authorized, ChargeOutcome::fromRedsys(null, $code), $code);
        }
    }
}
