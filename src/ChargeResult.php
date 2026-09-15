<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

/**
 * Lo que devolvio Redsys en un intento de cobro.
 *
 * El enum solo dice que hacer; el codigo dice por que. Hacen falta los dos:
 * sin el codigo el panel puede decir «denegada», pero no si fue por fondos
 * (0180, se reintenta) o por firma (SIS0042, lo tuyo esta mal configurado).
 */
final readonly class ChargeResult
{
    public function __construct(
        public ChargeOutcome $outcome,
        /** Ds_Response de cuatro digitos, o SISxxxx si fallo la peticion. */
        public ?string $code = null,
    ) {}
}
