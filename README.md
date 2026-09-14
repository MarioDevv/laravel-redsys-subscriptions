# Laravel Redsys Subscriptions

Suscripciones y cobros recurrentes con **Redsys** para Laravel.

Redsys no aloja suscripciones: solo guarda una referencia de tarjeta. El ciclo de
facturación —renovaciones, reintentos, tarjetas caducadas, autenticaciones
pendientes— lo pone este paquete.

> **Estado: alfa.** El núcleo está probado contra el entorno real de Redsys, pero
> la API pública puede cambiar antes de la 1.0.

## Instalación

```bash
composer require mariodevv/laravel-redsys-subscriptions
php artisan migrate
```

```dotenv
REDSYS_MERCHANT_CODE=999008881
REDSYS_TERMINAL=1
REDSYS_SECRET_KEY=sq7HjrUOBfKmC576ILgskD5srU870gJ7
REDSYS_PRODUCTION=false
```

## Uso

```php
use MarioDevv\RedsysSubscriptions\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

```php
// 1. Crear la suscripción (queda incompleta hasta que haya tarjeta)
$subscription = $user->newSubscription(amountInCents: 1500, interval: 'monthly');

// 2. Registrar la tarjeta: el titular pasa por Redsys y por el 3DS una vez
echo $gateway->cardRegistrationForm(
    amountInCents: 1500,
    order: '0001'.time(),
    urlOk: route('redsys.ok'),
    urlKo: route('redsys.ko'),
);

// 3. A partir de ahí se cobra solo
// php artisan redsys:charge-subscriptions
```

Programa el comando en `routes/console.php`:

```php
Schedule::command('redsys:charge-subscriptions')->dailyAt('03:00');
```

## Estados

Las transiciones no son de manual: salen de observar qué responde Redsys.

| Respuesta de Redsys | Estado | Qué hacer |
|---|---|---|
| `0000`–`0099` | `active` | Nada. Se reinicia el contador de fallos. |
| `0195` | `past_due_sca` | Enviar al titular a reautenticar. **No reintentar. No cancelar.** |
| `SIS0321` | `canceled` | La referencia ya no vale. Pedir tarjeta nueva. |
| Otra denegación | `past_due` | Reintentar, hasta 3 veces. |

Dos detalles que cuestan caro si se ignoran:

- **`0195` no es un rechazo de tarjeta.** El emisor pide autenticación del titular
  para *esa* cuota. La tarjeta sigue viva y volverá a cobrar el mes siguiente.
  Cancelar aquí es tirar clientes que pagan.
- **Cada intento necesita su propio número de pedido.** Redsys rechaza los
  repetidos con `SIS0051`, reintentos incluidos.

## Firma SHA-512

Soporta `HMAC_SHA512_V2`, la versión de firma que Redsys presenta hoy como actual:

```php
use MarioDevv\RedsysSubscriptions\Sha512Signature;

$signature = Sha512Signature::sign($merchantParameters, $order, $secretKey);
$valid     = Sha512Signature::verify($received, $merchantParameters, $order, $secretKey);
```

Con esta versión `Ds_MerchantParameters` viaja en **Base64URL**; con base64
estándar Redsys responde `SIS0430`.

## Todavía no

Cupones, prorrateo, periodos de prueba, facturas en PDF, multidivisa, Bizum y
preautorizaciones. Abre un issue si necesitas alguno.

## Créditos

El protocolo lo pone [`creagia/redsys-php`](https://github.com/creagia/redsys-php).
Este paquete solo añade la capa de suscripciones.

## Licencia

MIT.
