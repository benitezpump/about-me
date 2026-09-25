#!/usr/bin/env bash
# Comprobaciones de humo contra una instancia YA EN MARCHA (la de docker compose en la integración continua, o una local).
# Verifican lo que las pruebas de PHPUnit no pueden: que la imagen arranca, migra, siembra y sirve de verdad por HTTP contra
# un PostgreSQL real, con la configuración de producción (cachés de configuración, rutas y vistas).
#
#   scripts/smoke.sh [URL]        # por defecto http://127.0.0.1:8080
#
# Espera el contenido de EJEMPLO (database/seed/content.example.json): es el que carga un despliegue desde el repositorio.
# El inicio de sesión del panel (Livewire) lo cubren las pruebas de PHPUnit, no este script.
set -euo pipefail

BASE="${1:-http://127.0.0.1:8080}"
BASE="${BASE%/}"
HEADERS="$(mktemp)"
trap 'rm -f "$HEADERS"' EXIT

failed=0
pass() { echo "  ok     $1"; }
fail() { echo "  FALLA  $1"; failed=1; }
check() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then pass "$name"; else fail "$name"; fi; }

status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
BROWSER='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'

echo "Esperando a que ${BASE}/healthz responda (hasta 120 s)…"
ready=0
for _ in $(seq 1 60); do
  if [ "$(status "${BASE}/healthz" || true)" = "200" ]; then ready=1; break; fi
  sleep 2
done
if [ "$ready" != "1" ]; then echo "La aplicación no respondió a tiempo."; exit 1; fi

echo "Comprobaciones:"
health="$(curl -s "${BASE}/healthz")"
check "/healthz confirma que la base responde"           grep -q '"status":"ok"' <<<"$health"

home="$(curl -s -A "$BROWSER" -D "$HEADERS" "${BASE}/")"
check "/ responde 200"                                   test "$(status -A "$BROWSER" "${BASE}/")" = "200"
check "/ trae el contenido sembrado (perfil)"            grep -q "Ana" <<<"$home"
check "/ trae los proyectos de la base"                  grep -q "Sistema Alfa" <<<"$home"
check "/ trae las tecnologías del catálogo"              grep -q '<li>PHP</li><li>Laravel</li>' <<<"$home"
check "/ cuenta la visita y muestra el contador"         grep -q 'class="views"' <<<"$home"
check "/ envía una CSP que solo permite scripts propios" grep -qi "script-src 'self';" "$HEADERS"
check "/ no permite scripts ni estilos en línea"         bash -c "! grep -qi \"script-src 'self' 'unsafe\" \"$HEADERS\""
check "/ envía X-Content-Type-Options: nosniff"          grep -qi "x-content-type-options: nosniff" "$HEADERS"
check "/ no revela la versión de PHP (X-Powered-By)"     bash -c "! grep -qi 'x-powered-by' \"$HEADERS\""
check "/static/css/site.css se sirve"                    test "$(status "${BASE}/static/css/site.css")" = "200"
check "/favicon.svg se genera con las iniciales"         grep -q ">AP<" <<<"$(curl -s "${BASE}/favicon.svg")"
check "una ruta inexistente da 404"                      test "$(status "${BASE}/no-existe")" = "404"
check "/admin sin sesión redirige al login"              test "$(status "${BASE}/admin")" = "302"
check "/admin/login carga y pide usuario (no correo)"    grep -q "Usuario" <<<"$(curl -s "${BASE}/admin/login")"
check "el panel lleva su propia CSP (Livewire/Alpine)"   grep -qi "unsafe-eval" <<<"$(curl -sI "${BASE}/admin/login")"
check "los archivos de la aplicación no se sirven (.env)"           test "$(status "${BASE}/.env")" = "404"
check "los archivos de la aplicación no se sirven (composer.json)"  test "$(status "${BASE}/composer.json")" = "404"

if [ "$failed" != "0" ]; then echo "Alguna comprobación falló."; exit 1; fi
echo "Todas las comprobaciones pasaron."
