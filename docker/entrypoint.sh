#!/bin/sh
# Laboratorio del paquete: una aplicación Laravel de verdad con el paquete
# montado desde el repositorio. Se crea la primera vez y se reutiliza después,
# así que el arranque siguiente es inmediato.
set -e

if [ ! -f /app/artisan ]; then
    echo "==> Primera vez: creando la aplicación Laravel"
    composer create-project laravel/laravel /app --no-interaction --prefer-dist

    cd /app
    # Repositorio de tipo path: composer enlaza /package, así que lo que toques
    # en el repositorio se ve aquí sin reinstalar nada.
    composer config repositories.paquete path /package
    composer require "mariodevv/laravel-redsys-subscriptions:*@dev" --no-interaction

    cp /demo/User.php app/Models/User.php
    cp /demo/routes.php routes/web.php
    cp /demo/demo.blade.php resources/views/demo.blade.php

    touch database/database.sqlite
fi

cd /app

# Las credenciales llegan por el entorno del contenedor. Se reescriben en cada
# arranque para poder cambiarlas sin reconstruir el laboratorio.
for pair in \
    "APP_URL=${APP_URL:-http://127.0.0.1:8000}" \
    "REDSYS_MERCHANT_CODE=${REDSYS_MERCHANT_CODE}" \
    "REDSYS_TERMINAL=${REDSYS_TERMINAL:-1}" \
    "REDSYS_SECRET_KEY=${REDSYS_SECRET_KEY}" \
    "REDSYS_PRODUCTION=${REDSYS_PRODUCTION:-false}" \
    "REDSYS_RETURN_URL=${REDSYS_RETURN_URL:-/}"
do
    key=${pair%%=*}
    sed -i "/^${key}=/d" .env
    echo "$pair" >> .env
done

if [ -z "${REDSYS_SECRET_KEY}" ]; then
    echo "!!  Sin REDSYS_SECRET_KEY: el paquete no firma y Redsys responderá SIS0042."
    echo "!!  Las del entorno de pruebas están publicadas en la documentación de Redsys."
fi

# La URL de notificación se construye con route(), o sea con APP_URL. Si esto
# sigue apuntando a localhost, a Redsys le llega 'localhost' como merchantUrl y
# la notificación no llega jamás, con el túnel levantado y todo.
case "${APP_URL}" in
    ''|*127.0.0.1*|*localhost*)
        echo "!!  APP_URL es local: Redsys no podrá notificar. Para probarlo, túnel:"
        echo "!!  APP_URL=https://tu-dominio.ngrok-free.app docker compose --profile tunel up"
        ;;
    *)
        echo "==> Notificación para el panel de Redsys: ${APP_URL}/redsys/subscriptions/notify"
        ;;
esac

php artisan migrate --force
php artisan config:clear >/dev/null

echo "==> Laboratorio en http://127.0.0.1:8000  ·  panel en /redsys-subscriptions"
exec php artisan serve --host=0.0.0.0 --port=8000
