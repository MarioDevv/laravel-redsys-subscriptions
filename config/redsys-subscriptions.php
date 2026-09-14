<?php

return [
    // Sin valores por defecto a proposito: una clave con fallback en el
    // repositorio termina firmando en produccion el dia que alguien olvida
    // poner la suya. Si falta, Redsys responde SIS0042 y se ve enseguida.
    'merchant_code' => env('REDSYS_MERCHANT_CODE'),
    'terminal'      => env('REDSYS_TERMINAL', 1),
    'secret_key'    => env('REDSYS_SECRET_KEY'),
    'production'    => env('REDSYS_PRODUCTION', false),

    // A donde vuelve el titular despues de pasar por Redsys.
    'return_url'    => env('REDSYS_RETURN_URL', '/'),

];
