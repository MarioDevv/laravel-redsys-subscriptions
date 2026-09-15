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
REDSYS_MERCHANT_CODE=
REDSYS_TERMINAL=1
REDSYS_SECRET_KEY=
REDSYS_PRODUCTION=false
```

El paquete no trae credenciales por defecto: sin `REDSYS_SECRET_KEY` no firma.
Las tuyas las da tu banco. Para probar sin comercio propio, las del entorno de
pruebas estan publicadas en la documentacion de Redsys.

```bash
php artisan vendor:publish --tag=redsys-subscriptions-config
```

### Si ya usas `creagia/laravel-redsys`

Conviven. Este paquete publica `config/redsys-subscriptions.php` y cuelga sus
rutas de `redsys/subscriptions/`, precisamente para no pisar el
`config/redsys.php` ni el prefijo `redsys/` de aquel. `REDSYS_MERCHANT_CODE` y
`REDSYS_TERMINAL` son las mismas variables en los dos; nuestra
`REDSYS_SECRET_KEY` es el mismo valor que su `REDSYS_KEY`.

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
return response($subscription->cardRegistrationForm());

// 3. A partir de ahí se cobra solo
// php artisan redsys:charge-subscriptions
```

El formulario se autoenvía a Redsys. Cuando el titular vuelve, la suscripción ya
está en `active` con la tarjeta guardada.

## El alta de tarjeta

El paquete registra dos rutas:

| Ruta | Quién la llama | Qué hace |
|---|---|---|
| `POST /redsys/subscriptions/notify` | Redsys, servidor a servidor | Guarda la tarjeta y activa |
| `GET\|POST /redsys/subscriptions/return/{id}` | El navegador del titular | Redirige a `return_url` |

**Manda la notificación, no el navegador.** Es la única que llega siempre,
firmada y completa: si el titular cierra la pestaña al volver, la suscripción se
activa igual.

Esto importa por una casilla del panel de Redsys, **«Enviar parámetros en las
URLs»**. En producción mucha gente la tiene en **NO**, y entonces el titular
vuelve a `urlOK` con un GET pelado: ni pedido, ni referencia, ni firma. Por eso
la suscripción se identifica en esa ruta por el id de la URL, y por eso ese id
**no basta para escribir nada**: hace falta que lleguen parámetros firmados y que
su `Ds_Order` sea el de esa suscripción.

Con la casilla en **SÍ** los parámetros llegan también por el navegador, y ese
camino acaba en la misma función: quien llegue primero activa, el segundo no hace
nada. De ahí que en local, donde Redsys no puede alcanzar tu máquina para
notificar, el alta funcione igualmente.

Una firma que no cuadra devuelve **403 y no toca la base de datos**: ni guarda ni
lo anota como pago fallido. Una versión de firma desconocida tampoco se adivina.
Se aceptan `HMAC_SHA256_V1` y `HMAC_SHA512_V2`, la que tenga configurada tu TPV.

Un alta denegada deja la suscripción en `incomplete` y el titular puede
reintentarla.

Programa el comando en `routes/console.php`:

```php
Schedule::command('redsys:charge-subscriptions')->dailyAt('03:00');
```

## Ver el paquete funcionando

El repositorio trae un laboratorio en `docker/`, que **no forma parte del
paquete**: solo lo ve quien lo clona. Es una aplicación Laravel de verdad, no un
banco de pruebas, así que lo que falle aquí falla igual en la tuya.

```bash
REDSYS_MERCHANT_CODE=... REDSYS_TERMINAL=... REDSYS_SECRET_KEY=... \
  docker compose up
```

La primera vez crea la aplicación y engancha el paquete con un repositorio de
tipo `path`, así que **lo que edites en el repositorio se ve al recargar**, sin
reinstalar nada. Tarda un par de minutos; las siguientes arranca en segundos.

En `http://127.0.0.1:8000` puedes dar de alta una tarjeta contra el entorno de
pruebas de Redsys, ver la suscripción pasar de `incomplete` a `active` con los
últimos cuatro dígitos, adelantar el vencimiento y lanzar
`redsys:charge-subscriptions` para ver la máquina de estados moverse. El panel
está en `/redsys-subscriptions`.

Sin credenciales arranca igual, pero avisa: el paquete no trae ninguna por
defecto.

```bash
docker compose down -v   # tirar la aplicación y empezar de cero
```

Los tests también corren ahí, que es lo cómodo si tu PHP no trae `pdo_sqlite`:

```bash
docker compose exec lab sh -c 'cd /package && vendor/bin/phpunit'
```

> El alta se completa entera en `localhost` **si tu comercio tiene «Enviar
> parámetros en las URLs» en SÍ**. Con la casilla en NO hace falta exponer la
> aplicación, porque la referencia solo llega por la notificación.

## Panel

El paquete trae un panel para operar en producción, en `/redsys-subscriptions`:

- **Resumen** — ingresos al mes, activas, sin cobrar, altas sin terminar, quién
  necesita atención y qué se cobra a continuación.
- **Suscripciones** — listado buscable por nº, pedido, últimos cuatro dígitos o
  referencia, filtrable por estado. Desde aquí se **da de baja** y se **lanza un
  cobro en el momento**, que es lo que hace falta cuando un cliente llama
  diciendo que ya tiene saldo.
- **Ajustes** — qué comercio, terminal y entorno está usando el paquete, y a qué
  direcciones responde. La clave de firma no se imprime nunca.

**Viene cerrado.** Fuera de `local` no entra nadie hasta que definas el Gate, en
un `ServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;
use MarioDevv\RedsysSubscriptions\Authorize;

Gate::define(Authorize::GATE, fn ($user) => $user->isAdmin());
```

Un panel con los datos de pago de todos tus clientes no puede quedarse abierto
porque alguien se olvidara de configurarlo. La ruta y el middleware se cambian en
`config/redsys-subscriptions.php`, y las vistas se adaptan con:

```bash
php artisan vendor:publish --tag=redsys-subscriptions-views
```

## Bajas

```php
$subscription->cancel();      // al final del periodo ya pagado
$subscription->cancelNow();   // de inmediato

$subscription->onGracePeriod(); // dada de baja, pero aun le queda periodo
$subscription->valid();         // activa, o en ese periodo de gracia
$user->subscribed();            // lo mismo, desde el titular
```

`cancel()` deja de cobrar desde ya, pero el acceso le dura hasta la fecha que le
quedaba: le cobraste el mes entero y cortarle el día que se da de baja es
quedarte su dinero. Para dar acceso mira `valid()`, no `active()`.

## Estados

Las transiciones no son de manual: salen de observar qué responde Redsys.

| Respuesta de Redsys | Estado | En el panel | Qué hacer |
|---|---|---|---|
| `0000`–`0099` | `active` | Activa | Nada. Se reinicia el contador de fallos. |
| `0195` | `past_due_sca` | Pendiente del titular | Enviar al titular a reautenticar. **No reintentar. No cancelar.** |
| `SIS0321` | `canceled` | Cancelada | La referencia ya no vale. Pedir tarjeta nueva. |
| Otra denegación | `past_due` | Reintentando | Reintentar, hasta 3 veces. |

El valor guardado es el de la columna «Estado»; lo traducido es solo la etiqueta,
y sale de `Subscription::statusLabels()`.

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

## Estado y hoja de ruta

Lo que hay probado, y contra qué:

| | Contra el sandbox de Redsys | Solo con tests |
|---|---|---|
| Cobro MIT, códigos de respuesta, `0195`, `SIS0321` | sí | |
| Formulario de alta COF | sí, hasta la puerta | |
| Verificación de firma, SHA-256 y SHA-512 | | sí |
| «Enviar parámetros en las URLs» en NO | | sí |
| Notificación servidor-a-servidor | **no** | sí |

El 3DS real y la notificación servidor-a-servidor todavía no han tocado Redsys.

### Antes de la 1.0

1. **Historial de cobros.** No se guarda cada intento, así que el panel no puede
   enseñar qué pasó ni cuándo. Falta una tabla de intentos y escribirla desde
   `recordCharge()`.
2. **Actualizar la tarjeta.** Tras un `SIS0321` o un `0195` no hay forma de poner
   una tarjeta nueva sobre la misma suscripción.
3. **Ficha de suscripción.** El panel llega hasta el listado. No hay pantalla de
   una sola suscripción, que es donde caben el historial y el cambio de tarjeta.
4. **El motivo exacto de un fallo.** Solo se guarda el contador `failures`, no el
   último código de Redsys. El panel puede decir «intento 2 de 3», pero no
   «0180 · fondos insuficientes». Sale gratis con el punto 1.
5. **Eventos.** La aplicación no puede enterarse de que un cobro ha fallado para
   avisar al cliente.
6. **Un cobro a la vez.** El comando no coge lock: dos pases solapados cobran dos
   veces al mismo cliente.
7. **Espera entre reintentos.** Un fallo no mueve `next_charge_at`, así que cada
   pase reintenta. Con cron horario, tres intentos se gastan en tres horas.
8. **Instalación en Laravel 13.** `creagia/redsys-php` pide `guzzle ^7` y Laravel
   13 trae la 8, así que `composer require` falla si no se fuerza con `-W`.

### Puede que nunca

Cupones, prorrateo, periodos de prueba, facturas en PDF, multidivisa, Bizum y
preautorizaciones. Abre un issue si necesitas alguno.

## Créditos

El protocolo lo pone [`creagia/redsys-php`](https://github.com/creagia/redsys-php).
Este paquete solo añade la capa de suscripciones.

## Licencia

MIT.
