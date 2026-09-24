#!/usr/bin/env bash
# Comprobaciones de humo contra una instancia YA EN MARCHA (la de docker compose en la integración continua, o una
# local). Verifica lo que las pruebas con PGlite o `app.inject` no pueden: que la imagen arranca, migra, siembra y
# sirve de verdad por HTTP contra un PostgreSQL real.
#
#   scripts/smoke.sh [URL]                         # por defecto http://127.0.0.1:3000
#   SMOKE_ADMIN_USER=... SMOKE_ADMIN_PASSWORD=... scripts/smoke.sh   # además, prueba el inicio de sesión real
set -euo pipefail

BASE="${1:-http://127.0.0.1:3000}"
BASE="${BASE%/}"
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

failed=0
pass() { echo "  ok     $1"; }
fail() { echo "  FALLA  $1"; failed=1; }
check() { local name="$1"; shift; if "$@" >/dev/null 2>&1; then pass "$name"; else fail "$name"; fi; }

status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "Esperando a que ${BASE}/healthz responda (hasta 90 s)…"
ready=0
for _ in $(seq 1 45); do
  if [ "$(status "${BASE}/healthz" || true)" = "200" ]; then ready=1; break; fi
  sleep 2
done
if [ "$ready" != "1" ]; then echo "La aplicación no respondió a tiempo."; exit 1; fi

echo "Comprobaciones:"
health="$(curl -s "${BASE}/healthz")"
check "/healthz confirma que la base responde"        test "$health" = '{"status":"ok"}'

home="$(curl -s -D "$JAR.headers" "${BASE}/")"
check "/ responde 200"                                test "$(status "${BASE}/")" = "200"
check "/ trae el contenido sembrado (perfil)"         grep -q "Ana" <<<"$home"
check "/ trae los proyectos de la base"               grep -q "Sistema Alfa" <<<"$home"
check "/ trae las tecnologías del catálogo"           grep -q 'class="stack">PHP, Laravel' <<<"$home"
check "/ envía una CSP que solo permite scripts propios" grep -qi "script-src 'self'" "$JAR.headers"
check "/static/css/site.css se sirve"                 test "$(status "${BASE}/static/css/site.css")" = "200"
check "una ruta inexistente da 404"                   test "$(status "${BASE}/no-existe")" = "404"
check "/admin sin sesión redirige al login"           test "$(status "${BASE}/admin")" = "302"
check "/admin/login carga"                            test "$(status "${BASE}/admin/login")" = "200"
rm -f "$JAR.headers"

if [ -n "${SMOKE_ADMIN_USER:-}" ] && [ -n "${SMOKE_ADMIN_PASSWORD:-}" ]; then
  echo "Inicio de sesión real (escribe una sesión en PostgreSQL):"
  page="$(curl -s -c "$JAR" -b "$JAR" "${BASE}/admin/login")"
  csrf="$(grep -o 'name="_csrf" value="[^"]*"' <<<"$page" | head -1 | sed 's/.*value="//; s/"$//')"
  check "el formulario de login trae su token CSRF"   test -n "$csrf"

  code="$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "${BASE}/admin/login" \
    --data-urlencode "_csrf=${csrf}" --data-urlencode "username=${SMOKE_ADMIN_USER}" --data-urlencode "password=${SMOKE_ADMIN_PASSWORD}")"
  check "el login con las credenciales correctas redirige" test "$code" = "302"

  panel="$(curl -s -c "$JAR" -b "$JAR" "${BASE}/admin")"
  check "el panel carga con la sesión iniciada"       grep -q "Visitas a la portada" <<<"$panel"
  check "el panel lista las tecnologías del catálogo" grep -q "Tecnolog" <<<"$(curl -s -b "$JAR" "${BASE}/admin/technologies")"

  bad="$(curl -s -o /dev/null -w '%{http_code}' -X POST "${BASE}/admin/login" \
    --data-urlencode "_csrf=falso" --data-urlencode "username=${SMOKE_ADMIN_USER}" --data-urlencode "password=${SMOKE_ADMIN_PASSWORD}")"
  check "un login sin el token CSRF correcto se rechaza" test "$bad" = "400"
else
  echo "(Sin SMOKE_ADMIN_USER / SMOKE_ADMIN_PASSWORD: se omite la prueba de inicio de sesión.)"
fi

if [ "$failed" != "0" ]; then echo "Alguna comprobación falló."; exit 1; fi
echo "Todas las comprobaciones pasaron."
