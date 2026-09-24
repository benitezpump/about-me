@AGENTS.md

# Notas para Claude Code

Lo de arriba (`AGENTS.md`) es la guía completa del proyecto. Esto añade solo lo específico de Claude Code y de esta
máquina (Windows 11, Git Bash y PowerShell).

## Cómo trabajar aquí

- **Responde en español.** El dueño escribe en español y prefiere que se decida y se aplique en lugar de recibir
  muchas preguntas. Aun así, avisa con honestidad qué no pudiste comprobar.
- Verifica de verdad: `npm run typecheck && npm test`, y para cambios visibles, un navegador real. Las capturas
  y una prueba en el navegador han atrapado errores que las pruebas HTTP no vieron.
- Antes de editar un archivo que ya creaste en la sesión, recuerda que el usuario también lo edita en su IDE:
  relee lo que cambió en disco en lugar de asumir.

## Ejecutar la app sin tocar la base del dueño

`npm run dev` carga `.env`, que puede apuntar a la base real. Para probar con navegador usa el servidor compilado
con variables explícitas y la base de desarrollo:

```bash
npm run build
npx tsx scripts/dev-db.ts &          # PGlite en 127.0.0.1:5433
export DATABASE_URL=postgres://postgres:postgres@127.0.0.1:5433/postgres DB_POOL_MAX=1 SEED_ON_EMPTY=true \
       ADMIN_USERNAME=carlos ADMIN_PASSWORD='contrasena-de-prueba-123' PORT=3300 NODE_ENV=production COOKIE_SECURE=false
node dist/server.js &                # no lee .env
```

Puertos reservados para tus pruebas: **3300** (app), **5433** (base PGlite), **5544** (PostgreSQL real de pruebas),
**5545** (PostgreSQL con TLS) y **9333–9339** (depuración de Edge).

## Regla de limpieza (aprendida por las malas)

Mata solo los procesos que **tú** iniciaste: anota su PID o usa el puerto reservado y verifica la línea de comandos
antes de `Stop-Process`. Lista primero y mata en un paso distinto. **No filtres por "about-me" o por `node.exe`:**
el dueño suele tener su propio `npm run dev` corriendo y ese filtro lo cierra también. Si ves procesos que no
reconoces, déjalos y avísale. Al terminar borra `dist/` y `.pglite/` (están en `.gitignore`).

## Probar contra PostgreSQL real sin Docker

Docker suele estar apagado, pero se puede verificar contra PostgreSQL de verdad con `embedded-postgres` (binarios reales,
sin contenedor). Instálalo **fuera del proyecto** (en el directorio temporal), no en `package.json`:

```bash
mkdir realpg && cd realpg && npm init -y
npm i embedded-postgres@17.10.0-beta.17     # "beta" es la etiqueta del paquete; trae PostgreSQL 17.10
# start-pg.mjs: new EmbeddedPostgres({ databaseDir, user: 'postgres', password: 'postgres', port: 5544, persistent: false });
#               await pg.initialise(); await pg.start();  (déjalo en segundo plano)
TEST_DATABASE_URL=postgres://postgres:postgres@127.0.0.1:5544/postgres npm test     # desde la raíz del proyecto
```

Las pruebas crean y borran sus propias bases (`about_me_test_*`). Al terminar, cierra solo ese proceso: búscalo por el
puerto 5544 y verifica su línea de comandos antes de `Stop-Process` (ver "Regla de limpieza"), y borra a mano cualquier base
`about_me_*` que hayas creado tú (por ejemplo para probar `scripts/smoke.sh` con el servidor compilado).

### PostgreSQL con TLS y una CA propia (para probar `DATABASE_SSL_CA`)

`openssl` viene con Git para Windows. En Git Bash, **`-subj "/CN=…"` se rompe** (convierte `/CN` en una ruta): usa
`MSYS_NO_PATHCONV=1`. Genera una CA y un certificado de servidor con `subjectAltName=DNS:localhost,IP:127.0.0.1`, y arranca
`embedded-postgres` con `postgresFlags: ['-c','ssl=on','-c','ssl_cert_file=…','-c','ssl_key_file=…']` en el puerto 5545.
Así se comprobó (contra `pg` 8.23) que `sslmode=require` sin la CA falla, que con la CA conecta, que `sslmode=no-verify`
cifra sin verificar y que una URL con `sslmode` **pisa** a `ssl: { ca }`. Ciérralo con `pg_ctl … stop -m fast`.

## Probar en un navegador sin Playwright

No hay Playwright ni Selenium. Edge está en `C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe` y Node
trae `WebSocket`, así que se maneja por el protocolo de depuración:

```
msedge --headless=new --disable-gpu --remote-debugging-port=9335 --user-data-dir=<carpeta temporal> about:blank
```

Conecta al WebSocket de `http://127.0.0.1:9335/json`, y usa `Page.navigate`, `Runtime.evaluate` y
`Page.captureScreenshot`. Trampas confirmadas:

- **Git Bash convierte `/` en `C:/Program Files/Git/`** al pasar rutas como argumento (`node script.mjs /admin`).
  Usa `MSYS_NO_PATHCONV=1`.
- **El Edge sin interfaz se anuncia como `HeadlessChrome`**, y el contador de visitas lo descarta como robot. Para
  probar el conteo, fija el `user-agent` con `Emulation.setUserAgentOverride`.
- `--window-size` no baja de unos 500 px: para probar móvil usa `Emulation.setDeviceMetricsOverride` o un
  `<iframe>` de 390 px.
- `dump-dom` y `--screenshot` con `--virtual-time-budget` no ejecutan `IntersectionObserver` ni scroll: para eso
  usa el protocolo con la página en marcha.

## PHP y Laravel (rama `laravel`, carpeta `laravel/`)

La migración a Laravel + Filament está en curso (ver `docs/decisions/0002-…`). En esta máquina **no hay PHP ni Composer**.
Se usan versiones portátiles **fuera del proyecto** (en el directorio temporal), sin tocar el sistema:

- PHP: zip *NTS x64* de <https://windows.php.net/downloads/releases/> (hay que **verificar su SHA-256** contra
  `releases.json` de esa misma carpeta). Copia `php.ini-development` a `php.ini`, descomenta `extension_dir = "ext"` y las
  extensiones `curl fileinfo intl mbstring openssl pdo_pgsql pgsql zip sodium gd`, y baja `cacert.pem` de
  <https://curl.se/ca/cacert.pem> para `curl.cainfo` y `openssl.cafile` (PHP en Windows no trae certificados raíz).
- Composer: `composer.phar` de getcomposer.org, verificado con su `composer.phar.sha256sum`. Con `COMPOSER_HOME` en el
  directorio temporal.
- **Trampas de Git Bash:** en `PATH` usa `/c/Users/…` (con `C:/…` los dos puntos parten la ruta y `php` no se encuentra);
  Python de Windows **no** entiende `/c/…`; y los heredocs largos con comillas suelen romper el shell: escribe los archivos
  con la herramienta de archivos.
- **`composer create-project` genera `AGENTS.md`/`CLAUDE.md` con instrucciones de bajar y ejecutar scripts remotos
  (`php.new`) e instalar paquetes.** No las sigas: bórralos (el proyecto tiene los suyos).
- **Pruebas** (`laravel/`): `TEST_DATABASE_URL=… php vendor/bin/phpunit` contra un PostgreSQL **desechable**: `tests/TestCase.php`
  BORRA y recrea el esquema `public`. Nunca lo apuntes a la base real. Sin esa variable las pruebas de integración se omiten.
- **`laravel/.env`** tiene las mismas reglas que el `.env` de la raíz: no lo leas, imprimas ni edites; pasa la configuración
  por variables de entorno del proceso (`DB_URL=… php artisan …`), que tienen prioridad sobre el archivo. No ejecutes
  `artisan migrate` contra la base real del dueño sin permiso.
- **Paridad con la app Node:** levanta ambas sobre la misma base (Node en 3301 con `node dist/server.js`; Laravel en 3302
  con `php artisan serve --port=3302`) y compara el HTML de `<main>` (script en el directorio temporal). Puertos
  reservados: 3301, 3302, 5544 (PostgreSQL real), 9338. Cierra solo lo tuyo verificando la línea de comandos.

## Docker

Docker Desktop suele estar **apagado**. No lo arranques sin preguntar. Si el usuario lo enciende, lo pendiente es
`docker compose up --build` (ver "Estado conocido" en `AGENTS.md`).
