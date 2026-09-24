# 0002. Migración a Laravel + Filament

- **Estado:** implementada en la rama `laravel` (fases 0 a 6). **Pendiente de verificar fuera de esta máquina:** la imagen de Docker, `docker-compose.yml`, la integración continua, Render y Supabase (nunca se han ejecutado).
- **Fecha:** 2026-09-24
- **Decide:** Carlos Benítez.
- **Relación con el ADR 0001:** lo reemplaza en su parte de arquitectura ("conservar Fastify + Nunjucks") y **cumple su plan
  de contingencia**: el 0001 dejó escrito que, si el panel necesitaba crecer, la migración sería a Laravel + Filament y no
  construirlo a mano. Aquí no creció el alcance: se migra por **estructura** (MVC por convención) y para **eliminar el
  código propio de seguridad del panel**, que era el riesgo principal identificado.

## Contexto

La aplicación Node (Fastify + Nunjucks + `pg`) funciona y está probada (145 pruebas), pero organiza a mano lo que un
framework impone por convención, y su panel (login, sesiones, CSRF, CRUD, importar/exportar) es código propio sin
auditoría externa. Se evaluó NestJS y se descartó: da estructura pero no trae panel, así que el código sensible se queda
y solo se reorganiza. Laravel es el stack principal del autor en su trabajo, y Filament trae el panel completo.

## Decisión

Migrar a **Laravel 13 (PHP ≥ 8.3, se usa 8.4) + Filament 5**. Se desarrolló en `laravel/` sin tocar la app Node hasta que la nueva la igualó; **al cortar, `laravel/` subió a la raíz y la app Node se archivó** en la etiqueta `node-legacy` (`git show node-legacy:src/app.ts`), fuera del árbol.

- **Base de datos: la misma.** Laravel adopta el esquema SQL existente (`database/sql/*.sql`, copiados de
  `db/migrations/` de la app Node). Una sola migración de Laravel los aplica con el mismo registro (`schema_migrations`) que usa la app
  Node: sobre una base ya migrada no repite nada y **las dos aplicaciones pueden convivir sobre la misma base**. El esquema
  no se reescribe con el constructor de Laravel (restricciones `NOT VALID`, llaves compuestas y RLS no caben en él).
- **MVC**: modelos Eloquent (`app/Models`), servicio que arma el contenido (`app/Services/SiteContent.php`, el "modelo de
  vista"), controladores delgados (`app/Http/Controllers`) y plantillas Blade (`resources/views`).
- **Se conserva:** esquema SQL, formato JSON de contenido, Supabase, `render.yaml` (ya usa Docker), CSS y JavaScript
  estáticos (servidos desde `/static`), y las reglas de seguridad del proyecto (RLS en toda tabla, CSP sin scripts
  inline, enlaces seguros, `TRUST_PROXY` con número de saltos, conexión cifrada con CA).

## Cómo se garantiza la paridad

1. **Comparación en vivo:** las dos aplicaciones sobre la misma base; el HTML de `<main>` debe ser idéntico (solo se
   ignora el espacio entre etiquetas; el de dentro del texto cuenta). Esto ya detectó un defecto real (un espacio antes
   de una coma) que la comparación solo de texto ocultaba.
2. **Pruebas portadas:** cada comportamiento de las 145 pruebas de Node se porta a PHPUnit, contra **PostgreSQL real**
   (nunca SQLite: el esquema no es compatible).
3. La app Node se apaga solo cuando la lista de paridad esté completa.

## Fases

| # | Fase | Estado |
|---|---|---|
| 0 | Esqueleto, adopción del esquema, `/healthz`, portada, favicon | **hecha** (HTML idéntico al de Node sobre la misma base) |
| 1 | Cabeceras de seguridad (CSP, HSTS), `TRUST_PROXY`, conexión con CA (`DATABASE_SSL_CA`), caché de contenido con invalidación | **hecha** (verificada con `libpq` contra un servidor TLS con CA propia) |
| 2 | Panel Filament: un solo administrador, 12 recursos, límite de intentos de login, cambio de contraseña | **hecha** (verificada en Edge real) |
| 3 | Importar y exportar JSON (validador, cobertura de columnas, serializador idéntico al de Node al byte) + contenido de ejemplo | **hecha** (verificada en Edge real) |
| 4 | Contador de visitas con privacidad (hash diario, `notrack`, DNT/GPC, robots) y tablero | **hecha** (verificada en Edge real) |
| 6 | Corte: `laravel/` sube a la raíz, la app Node se archiva en `node-legacy` | **hecha** (se hizo **antes** que la 5 para escribir el despliegue una sola vez sobre la estructura final) |
| 5 | Dockerfile (FrankenPHP), `docker-compose.yml`, `render.yaml`, integración continua con PostgreSQL, guías | **escrita**; piezas verificadas por separado (arranque en frío contra una base vacía, cachés de producción, humo HTTP, esquema de Render); **la imagen y la CI nunca se han ejecutado** |

## Consecuencias

**Buenas:** estructura por convención; panel mantenido por terceros; menos código propio sensible.

**Malas, y aceptadas:** se reescribió y se reverificó la seguridad; se pierde TypeScript estricto; la imagen de Docker es
más pesada; el hash `scrypt` del administrador de la app anterior no se puede verificar en PHP (se recrea desde el
entorno, una vez).

## Riesgos abiertos

- **Nada de esto se ha ejecutado en Docker, Render ni GitHub Actions** (la app Node tampoco). La primera ejecución de
  `.github/workflows/ci.yml` (trabajo `docker`) es lo que valida la imagen.
- **FrankenPHP en lugar de nginx + php-fpm:** se cambió lo que se había anunciado porque es UN proceso y un archivo de
  configuración corto (menos piezas que puedan fallar sin poder probarlas). Sus etiquetas de imagen y el `Caddyfile` no se
  han probado; la alternativa es `serversideup/php` (nginx + php-fpm) si algo falla.
- **Sesiones y autenticación:** resuelto. Las tablas `sessions` y `admin_users` de la app Node tenían otro formato que las de
  Laravel: se usan `web_sessions` (propia) y `admin_users` con `password_hash` bcrypt; `admin:ensure` reemplaza **una vez** un
  hash `scrypt` heredado con `ADMIN_PASSWORD`. La tabla `sessions` vieja queda sin uso.
- **`verify-full` contra Supabase:** comprueba también el nombre del servidor; no se pudo confirmar que coincida con el del
  pooler. Escape documentado: `DB_SSLMODE=verify-ca`.
- **CSP del panel:** Filament (Livewire/Alpine) exige `unsafe-inline` y `unsafe-eval` en `script-src` para `/admin`. Es una
  concesión acotada a esas rutas (el sitio público conserva la CSP estricta) y una prueba la vigila.
- **`migrate:fresh` no elimina funciones de PostgreSQL:** por eso las pruebas reinician el esquema `public` de una base
  desechable (`tests/TestCase.php`).
