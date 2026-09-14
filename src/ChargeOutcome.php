<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

/**
 * Resultado de un cobro MIT, traducido desde lo que responde Redsys.
 *
 * La clasificacion no es cosmetica: cada caso exige una accion distinta y
 * confundirlos rompe facturacion. Verificado contra el sandbox el 14/09/2026.
 */
enum ChargeOutcome: string
{
    /** Ds_Response 0000-0099. */
    case Authorized = 'authorized';

    /** Ds_Response 0195: el emisor exige que el titular autentique. */
    case ScaRequired = 'sca_required';

    /** SIS0321: la referencia ya no vale (tarjeta revocada, caducada). */
    case TokenDead = 'token_dead';

    /** Cualquier otra denegacion: reintentable. */
    case Declined = 'declined';

    /**
     * @param string|null $responseCode Ds_Response, si hubo respuesta de pago.
     * @param string|null $errorCode    Codigo SISxxxx, si fallo la peticion.
     */
    public static function fromRedsys(?string $responseCode, ?string $errorCode = null): self
    {
        if ($errorCode === 'SIS0321') {
            return self::TokenDead;
        }

        if ($errorCode !== null) {
            return self::Declined;
        }

        if ($responseCode === '0195') {
            return self::ScaRequired;
        }

        // Redsys autoriza en el rango 0000-0099. El resto es denegacion.
        if ($responseCode !== null && is_numeric($responseCode) && (int) $responseCode <= 99) {
            return self::Authorized;
        }

        return self::Declined;
    }
}
