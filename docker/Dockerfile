FROM php:8.4-cli

# pdo_sqlite y sqlite3 vienen compilados en la imagen oficial. Se comprueba en
# el build en vez de darlo por hecho: sin ellos la app no arranca y el fallo
# aparecería a mitad de una migración, no aquí.
RUN php -m | grep -qx pdo_sqlite && php -m | grep -qx sqlite3

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libzip-dev \
 && docker-php-ext-install zip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app
EXPOSE 8000
ENTRYPOINT ["entrypoint"]
