# syntax=docker/dockerfile:1

# La imagen usa FrankenPHP (Caddy + PHP en un solo proceso, con HTTP/2 y compresión). Se eligió sobre nginx + php-fpm porque
# es UN proceso y un archivo de configuración corto: menos piezas que puedan fallar en un despliegue.
#
# ¡Esta imagen NO se ha construido ni ejecutado aún! Al escribirla, Docker estaba apagado. La integración continua
# (.github/workflows/ci.yml, trabajo "docker") la construye y ejercita de punta a punta la primera vez que corra.

# --- 1) Base: PHP 8.4 con las extensiones que necesita la aplicación --------------------------------------------------
FROM dunglas/frankenphp:1-php8.4-alpine AS base
# pdo_pgsql: la base (PostgreSQL / Supabase). intl y zip: los exige Filament. opcache: rendimiento.
RUN install-php-extensions pdo_pgsql intl zip opcache

# --- 2) Compilación: dependencias de producción y recursos del panel -----------------------------------------------------
FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1

# Primero solo lo que define las dependencias: la capa se reutiliza mientras no cambien.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist

COPY . .
# Ahora sí el resto: autocarga optimizada, descubrimiento de paquetes y los recursos (CSS/JS) de Filament, que no van en git.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts \
 && php artisan package:discover --ansi \
 && php artisan filament:assets --no-interaction \
 && rm -rf storage/logs/*

# --- 3) Imagen final: sin Composer, sin dependencias de desarrollo, sin root ---------------------------------------------
FROM base
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

WORKDIR /app
COPY --from=build --chown=www-data:www-data /app /app
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
# Valores de producción de PHP (sin mostrar errores al visitante) y luego los ajustes propios.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint

# Los directorios donde escribe Caddy (XDG_* los define la imagen base) y donde escribe Laravel deben ser de www-data.
RUN mkdir -p /data/caddy /config/caddy \
 && chown -R www-data:www-data /data /config /app/storage /app/bootstrap/cache

# La imagen base le da a `frankenphp` la capacidad de abrir puertos menores a 1024 (atributo de archivo `cap_net_bind_service=+ep`).
# Aquí se escucha en el 10000, así que no hace falta, y estorba: en las plataformas que quitan esa capacidad del contenedor
# (Render) el kernel se niega a ejecutar el binario ("frankenphp: Operation not permitted", estado 126). Se quita copiando el
# archivo: `cp` no lleva consigo los atributos extendidos, que es donde el kernel guarda las capacidades.
RUN cp /usr/local/bin/frankenphp /usr/local/bin/frankenphp.copy  && mv -f /usr/local/bin/frankenphp.copy /usr/local/bin/frankenphp  && chmod 755 /usr/local/bin/frankenphp

USER www-data
EXPOSE 10000

# Responde 200 solo si la base contesta (/healthz). Render usa la misma ruta como comprobación de salud.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD php -r "exit(@file_get_contents('http://127.0.0.1:'.(getenv('PORT') ?: 10000).'/healthz') !== false ? 0 : 1);"

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
