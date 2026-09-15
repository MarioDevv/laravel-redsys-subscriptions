<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

/**
 * Resultado de un cobro MIT, traducido desde lo que responde Redsys.
 *
 * La clasificacion no es cosmetica: cada caso exige una accion distinta y
 * confundirlos rompe facturacion. Lo que mas cuesta no es equivocarse con una
 * denegacion, es meter en el mismo saco cosas que no son del cliente:
 *
 *   - Si tu clave esta mal, Redsys contesta SIS0042 a TODAS las suscripciones.
 *     Tratado como denegacion, en tres pases cancelas la cartera entera.
 *   - Si el emisor no esta disponible, el cliente no ha hecho nada y no puede
 *     gastar uno de sus tres intentos.
 *   - Una tarjeta caducada esta tan muerta como una referencia borrada, aunque
 *     Redsys use otro codigo. Reintentarla nueve dias es regalar servicio.
 *
 * Comprobados contra el sandbox de verdad: 0000 y SIS0321 (14/09/2026),
 * SIS0042 y SIS0051 (17/09/2026). El 0195 se vio en el sandbox en septiembre
 * pero no se ha podido volver a forzar. El resto sale de la tabla de codigos
 * de Redsys: son codigos que no se pueden provocar desde fuera.
 */
enum ChargeOutcome: string
{
    /** Ds_Response 0000-0099. */
    case Authorized = 'authorized';

    /** Ds_Response 0195: el emisor exige que el titular autentique. */
    case ScaRequired = 'sca_required';

    /** La tarjeta ya no vale. Hay que pedir otra: no se reintenta. */
    case TokenDead = 'token_dead';

    /** Tu configuracion, no la tarjeta del cliente. No es culpa suya. */
    case MerchantError = 'merchant_error';

    /** Pasajero: ni Redsys ni el emisor pueden ahora. Se reintenta sin coste. */
    case Unavailable = 'unavailable';

    /** Denegacion real del emisor: reintentable. */
    case Declined = 'declined';

    /**
     * La tarjeta esta muerta, venga el codigo que venga. No tiene sentido
     * insistir: lo unico que arregla esto es una tarjeta nueva.
     */
    private const DEAD = [
        'SIS0321', // El identificador no esta asociado al comercio
        '0101',    // Tarjeta caducada
        '0191',    // Fecha de caducidad erronea
        '0125',    // Tarjeta no efectiva
        '0106',    // Intentos de PIN excedidos
        '0202',    // Sospecha de fraude con retirada de tarjeta
        '9093',    // Tarjeta no existente
        '9253',    // La tarjeta no cumple el check-digit
    ];

    /**
     * Fallos de comercio. Salen igual para todas las suscripciones, asi que no
     * pueden contarse como fallo de nadie ni cancelar a nadie.
     */
    private const MERCHANT = [
        'SIS0042', // Error en el calculo de la firma
        'SIS0026', // Problema con la configuracion
        'SIS0028', // Comercio / terminal dado de baja
        'SIS0430', // Error al decodificar Ds_MerchantParameters
        '0904',    // Comercio no registrado en FUC
        '9104',    // Comercio con titular seguro y titular sin clave
        '9218',    // El comercio no permite operaciones seguras por esa entrada
        '9256',    // El comercio no puede realizar preautorizaciones
    ];

    /**
     * Transitorios. Se vuelve a intentar en el siguiente pase sin gastar uno de
     * los tres intentos del cliente, que no ha hecho nada.
     *
     * El pedido repetido esta aqui porque es un choque de newOrder(), un fallo
     * nuestro: apuntarselo al titular seria cobrarle nuestro error.
     */
    private const UNAVAILABLE = [
        '0909',    // Error de sistema
        '0912',    // Emisor no disponible
        '9912',    // Emisor no disponible
        '9997',    // Otra transaccion en curso con la misma tarjeta
        '9998',    // Operacion en proceso de solicitud de datos de tarjeta
        '9999',    // Operacion redirigida al emisor a autenticar
        '0913',    // Pedido repetido
        'SIS0051', // Numero de pedido repetido
        'SIS0001', // Error interno
    ];

    /**
     * @param string|null $responseCode Ds_Response, si hubo respuesta de pago.
     * @param string|null $errorCode    Codigo SISxxxx, si fallo la peticion.
     */
    public static function fromRedsys(?string $responseCode, ?string $errorCode = null): self
    {
        // Da igual por cual de los dos llegue: el codigo dice lo mismo.
        $code = $errorCode ?? $responseCode;

        if ($code === null) {
            return self::Declined;
        }

        return match (true) {
            in_array($code, self::DEAD, true)        => self::TokenDead,
            in_array($code, self::MERCHANT, true)    => self::MerchantError,
            in_array($code, self::UNAVAILABLE, true) => self::Unavailable,
            $code === '0195'                         => self::ScaRequired,

            // Redsys autoriza en el rango 0000-0099. Solo cuando viene como
            // respuesta de pago: un SISxxxx nunca es una autorizacion.
            $errorCode === null && is_numeric($code) && (int) $code <= 99 => self::Authorized,

            default => self::Declined,
        };
    }

    /** Si cuenta como intento fallido del titular. */
    public function blamesTheCardholder(): bool
    {
        return $this === self::Declined;
    }
}
