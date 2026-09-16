# Laravel Redsys Subscriptions

[![Packagist](https://img.shields.io/packagist/v/mariodevv/laravel-redsys-subscriptions.svg)](https://packagist.org/packages/mariodevv/laravel-redsys-subscriptions)
[![Descargas](https://img.shields.io/packagist/dt/mariodevv/laravel-redsys-subscriptions.svg)](https://packagist.org/packages/mariodevv/laravel-redsys-subscriptions)
[![PHP](https://img.shields.io/packagist/dependency-v/mariodevv/laravel-redsys-subscriptions/php.svg)](composer.json)
[![Licencia](https://img.shields.io/packagist/l/mariodevv/laravel-redsys-subscriptions.svg)](LICENSE)
[![Tests](https://github.com/MarioDevv/laravel-redsys-subscriptions/actions/workflows/workflow.yaml/badge.svg)](https://github.com/MarioDevv/laravel-redsys-subscriptions/actions/workflows/workflow.yaml)

Suscripciones y cobros recurrentes con **Redsys** para Laravel.

Redsys no aloja suscripciones: solo guarda una referencia de tarjeta. El ciclo de
facturación —renovaciones, reintentos, tarjetas caducadas, autenticaciones
pendientes— lo pone este paquete.

> **Estado: beta.** Alta con 3DS, cobro recurrente y notificación
> servidor-a-servidor verificados contra el entorno de pruebas de Redsys. Sin
> probar en producción, y la API pública puede cambiar antes de la 1.0.

```php
$subscription = $user->newSubscription(amountInCents: 1500, interval: 'monthly');

return response($subscription->cardRegistrationForm());   // el titular pasa el 3DS una vez
// y a partir de ahí se cobra solo
```

**Qué pone el paquete:** la máquina de estados, los reintentos con espera, el
historial de cada intento, el cambio de tarjeta, un panel para operar y la
clasificación de los códigos de error de Redsys —que es donde se rompe la
facturación cuando se hace a ojo.

**Qué no:** [cupones, prorrateo, periodos de prueba, facturas, multidivisa…](#puede-que-nunca)

---

- [Instalación](#instalación)
- [Uso](#uso)
- [El alta de tarjeta](#el-alta-de-tarjeta)
- [Ver el paquete funcionando](#ver-el-paquete-funcionando)
- [Panel](#panel)
- [Historial de cobros](#historial-de-cobros)
- [Enterarte de lo que pasa](#enterarte-de-lo-que-pasa)
- [Cambiar la tarjeta](#cambiar-la-tarjeta)
- [Bajas](#bajas)
- [Estados](#estados) — **empieza por aquí si vienes de otro TPV**
- [Firma SHA-512](#firma-sha-512)
- [Estado y hoja de ruta](#estado-y-hoja-de-ruta)

## Instalación

```bash
composer require mariodevv/laravel-redsys-subscriptions
php artisan migrate
```

Laravel 12 y 13, PHP 8.2 o superior. En la 13 no hace falta forzar nada:
`creagia/redsys-php` pide `guzzle ^7`, la 13 acepta `^7.8.2 || ^8.0`, y composer
resuelve la 7.15.

Laravel 11 se quedó fuera: su soporte de seguridad terminó y Composer bloquea
todas sus versiones por avisos, así que declararlo sería prometer algo que nadie
puede instalar.

```dotenv
REDSYS_MERCHANT_CODE=
REDSYS_TERMINAL=1
REDSYS_SECRET_KEY=
REDSYS_PRODUCTION=false
```

El paquete no trae credenciales por defecto: sin `REDSYS_SECRET_KEY` no firma.
Las tuyas las da tu banco. Para probar sin comercio propio, las del entorno de
pruebas están publicadas en la documentación de Redsys.

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
reintentarla, con un pedido nuevo: el anterior ya lo tiene Redsys.

**Mientras el alta sigue en el aire, el formulario devuelve siempre el mismo
pedido.** Da igual que se recargue, que se abra en otra pestaña o que el enlace
de cambio de tarjeta se abra primero en el móvil y luego en el escritorio.
Importa porque el alta **cobra de verdad**: con un pedido distinto por pantalla,
pagar desde la que quedó vieja carga el importe de una suscripción que ya no lo
espera, y pagar desde las dos son dos cargos. Repitiendo el pedido, Redsys los
deduplica con `SIS0051` y solo puede entrar uno.

Programa el comando en `routes/console.php`:

```php
Schedule::command('redsys:charge-subscriptions')->dailyAt('03:00');
```

### Si tu aplicación va detrás de un proxy

**Configura `TrustProxies`, o las altas no se activarán nunca.**

La URL de notificación no se configura: el paquete la construye con `route()`, y
Laravel construye URLs absolutas con el esquema y el host de **la petición**. Si
quien termina el TLS es un proxy, un balanceador o un CDN, a Laravel le llega la
petición en claro y manda a Redsys una `merchantUrl` con `http://`.

Redsys **no sigue redirecciones**: recibe el 307 de tu proxy hacia HTTPS, lo da
por fallido, y la notificación no llega. Tu aplicación no registra ningún error,
porque desde su lado no ha pasado nada. Las suscripciones se quedan en
`incomplete` para siempre.

En `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: '*');   // o la lista de IPs de tu proxy
})
```

Comprueba que lo tienes bien mirando qué sale en Ajustes como «Notificación de
Redsys»: si empieza por `http://` y tu sitio es HTTPS, esto te va a morder.

**No hace falta `withoutOverlapping()`.** El comando coge un lock él solo, así
que dos pases que se pisen no cobran dos veces al mismo cliente: el segundo se
salta y lo dice. Usa los locks atómicos de Laravel, así que **si tu caché es
`database`, necesitas la tabla `cache_locks`** — la que trae Laravel de serie.

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

Y contra el Redsys de verdad, para comprobar que la tabla de errores dice lo que
Redsys dice y no lo que creemos:

```bash
docker compose exec lab php /demo/verify-redsys.php
```

Prueba lo que se puede forzar sin un titular delante: la firma incorrecta y la
referencia muerta. Lo que necesita 3DS se comprueba a mano dando de alta una
tarjeta en el laboratorio.

**Para probar la notificación, entra al laboratorio por la URL del túnel, no por
`127.0.0.1`.** La `merchantUrl` que viaja a Redsys se construye con el host de la
petición: si entras por localhost, Redsys recibe `http://127.0.0.1:8000/...` y no
puede llamarte. Es el mismo motivo por el que en producción hace falta
`TrustProxies`.

Los tests también corren ahí, que es lo cómodo si tu PHP no trae `pdo_sqlite`:

```bash
docker compose exec lab sh -c 'cd /package && vendor/bin/phpunit'
```

> El alta se completa entera en `localhost` **si tu comercio tiene «Enviar
> parámetros en las URLs» en SÍ**. Con la casilla en NO hace falta exponer la
> aplicación, porque la referencia solo llega por la notificación.

### Probar la notificación servidor-a-servidor

Es lo único que no se puede comprobar en local: Redsys no alcanza tu máquina.
El laboratorio trae un túnel opcional para eso.

```bash
NGROK_AUTHTOKEN=... NGROK_DOMAIN=lo-tuyo.ngrok-free.app   APP_URL=https://lo-tuyo.ngrok-free.app   docker compose --profile tunel up
```

Luego pega en tu panel de Redsys, como URL de notificación:

```
https://lo-tuyo.ngrok-free.app/redsys/subscriptions/notify
```

Tres cosas que cuestan una tarde si no se saben:

- **`APP_URL` es obligatorio y tiene que ser el dominio público.** La URL de
  notificación se construye con `route()`, así que si la aplicación se sigue
  creyendo en `localhost` le manda *eso* a Redsys como `merchantUrl` y no llega
  nada, con el túnel levantado y todo. El laboratorio avisa al arrancar.
- **Usa un dominio fijo**, de los que da ngrok en el plan gratuito. Con una
  dirección aleatoria hay que volver a pegarla en el panel de Redsys en cada
  arranque.
- El `docker compose up` normal **no necesita nada de esto**: el túnel va en un
  perfil aparte y quien no lo use ni se entera.

## Panel

El paquete trae un panel para operar en producción, en `/redsys-subscriptions`:

- **Resumen** — ingresos al mes, activas, sin cobrar, altas sin terminar, quién
  necesita atención y qué se cobra a continuación.
- **Suscripciones** — listado buscable por nº, pedido, últimos cuatro dígitos o
  referencia, filtrable por estado. Los cuatro dígitos solo están si tu comercio
  tiene activado el envío del número enmascarado en su TPV: Redsys no lo manda
  por defecto, ni en la notificación ni en la respuesta del cobro. Desde aquí se **da de baja** y se **lanza un
  cobro en el momento**, que es lo que hace falta cuando un cliente llama
  diciendo que ya tiene saldo.
- **Ficha de una suscripción** — la tarjeta guardada, el cobro y el **historial
  de intentos**, cada uno con el código que devolvió Redsys.
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

## Historial de cobros

Cada intento deja una fila, salga bien o mal. El estado dice cómo está la
suscripción ahora; el historial dice cómo ha llegado hasta ahí, que es lo que
hace falta cuando un cliente pregunta por un cargo.

```php
$subscription->charges;          // el más reciente primero

$charge->order;                  // el pedido de ese intento
$charge->outcome;                // ChargeOutcome
$charge->response_code;          // '0180', 'SIS0321'…
$charge->responseLabel();        // «0180 · Denegada»
```

El importe se congela en la fila: si mañana subes el precio, lo que se cobró
aquel día no cambia. Y el alta de tarjeta es la primera línea del historial,
porque también fue un cobro.

## Enterarte de lo que pasa

Cada intento de cobro dispara un evento. Es lo único que tu aplicación no sabe
por su cuenta: cuando llama a `cancel()` ya se ha enterado, pero lo que hizo el
cron a las tres de la mañana no lo ve nadie.

```php
use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use MarioDevv\RedsysSubscriptions\Events\SubscriptionCharged;

Event::listen(function (SubscriptionCharged $event) {
    $titular = $event->subscription->billable;

    match ($event->charge->outcome) {
        ChargeOutcome::Declined    => $titular->notify(new CobroFallido($event->charge)),
        ChargeOutcome::ScaRequired => $titular->notify(new AutenticaTuPago($event->subscription)),
        ChargeOutcome::TokenDead   => $titular->notify(new CambiaLaTarjeta($event->subscription->cardUpdateLink())),
        ChargeOutcome::Authorized  => $titular->notify(new Recibo($event->charge)),
    };
});
```

El estado ya está aplicado cuando llega el evento, así que
`$event->subscription->status` te dice si además se ha quedado cancelada. El
alta de tarjeta también lo dispara, porque también fue un cobro.

Es **un** evento y no seis a propósito: los seis te obligarían a registrar seis
listeners para hacer un `match` que cabe en uno.

## Cambiar la tarjeta

Cuando Redsys mata la referencia con `SIS0321`, o el emisor pide autenticación
con `0195`, el titular tiene que registrar otra tarjeta. **La suscripción no se
rehace**: conserva su número, su importe y su historial.

```php
$link = $subscription->cardUpdateLink();   // firmado, caduca en 7 días
$link = $subscription->cardUpdateLink(30); // o los días que quieras
```

Mándaselo al titular, o cópialo desde la ficha del panel. Abrirlo le lleva al
formulario de Redsys; cuando vuelve, la suscripción queda activa con la tarjeta
nueva.

El enlace va **firmado y con caducidad** a propósito: quien lo abra deja su
tarjeta asociada a esa suscripción, así que una dirección adivinable sería una
forma de pagar con la cuenta de otro.

## Bajas

```php
$subscription->cancel();      // al final del periodo ya pagado
$subscription->cancelNow();   // de inmediato

$subscription->onGracePeriod(); // dada de baja, pero aún le queda periodo
$subscription->valid();         // activa, o en ese periodo de gracia
$user->subscribed();            // lo mismo, desde el titular
```

`cancel()` deja de cobrar desde ya, pero el acceso le dura hasta la fecha que le
quedaba: le cobraste el mes entero y cortarle el día que se da de baja es
quedarte su dinero. Para dar acceso mira `valid()`, no `active()`.

## Estados

Las transiciones no son de manual: salen de observar qué responde Redsys.

| Respuesta de Redsys | Estado | Qué hacer |
|---|---|---|
| `0000`–`0099` | `active` | Nada. Se reinicia el contador de fallos. |
| `0195` | `past_due_sca` | Enviar al titular a reautenticar. **No reintentar. No cancelar.** |
| `SIS0321`, `0101`, `0191`, `0125`, `0106`, `0202`, `9093`, `9253` | `canceled` | La tarjeta está muerta. Mandar un `cardUpdateLink()`. |
| `SIS0042`, `SIS0026`, `SIS0028`, `SIS0430`, `0904`, `9104`, `9218`, `9256` | *sin cambios* | **Es tu configuración, no la tarjeta.** El pase se detiene. |
| `0909`, `0912`, `9912`, `9997`, `9998`, `9999`, `0913`, `SIS0051`, `SIS0001` | *sin cambios* | Transitorio. Se reintenta sin gastar intento. |
| Otra denegación | `past_due` | Reintentar, hasta 3 veces, cada 3 días. |

Las dos filas de en medio son las que más caro cuestan si se ignoran, y son las
que casi todo el mundo mete en el mismo saco que una denegación:

- **Un fallo de comercio le sale igual a todas tus suscripciones.** Tratado como
  denegación, una clave mal puesta te **cancela la cartera entera** en tres
  pases. Aquí no suma fallo, no cancela, no mueve la fecha, y el comando aborta
  el pase en cuanto ve uno.
- **Un emisor caído no es culpa del titular.** No puede gastarle uno de sus tres
  intentos.

El valor guardado es el de la columna «Estado»; lo traducido es solo la etiqueta,
y sale de `Subscription::statusLabels()`.

Dos detalles que cuestan caro si se ignoran:

- **`0195` no es un rechazo de tarjeta.** El emisor pide autenticación del titular
  para *esa* cuota. La tarjeta sigue viva y volverá a cobrar el mes siguiente.
  Cancelar aquí es tirar clientes que pagan.
- **Cada intento estrena número de pedido, menos uno.** Redsys rechaza los
  repetidos con `SIS0051`. Ese código cuenta como transitorio y no como fallo
  del titular: el choque es nuestro, no suyo. La excepción es el cobro que se
  quedó sin respuesta fiable —se cayó la red, o volvió algo que no venía
  firmado por Redsys—: ahí puede que la autorizara y el dinero esté cobrado,
  así que el pedido se guarda y **el siguiente intento repite ese mismo**. Es
  la única forma de que Redsys pueda hacer de guardia, porque dos pedidos
  distintos para él son dos cobros. Si entonces responde `SIS0051`, lo único
  que sabes es que ya lo tenía, no cómo acabó: esa suscripción se queda
  esperando a que alguien mire el back office. No cobrar dos veces vale más que
  desatascarla sola.
- **Un fallo espera `Subscription::RETRY_DAYS` antes del siguiente intento.**
  Reintentar el mismo día es gastar los tres contra el mismo saldo vacío; tres
  días dan margen a que entre una nómina.

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
| Alta de tarjeta COF, **3DS incluido** | sí, entera | |
| **Notificación servidor-a-servidor** | sí | |
| Cobro MIT con la referencia guardada | sí | |
| Mensual, semanal y anual | sí, las tres | |
| `0000`, `SIS0321`, `SIS0042`, `SIS0051` | sí | |
| 3DS denegado y pago cancelado | sí | |
| `0195` | | sí |
| El resto del catálogo de códigos | | sí |
| Verificación de firma, SHA-256 y SHA-512 | | sí |

El alta pasa el 3DS de verdad, la notificación llega y activa la suscripción
**sin que el titular vuelva del navegador**, y el cobro recurrente sale
autorizado sin volver a pedir autenticación, que es lo que tenía que demostrar
la exención MIT.

Un 3DS denegado o un pago cancelado dejan la suscripción en `incomplete` sin
inventarse una línea en el historial. La cancelación también llega por
notificación y el paquete la ignora, que es lo que evita activar suscripciones
que nadie ha pagado.

**Lo que no está probado:** producción, el `0195`, y los ~30 códigos de respuesta
y 683 `SISxxxx` restantes del catálogo. La mayoría no se pueden provocar desde
fuera, así que su clasificación es criterio razonado y no observación.

### Para la 1.0

Dos cosas, y ninguna es una función nueva:

1. **Un cobro en producción.** Todo lo verificado lo está contra el sandbox.
   Cambia el endpoint y la clave, nada más, pero nadie lo ha ejecutado con
   dinero de verdad. Es lo único que separa esto de la 1.0.
2. **Forzar un `0195`.** Se vio en el sandbox en septiembre y no se ha podido
   reproducir. Es el caso del que más presume el paquete —no reintentar, no
   cancelar— y hoy descansa en una observación y en tests.

### Mejoras conocidas

Ninguna bloquea la 1.0, todas salieron de usar el paquete de verdad:

- **`past_due_sca` corta el acceso.** `valid()` mira `active()` o periodo de
  gracia, así que un titular al que el banco le pide autenticar pierde el
  servicio aunque su tarjeta siga viva y vaya a cobrar el mes que viene.
  Decisión de producto, no bug.
- **Un alta denegada no deja rastro.** `completeCheckout()` sale sin registrar
  nada, así que soporte no puede ver que alguien lo intentó tres veces.
- **«Lanzar cobro ahora» es síncrono** dentro de la petición HTTP. Con muchas
  vencidas hay que mandarlo a una cola.
- **Avisos de configuración en Ajustes:** URL de notificación inalcanzable,
  entorno real sin clave, o el envío del número enmascarado desactivado —que es
  lo que deja la búsqueda por los cuatro dígitos sin funcionar y en silencio.

### Puede que nunca

Cupones, prorrateo, periodos de prueba, facturas en PDF, multidivisa, Bizum y
preautorizaciones. Abre un issue si necesitas alguno.

## Créditos

El protocolo lo pone [`creagia/redsys-php`](https://github.com/creagia/redsys-php).
Este paquete solo añade la capa de suscripciones.

## Licencia

MIT. Ver [LICENSE](LICENSE).
