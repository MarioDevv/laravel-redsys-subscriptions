<?php

declare(strict_types=1);

namespace MarioDevv\CashierRedsys;

/**
 * Firma HMAC_SHA512_V2 de Redsys.
 *
 * Difiere de HMAC_SHA256_V1 en tres puntos que no son evidentes:
 *   1. La clave AES son los bytes UTF-8 de los 16 PRIMEROS CARACTERES del
 *      string de la clave, no la clave decodificada de base64.
 *   2. El pedido se cifra con AES-128-CBC, IV de ceros y padding PKCS7.
 *   3. La clave del HMAC es el BASE64 de esa derivada tratado como texto.
 *
 * Ademas, con esta version `Ds_MerchantParameters` viaja en Base64URL; con
 * base64 estandar Redsys responde SIS0430.
 *
 * Contrastado con el vector oficial de Redsys (ver tests) y con un pago real.
 */
final class Sha512Signature
{
    public const VERSION = 'HMAC_SHA512_V2';

    public static function sign(string $merchantParameters, string $order, string $signatureKey): string
    {
        $derived = openssl_encrypt(
            $order,
            'aes-128-cbc',
            substr($signatureKey, 0, 16),
            OPENSSL_RAW_DATA,
            str_repeat("\0", 16),
        );

        $mac = hash_hmac('sha512', $merchantParameters, base64_encode($derived), true);

        return self::base64Url($mac);
    }

    public static function verify(string $received, string $merchantParameters, string $order, string $key): bool
    {
        return hash_equals(
            self::normalize(self::sign($merchantParameters, $order, $key)),
            self::normalize($received),
        );
    }

    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function normalize(string $signature): string
    {
        return rtrim(strtr(trim($signature), '+/', '-_'), '=');
    }
}
