<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Quien puede ver el panel.
 *
 * Cerrado por defecto: en cualquier entorno que no sea local hace falta definir
 * el Gate a mano. Un panel con las suscripciones de todos los clientes no puede
 * quedarse abierto porque alguien se olvidara de configurarlo.
 */
class Authorize
{
    public const GATE = 'viewRedsysSubscriptions';

    public function handle(Request $request, Closure $next)
    {
        if (! app()->environment('local') && ! Gate::allows(self::GATE, [$request->user()])) {
            throw new AccessDeniedHttpException('No autorizado para ver las suscripciones.');
        }

        return $next($request);
    }
}
