<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use MarioDevv\RedsysSubscriptions\Billable;

/**
 * El User de Laravel con el trait del paquete. Sustituye al que trae la
 * aplicación recién creada: es la única línea que hace falta para que un
 * modelo pueda tener suscripciones.
 */
class User extends Authenticatable
{
    use Billable, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];
}
