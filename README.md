# about-me

Sitio personal de una sola página con **todo el contenido en PostgreSQL** y un panel de administración (`/admin`) para
editarlo sin tocar código. **Laravel 13 + Filament 5**, con el HTML generado en el servidor (Blade). Sin framework de
frontend ni build de cliente.

- **Sitio público**: portada con perfil, herramientas, trabajo (línea de tiempo), docencia, proyectos propios, formación y
  certificaciones enlazadas. Tema oscuro/claro, contador de visualizaciones que respeta la privacidad.
- **Panel `/admin`** (un solo administrador): perfil, "Actualmente", catálogo de tecnologías, herramientas, empleos y
  docencia, proyectos, materias, talleres, estudios, certificaciones y enlaces de contacto. Cada cambio se ve en el sitio al
  guardar. Además **importa y exporta todo el contenido en JSON**.
- **Base de datos**: PostgreSQL (Supabase en producción). El esquema es SQL plano (`database/sql/`), con restricciones a
  nivel de base y seguridad por filas (RLS).

> **Estado (verifica antes de afirmar lo contrario).** Las 186 pruebas pasan contra PostgreSQL 17 real (185 sin tus
> archivos personales) y el sitio y el
> panel se recorrieron en un navegador (Edge). **Nada de esto se ha ejecutado en Docker, en Render, en Supabase ni en GitHub
> Actions**: la imagen, el `docker-compose.yml`, `render.yaml` (validado solo contra el esquema de Render) y la integración
> continua están escritos y sus piezas verificadas por separado, pero no de punta a punta. Ver "Estado conocido".

## Stack

| Capa | Elección |
|---|---|
| Lenguaje y framework | PHP 8.4, Laravel 13 |
| Panel | Filament 5 (Livewire), un solo administrador |
| Vistas del sitio | Blade (HTML en el servidor, sin JavaScript de framework) |
| Base de datos | PostgreSQL 17 (Supabase en producción), acceso con Eloquent y SQL |
| Servidor | FrankenPHP (Caddy + PHP) en una imagen de Docker |
| Pruebas | PHPUnit 12 contra PostgreSQL **real** |
| Despliegue | Docker; Render con la base en Supabase (`render.yaml`) |

La arquitectura y el alcance del panel se decidieron a propósito: ver `docs/decisions/`. La aplicación anterior (Node +
Fastify) quedó archivada en la etiqueta `node-legacy` de git (`git show node-legacy:src/app.ts`).

## Arranque con Docker

```bash
cp .env.example .env
# Edita .env: APP_KEY (php artisan key:generate --show), ADMIN_USERNAME, ADMIN_PASSWORD y la base de datos (Opción A o B).
docker compose up --build
```

Abre <http://localhost:8080> (sitio) y <http://localhost:8080/admin> (panel). En el primer arranque el contenedor migra el
esquema, carga el contenido inicial (el tuyo, `database/seed/content.json`, si existe; si no, el de ejemplo) y crea el
administrador. El puerto se publica solo en `127.0.0.1`.

## Desarrollo sin Docker

Necesitas PHP 8.4 con las extensiones `pdo_pgsql intl zip mbstring`, Composer y una PostgreSQL.

```bash
composer install
cp .env.example .env            # ajusta DB_URL, ADMIN_USERNAME y ADMIN_PASSWORD
php artisan key:generate        # escribe APP_KEY en .env
php artisan migrate             # aplica el esquema (database/sql/*.sql)
php artisan content:seed        # contenido inicial (el tuyo o el de ejemplo)
php artisan admin:ensure        # crea el administrador desde ADMIN_USERNAME / ADMIN_PASSWORD
php artisan serve               # http://127.0.0.1:8000  ·  panel en /admin
```

Sin `APP_KEY` las cookies y sesiones no funcionan. Con `SESSION_SECURE_COOKIE=true` el login no funciona por `http://`.

## Comandos

| Comando | Qué hace |
|---|---|
| `vendor/bin/phpunit` | Las pruebas. **Necesitan `TEST_DATABASE_URL`** (una PostgreSQL desechable): borran y recrean su esquema `public`. Sin esa variable las de integración se omiten. |
| `php artisan migrate --force` | Aplica el esquema pendiente (es idempotente y compatible con la aplicación anterior). |
| `php artisan content:seed [--force]` | Carga el contenido inicial si la base está vacía; con `--force` **reemplaza** todo el contenido editable. |
| `php artisan content:export [archivo]` | Exporta todo el contenido a JSON (respaldo). |
| `php artisan content:import archivo --yes` | Reemplaza todo el contenido por el de un JSON (lo valida completo antes de tocar la base). |
| `php artisan admin:create <usuario> [--reset]` | Crea al administrador; con `--reset` cambia su contraseña y cierra sus sesiones. |
| `php artisan admin:ensure` | Crea al administrador inicial desde el entorno si no existe (lo usa el arranque del contenedor). |

## Cómo se organiza

```
app/
  Http/Controllers/    SiteController (portada y favicon) y HealthController (/healthz).
  Http/Middleware/     SecurityHeaders (CSP, HSTS…) y ApplyTrustedProxies (TRUST_PROXY: saltos o lista de IP).
  Models/              Un modelo Eloquent por tabla de contenido, delgados (el esquema es la autoridad).
  Services/            SiteContent (arma la portada, con caché) y ViewCounter (visitas con privacidad).
  Content/             Formato JSON: ContentParser (validador), ContentSerializer, ContentExporter, ContentImporter, SeedContent.
  Filament/            El panel: Resources/ (uno por entidad), Pages/ (Cuenta, Importar y exportar), Widgets/ (visitas).
  Support/             Format, Period, TrustProxy, DatabaseSsl.
  Console/Commands/    admin:create, admin:ensure, content:seed, content:export, content:import.
database/
  sql/                 El esquema en SQL plano, numerado. NUNCA edites uno ya aplicado: añade el siguiente.
  migrations/          Dos migraciones de Laravel: aplica database/sql y crea web_sessions.
  seed/                content.example.json (ficticio, en git) y content.json (el tuyo, en .gitignore).
resources/views/       home.blade.php y components/ del sitio (el panel usa las vistas de Filament).
public/static/         CSS y JavaScript del sitio (sin nada en línea: la CSP no lo permite).
docker/                Caddyfile, php.ini y entrypoint.sh de la imagen.
tests/                 Unit/ y Feature/ (los de integración exigen PostgreSQL real).
docs/decisions/        Decisiones de arquitectura: por qué Laravel + Filament y cuándo cambiarlas.
scripts/               smoke.sh (comprobaciones HTTP) y validate-render.py (valida render.yaml).
```

## Base de datos

El esquema es SQL plano en `database/sql/` (no el constructor de esquemas de Laravel): usa restricciones `NOT VALID`,
llaves foráneas compuestas, RLS y comentarios que ese constructor no expresa. La migración de Laravel
(`2026_09_24_000000_apply_sql_schema`) aplica los archivos que falten y los anota en `schema_migrations`, **la misma tabla
que usaba la aplicación anterior**: sobre una base que ya estaba migrada no repite nada. Laravel corre esa migración una sola
vez, así que los archivos SQL posteriores los aplica `App\Support\SqlSchema` al final de cada `php artisan migrate`.

Tablas de contenido (todas con `position` para ordenar y restricciones que repiten las reglas de los formularios):
`profile` (una fila), `now_items`, `technologies` (catálogo), `tool_groups` + `tool_group_items`, `experiences`, `projects`
+ `project_technologies` + `project_highlights`, `courses`, `workshops`, `education`, `certification_groups` +
`certifications` y `contact_links`. Además `admin_users`, `web_sessions` y las del contador (`page_views`, `daily_salts`,
`daily_visitors`).

- Las fechas de periodos tienen precisión de mes; `end_date` nulo significa "en curso".
- **Borrar**: entre entidades independientes es `on delete restrict` (el panel lo explica en lugar de fallar); solo los
  hijos que se editan dentro del formulario del padre (herramientas de un grupo, puntos de detalle) van en cascada.
- **Row Level Security** activa en toda tabla (Supabase expone `public` por su API de datos: sin RLS quedaría abierta a la
  clave pública). Una prueba falla si alguna tabla no la tiene.
- **Tipos coherentes**: los proyectos cuelgan de experiencias `work`; materias y talleres, de experiencias `teaching`
  (llave foránea compuesta con columnas constantes `experience_kind`).

## Agregar contenido

Todo se hace desde `/admin` y se publica al guardar:

- **Un proyecto, certificación, materia…** → el recurso correspondiente, "Agregar".
- **Una tecnología nueva** → "Tecnologías" (el catálogo), o desde el propio selector con el botón "+". Se elige de la lista en
  "Herramientas" y en cada proyecto: el nombre se escribe una sola vez y renombrarla la actualiza en todo el sitio.
- **Ocultar algo sin borrarlo** → apaga "Mostrar en el sitio" (proyectos y "Actualmente").
- **Otro empleo** → "Empleos y docencia" con tipo *Trabajo*; sus proyectos se enlazan a esa experiencia.
- **El orden**: los periodos se ordenan solos por fecha (lo más reciente primero); "Orden" solo desempata.
- Una sección sin contenido desaparece del sitio y de la navegación.

### Una entidad nueva

1. Crea el siguiente archivo `database/sql/00N_….sql` con la tabla y **activa RLS en ella** (la migración de Laravel
   `migrate` recoge los archivos nuevos solo: `App\Support\SqlSchema` corre al final de cada `php artisan migrate`).
2. Crea su modelo (`app/Models`, con `ForgetsSiteContent`) y su recurso de Filament (`app/Filament/Resources`).
3. Léela en `app/Services/SiteContent.php` y muéstrala en `resources/views/home.blade.php`.
4. Inclúyela en el formato JSON: `ContentParser`, `ContentExporter` e `ContentImporter` (y su lista `TABLES`). Sin esto una
   exportación perdería la entidad nueva; **una prueba de cobertura falla** si una tabla de contenido gana una columna que
   el formato no contempla.
5. Añade pruebas y corre `vendor/bin/phpunit`.

## Importar y exportar el contenido

En el panel, **Importar y exportar** (`/admin/importar-exportar`), o por línea de comandos (`content:export`, `content:import`):

- **Exportar** descarga todo el contenido en un JSON (`about-me-AAAA-MM-DD.json`): perfil, herramientas, empleos, proyectos,
  docencia, estudios y certificaciones. **No** incluye usuarios, contraseñas, sesiones ni visitas. Sirve de respaldo
  versionable (los proyectos gratuitos de Supabase se pausan tras una semana sin actividad) y para mover el sitio a otra base.
- **Importar** **reemplaza todo** el contenido por el del archivo. Lo valida completo antes de guardar (cada error dice dónde
  está, por ejemplo `experiences[0].projects[2].start_date`) y lo aplica en una sola transacción: si la validación o la base
  rechazan algo, no cambia nada. Exige marcar una casilla de confirmación. Descarga una exportación antes por si quieres
  volver atrás. Los usuarios, la contraseña y las estadísticas no se tocan.

El archivo lo lee tu **navegador** y se envía como texto: el panel no guarda archivos subidos (ver `docs/decisions/0001-…`).

**Tu contenido real no se sube al repositorio.** `database/seed/content.json` y `legacy/` están en `.gitignore`; el
repositorio trae solo `database/seed/content.example.json` (ficticio). Un despliegue desde el repositorio (por ejemplo en
Render) arranca con esos datos de ejemplo: entra a `/admin/importar-exportar` e importa tu archivo. **Guarda tu
exportación en un lugar privado: es tu respaldo.**

Reglas del formato (`app/Content/ContentParser.php`):

- Los nombres de campo son los de las columnas. El **orden de las listas es el orden** en el sitio (no hay `position`).
- Las fechas son `AAAA-MM-DD`; `end_date: null` significa "en curso".
- Las tecnologías van **por nombre** y se buscan o crean en el catálogo sin distinguir mayúsculas.
- Una experiencia `work` lleva `projects`; una `teaching` lleva `courses` y `workshops`. Los proyectos propios van en
  `personal_projects`.
- Un campo desconocido se rechaza (así un error de tipeo no se pierde en silencio). Lo opcional puede omitirse.
- `legacy_stack` (texto libre original de un proyecto) se acepta y se ignora.

## Contador de visualizaciones

El pie de la portada muestra el total de visualizaciones (se puede ocultar en Perfil) y el Escritorio del panel las cifras de
hoy, 7 y 30 días con un gráfico de 14 días. Está pensado para la privacidad:

- **`page_views`** guarda solo totales por día; no hay un registro por visita.
- **`daily_visitors`** guarda un hash (IP + navegador + una sal que rota cada día) únicamente para no contar dos veces a la
  misma persona el mismo día. Ambas tablas se purgan a los 2 días: el hash no permite seguir a nadie de un día a otro.
- Cuenta personas, no máquinas: excluye robots, `HEAD`, "No rastrear" (DNT), Global Privacy Control y **tus propias visitas**
  (al iniciar sesión el navegador recibe una cookie `notrack`, sin datos personales).
- El registro ocurre **después** de enviar la respuesta (`defer`): el visitante no espera a la base y un fallo del contador
  nunca rompe la portada.

## Variables de entorno

Ver `.env.example`, que las documenta todas. Las que más importan:

| Variable | Qué hace |
|---|---|
| `APP_KEY` | Cifra cookies y sesiones (`php artisan key:generate --show`). **Secreto.** |
| `DB_URL` o `DATABASE_URL` | Conexión a PostgreSQL. **Secreto** (lleva la contraseña). |
| `DATABASE_SSL_CA` | Certificado (PEM) de la CA de la base: cifra **y verifica** al servidor (`sslmode=verify-full`). |
| `RUN_MIGRATIONS` | Migra el esquema al arrancar el contenedor (`true` por omisión). |
| `SEED_ON_EMPTY` | Carga el contenido inicial si la base no tiene perfil. |
| `ADMIN_USERNAME`, `ADMIN_PASSWORD` | Administrador inicial (mínimo 12 caracteres). |
| `SESSION_SECURE_COOKIE` | Cookies solo por HTTPS y HSTS. Actívalo siempre detrás de HTTPS. |
| `TRUST_PROXY` | Proxies de confianza para leer la IP real: número de saltos, lista de IP/CIDR o vacío. **No uses `true`.** |
| `LOGIN_MAX_ATTEMPTS` | Intentos de login por IP cada 15 minutos (10 por omisión). |
| `STATS_TIMEZONE` | Define cuándo empieza un "día" en el contador (`America/Hermosillo`). |

Con `config:cache` (lo hace el arranque del contenedor) `env()` solo se lee en los archivos de `config/`: por eso las variables
nuevas se declaran ahí (`config/security.php`) y no se leen con `env()` en el código.

## Pruebas, integración continua y versiones

```bash
TEST_DATABASE_URL=postgres://usuario:clave@localhost:5432/una_base_desechable vendor/bin/phpunit
```

- Las pruebas de integración corren contra **PostgreSQL real**, nunca SQLite (el esquema usa restricciones y RLS que
  SQLite no entiende). **Borran y recrean el esquema `public`** de esa base: no la apuntes a una real.
- Las pruebas no dependen de tu `.env` (llevan su propia clave y vacían `DATABASE_URL`, la CA y las credenciales).
- `tests/Feature/FidelityTest.php` compara el sitio con el HTML original (`legacy/`) usando tu contenido real; solo corre en
  tu máquina y se omite donde esos archivos faltan.
- `.github/workflows/ci.yml` corre las pruebas contra PostgreSQL 17, `composer audit`, valida `render.yaml` y construye la
  imagen con `docker compose` y le pasa `scripts/smoke.sh`. **Nunca se ha ejecutado en GitHub.**
- **Publicación de la imagen (opcional).** El trabajo `publish` sube la imagen a Docker Hub (`<usuario>/about-me`, etiquetas
  `latest` y el commit corto) solo en un `push` a `master` y solo si `phpunit` y `docker` pasaron. Necesita dos secretos del
  repositorio (Settings → Secrets and variables → Actions): `DOCKERHUB_USERNAME` y `DOCKERHUB_TOKEN` (token de acceso con
  permiso Read & Write, no tu contraseña). Sin ellos solo falla ese trabajo. Tampoco se ha ejecutado nunca.
- Dependabot (`.github/dependabot.yml`) propone actualizaciones semanales; los cambios de versión **mayor** de Laravel, Filament
  y PHPUnit están excluidos a propósito y se deciden a mano. Nunca propone una versión mayor de PostgreSQL (exige exportar e
  importar los datos).

## Desplegar en Render con la base en Supabase

`render.yaml` crea un servicio web (construido con el `Dockerfile`); **no** crea base de datos: esa vive en tu proyecto de
Supabase. Está validado contra el [esquema oficial de Render](https://render.com/schema/render.yaml.json) con
`scripts/validate-render.py`, que también corre en la integración continua.

> **Lo que está verificado y lo que no.** Verifiqué en local: la conexión cifrada con `libpq` (el cliente real de PHP)
> contra un servidor con CA propia, la secuencia de arranque contra una base vacía con las cachés de producción, y el
> formato del Blueprint contra el esquema oficial. **No he podido probar nada contra Render, Supabase ni Docker reales**: los
> detalles de cada plataforma vienen de su documentación al 2026-09-24 y pueden cambiar. Revisa lo que te indican antes de
> confiar en cualquier cifra.

**1. Usa un proyecto de Supabase dedicado a este sitio.** El esquema crea tablas con nombres generales (`profile`, `courses`,
`education`…) en `public`. Si el proyecto ya tiene tablas con esos nombres, la migración falla (sin dejar nada a medias).

**2. Qué URL de conexión copiar.** Supabase ofrece tres:

| Conexión | Puerto | Sirve aquí |
|---|---|---|
| Directa (`db.<ref>.supabase.co`) | 5432 | No: es solo IPv6 (con IPv4 solo por un complemento de pago) y no puedo confirmar que Render salga por IPv6 |
| **Session pooler** (`aws-…pooler.supabase.com`, usuario `postgres.<ref>`) | **5432** | **Sí.** IPv4 en todos los planes y conserva el estado de la sesión |
| Transaction pooler (`…pooler.supabase.com`) | 6543 | No: según su documentación no admite sentencias preparadas, que PDO usa |

Si tu contraseña tiene caracteres especiales (`@ : / # %`), deben ir codificados en la URL (`@` → `%40`).

**3. El certificado (CA).** Supabase firma con **su propia CA**, que no viene en los almacenes del sistema. Lo reproduje con
`libpq` contra un servidor de pruebas con CA propia:

| Configuración | Resultado |
|---|---|
| `sslmode=require` sin la CA | Conecta y **cifra, pero no verifica** al servidor |
| Exigir verificación (`verify-full`) sin la CA | **Falla**: no hay certificado raíz |
| CA en `DATABASE_SSL_CA` | Conecta, cifrado **y verificando** al servidor (`verify-full`) |
| Una CA que **no** firmó el servidor | **Rechaza**: `certificate verify failed` |

Descarga el certificado en los ajustes de base de datos de tu proyecto (sección SSL) y pega su contenido **completo** en
`DATABASE_SSL_CA` (es un certificado público, no un secreto; se acepta en varias líneas o en una con `\n`). Con la CA, la
aplicación quita `sslmode` de la URL a propósito (si no, podría pisar la verificación) y usa `verify-full`. Si la conexión falla
solo por el **nombre** del servidor (no lo pude comprobar contra Supabase), `DB_SSLMODE=verify-ca` verifica la cadena sin el
nombre.

**4. Riesgos propios de Supabase que conviene conocer**

- **Los proyectos gratuitos se pausan tras una semana de inactividad** (según su documentación). Con un portafolio de pocas
  visitas, el sitio podría quedarse sin base de datos hasta que la restaures desde su panel. El plan de pago lo evita. Una
  exportación reciente (`/admin/importar-exportar`) te deja reconstruir el contenido en otra base.
- **La API de datos de Supabase expone el esquema `public`.** En proyectos existentes las tablas nuevas nacen con permisos
  para el rol público `anon`, cuya clave no es secreta. La migración `006` activa RLS en todas las tablas de la aplicación y
  retira esos permisos; las migraciones de Laravel hacen lo mismo con `migrations`, `schema_migrations` y `web_sessions`.
- **La aplicación debe conectarse como dueña de las tablas** (en Supabase, el rol `postgres` de la URL del pooler), que no
  está sujeto a RLS. No uses la clave `service_role` ni un rol sin propiedad.

**5. Pasos**

1. Genera una clave de aplicación en tu equipo: `php artisan key:generate --show` (empieza con `base64:`).
2. Sube el repositorio a GitHub y conecta tu cuenta en Render.
3. En Render: **New → Blueprint**, elige el repositorio y la rama.
4. Render pide cinco secretos: `APP_KEY`, `DATABASE_URL` (el session pooler), `DATABASE_SSL_CA` (el certificado),
   `ADMIN_USERNAME` y `ADMIN_PASSWORD` (mínimo 12 caracteres). No se guardan en el repositorio.
5. Revisa el plan del servicio y su costo **antes de confirmar**, y pulsa **Apply**.
6. En el primer arranque el contenedor migra el esquema, carga el contenido **de ejemplo** (tu `content.json` no está en el
   repositorio) y crea el administrador. Entra a `https://<tu-servicio>.onrender.com/admin`, abre **Importar y exportar** e
   importa tu JSON.

**Costo y límites del servicio web (Render, 2026-09-24).** El plan gratuito se duerme tras 15 minutos sin visitas y tarda
cerca de un minuto en despertar: para un portafolio que verá un reclutador, la primera visita sería una espera de un minuto.
Por eso el Blueprint usa `starter` (de pago). Para probar sin gastar, cambia a `plan: free`.

**Verifica `TRUST_PROXY` tras el primer despliegue (importa para la seguridad).**

Render pone HTTPS y uno o más proxies delante de tu app; la IP real del visitante llega en `X-Forwarded-For`. De esa IP
dependen el límite de intentos de login y el contador de visitas únicas, y **no debes usar `TRUST_PROXY=true`**: con `true` la
app toma la IP más a la izquierda de la cabecera, que el propio visitante puede escribir, y así evadir el límite (una prueba
lo demuestra). Un número fija cuántos proxies de confianza cuentas desde la app hacia afuera. No sé cuántos hay en Render:

1. Con `TRUST_PROXY=1` (el valor inicial del Blueprint), envía una petición con una IP falsa:
   `curl -s -o /dev/null -H "X-Forwarded-For: 203.0.113.9" https://<tu-servicio>.onrender.com/healthz`
2. Averigua qué IP usó la app para esa petición (los registros de Caddy en el panel de Render → **Logs** muestran la IP del
   cliente que ve el servidor): si es `203.0.113.9`, confías de más (**baja** el número); si es una dirección de Render o de
   Cloudflare, confías de menos (**sube** el número en 1); si es tu IP pública real sin haber podido elegirla, es correcto.
3. Cambia `TRUST_PROXY` en el panel (Environment) y repite hasta acertar.

Confiar de menos es seguro pero grueso (varios visitantes comparten IP); confiar de más es el error peligroso.

**Migraciones.** Se aplican al arrancar (`RUN_MIGRATIONS=true`, con bloqueo). Render mantiene la versión vieja sirviendo hasta
que la nueva pasa el chequeo de salud, así que una migración destructiva futura podría romper unos segundos a la versión vieja;
si algún día la hay, considera `preDeployCommand` de Render.

**Respaldos.** Verifica qué copias incluye tu plan de Supabase, y guarda exportaciones periódicas del contenido.

**Dominio propio.** Descomenta `domains` en `render.yaml` y crea el registro DNS que Render indique.

## Producción: lo que conviene saber

- **HTTPS.** Render lo termina; con otro servidor pon un proxy con certificado y usa `SESSION_SECURE_COOKIE=true` y un
  `TRUST_PROXY` correcto. Sin HTTPS las credenciales del panel viajarían en claro.
- **Varias instancias.** Funciona (las sesiones están en la base), pero la caché de contenido es por servidor
  (`CACHE_STORE=file`): tras editar, otra instancia tarda hasta 60 s en verlo.
- **Seguridad incluida.** CSP estricta en el sitio público (sin scripts ni estilos en línea; el panel necesita una más
  relajada por Livewire/Alpine), cabeceras de endurecimiento, HSTS con cookies seguras, CSRF en todos los formularios, sesiones
  con cookie `HttpOnly` + `SameSite`, límite de intentos de login por IP, enlaces restringidos a `https`/`http`/`mailto`/`tel`,
  contraseñas con bcrypt, y consultas siempre parametrizadas. Filament y Laravel los mantiene su comunidad: actualiza.
- **Restringe `/admin` si puedes.** Con tu propio proxy puedes limitar la ruta a una red privada o a una lista de IP. **En
  Render no hay reglas por ruta:** su lista de IPs (`ipAllowList`) restringe TODO el servicio, así que no sirve para un sitio
  público. Alternativas: un servicio aparte para el panel, o Cloudflare Access con dominio propio.
- **Contenido personal.** El teléfono y el correo no están en el sitio a propósito (el contacto es por LinkedIn). Si los agregas
  como "Enlaces de contacto", serán públicos.

## Si vienes de la versión Node

- El código de la aplicación anterior está en la etiqueta `node-legacy`. La base de datos es **la misma**: el esquema no cambió.
- **La contraseña del administrador hay que recrearla.** La versión Node guardaba `scrypt`, que PHP no puede verificar sin una
  implementación muy lenta. Con `ADMIN_USERNAME` y `ADMIN_PASSWORD` definidos, `admin:ensure` reemplaza **una sola vez** un
  hash heredado; si ya cambiaste la contraseña desde el panel nuevo, no la pisa.
- Las sesiones de la app anterior (tabla `sessions`) no se reutilizan: Laravel usa `web_sessions`. La tabla vieja queda sin
  uso y puedes borrarla a mano cuando quieras.
- Tu `.env` de la app anterior trae `DATABASE_URL`, `ADMIN_*`, etc.: sirven tal cual, pero **falta `APP_KEY`**
  (`php artisan key:generate`) y `COOKIE_SECURE` ahora se llama `SESSION_SECURE_COOKIE`.

## Estado conocido (verifica antes de afirmar lo contrario)

- **Pruebas:** 186 pasan contra PostgreSQL 17.10 real con PHP 8.4.26 (185 en la integración continua: la de fidelidad con el
  HTML original corre solo si existen `legacy/` y `database/seed/content.json`).
- **Verificado en un navegador real (Edge):** el sitio, el login del panel, la edición con filas repetibles, el cambio del
  sitio al instante, importar/exportar (descarga y lectura del archivo), el contador y el gráfico, sin errores de consola.
- **Verificado en local:** la conexión cifrada y verificada con `libpq`, la secuencia de arranque contra una base vacía con las
  cachés de producción y `scripts/smoke.sh` contra ella (con el servidor de PHP, no con FrankenPHP).
- **Nunca ejecutado:** `Dockerfile` y `docker/Caddyfile` (Docker estaba apagado), `docker-compose.yml`,
  `.github/workflows/ci.yml`, Render y Supabase reales.
