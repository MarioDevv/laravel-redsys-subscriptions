<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Creagia\Redsys\Support\Signature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * La vuelta de Redsys tras el alta de tarjeta.
 *
 * Manda la notificacion servidor-a-servidor, no el navegador: es la unica que
 * llega siempre, firmada y completa. Con 'Enviar parametros en las URLs' en NO
 * la vuelta del navegador viene vacia y aqui solo sirve para redirigir. Cuando
 * si trae parametros firmados, los dos caminos acaban en el mismo sitio y el
 * segundo en llegar no hace nada; asi en local, donde Redsys no alcanza tu
 * maquina para notificar, la suscripcion se activa igualmente.
 */
class RedsysCallbacks
{
    public function notify(Request $request): Response
    {
        $params = $this->verified($request);

        if ($params === null) {
            return response('Firma invalida', 403);
        }

        Subscription::query()
            ->where('checkout_order', $params['DS_ORDER'] ?? '')
            ->first()
            ?->completeCheckout($params);

        // Redsys reintenta la notificacion si no recibe un 200.
        return response('OK');
    }

    public function back(Request $request, string $subscription): RedirectResponse
    {
        $subscription = Subscription::findOrFail($subscription);
        $params = $this->verified($request);

        // El id viene de la URL, que el titular puede manipular: solo se escribe
        // si los parametros estan firmados y hablan de esta suscripcion.
        if ($params !== null && ($params['DS_ORDER'] ?? null) === $subscription->checkout_order) {
            $subscription->completeCheckout($params);
        }

        return redirect()->to((string) config('redsys-subscriptions.return_url'));
    }

    /**
     * Parametros de Redsys, o null si la firma no cuadra o no vienen firmados.
     *
     * Nada de lo que devuelve esto se ha escrito todavia: una firma que no
     * cuadra no se guarda ni se anota como pago fallido, se rechaza y ya.
     *
     * @return array<string, mixed>|null Ds_* en MAYUSCULAS.
     */
    private function verified(Request $request): ?array
    {
        $encoded  = (string) $request->input('Ds_MerchantParameters', '');
        $received = (string) $request->input('Ds_Signature', '');

        if ($encoded === '' || $received === '') {
            return null;
        }

        $json   = base64_decode(strtr($encoded, '-_', '+/'));
        $params = json_decode(urldecode($json), true) ?: json_decode($json, true);

        if (! is_array($params)) {
            return null;
        }

        // Redsys no es consistente con las mayusculas de los Ds_ segun el canal.
        $params = array_change_key_case($params, CASE_UPPER);
        $order  = (string) ($params['DS_ORDER'] ?? '');
        $key    = (string) config('redsys-subscriptions.secret_key');

        // La firma se comprueba sobre el texto tal y como llego, nunca sobre lo
        // que hemos decodificado. La version la elige el comercio en su TPV.
        $valid = match ((string) $request->input('Ds_SignatureVersion', '')) {
            'HMAC_SHA512_V2' => Sha512Signature::verify($received, $encoded, $order, $key),
            'HMAC_SHA256_V1' => hash_equals(
                base64_decode(Signature::calculateSignature($encoded, $order, $key)),
                base64_decode(strtr($received, '-_', '+/')),
            ),
            default => false, // una version que no conocemos no se adivina
        };

        return $valid ? $params : null;
    }
}
