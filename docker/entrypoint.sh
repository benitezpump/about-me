#!/bin/sh
# Arranque del contenedor: prepara la aplicación contra la base de datos y entrega el control al servidor.
# Cualquier paso que falle detiene el arranque (set -e): mejor no publicar que servir a medias.
set -eu
cd "${APP_DIR:-/app}"

# Sin estos valores nada funciona: se avisa claro en lugar de fallar más adelante con un error oscuro.
: "${APP_KEY:?Falta APP_KEY. Genera una con: php artisan key:generate --show}"
if [ -z "${DB_URL:-}" ] && [ -z "${DATABASE_URL:-}" ]; then
  echo "Falta DATABASE_URL (la URL de conexión a PostgreSQL)." >&2
  exit 1
fi

# 1) Esquema. --isolated evita que dos instancias que arrancan a la vez migren al mismo tiempo.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force --isolated
fi

# 2) Contenido inicial (solo si SEED_ON_EMPTY=true y la base no tiene perfil) y administrador inicial (si no existe).
php artisan content:seed --if-empty-env
php artisan admin:ensure

# 3) Cachés de producción. Van DESPUÉS de leer las variables del contenedor: `config:cache` las congela.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
