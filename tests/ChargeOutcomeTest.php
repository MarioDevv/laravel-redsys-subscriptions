<?php

declare(strict_types=1);

namespace MarioDevv\CashierRedsys\Tests;

use MarioDevv\CashierRedsys\ChargeOutcome;
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
            'pedido repetido'       => [null, 'SIS0051', ChargeOutcome::Declined],
            'firma incorrecta'      => [null, 'SIS0042', ChargeOutcome::Declined],
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
}
