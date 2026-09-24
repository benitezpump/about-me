# about-me

Sitio personal cuyo contenido vive en **PostgreSQL** y se edita desde un panel en `/admin`. No hay texto del
sitio escrito a mano en el código: perfil, proyectos, certificaciones, herramientas, materias y demás salen de
la base de datos, y se publican al guardar.

> Trabajas con un agente de IA en este repositorio: lee [`AGENTS.md`](AGENTS.md) (reglas y trampas del proyecto)
> y, si usas Claude Code, [`CLAUDE.md`](CLAUDE.md).

## Qué incluye

- **Sitio público** en una sola página: portada con "Actualmente", herramientas, línea de tiempo de trabajo,
  docencia, proyectos propios, formación y contacto. Tema oscuro por defecto, con tema claro.
- **Panel `/admin`** para editar todo el contenido, cambiar la contraseña y ver las visitas.
- **Contador de visualizaciones** que cuenta personas (no robots) sin guardar IPs.
- **Seguridad**: CSP estricta, CSRF, sesiones `HttpOnly`, límite de intentos de login y contraseñas con scrypt.

## Stack

| Pieza | Elección | Por qué |
|---|---|---|
| Runtime | Node.js 22 + TypeScript | Los errores de esquema se ven al compilar. |
| Servidor | Fastify 5 | Rápido, con plugins de seguridad mantenidos (helmet, rate-limit, cookie). |
| Vistas | Nunjucks en el servidor | Es un sitio de contenido: HTML completo, buen SEO, cero JavaScript de framework. Autoescape activo. |
| Base de datos | PostgreSQL 17 con `pg` | SQL explícito y migraciones en archivos `.sql`. Sin ORM. |
| Contraseñas | scrypt (`node:crypto`) | Sin dependencias nativas que compilar. |
| Empaquetado | Docker Compose | La app y la base arrancan juntas con un comando. |

Requisitos: Node.js 22 o superior (el proyecto fija la LTS 22 en `.nvmrc`: `nvm use`; es la que corre en producción y en la
integración continua). Docker solo si quieres el arranque con contenedores.

## Arranque con Docker

```bash
cp .env.example .env        # y elige UNA opción de base de datos (abajo)
docker compose up -d --build
```

Abre <http://localhost:3000> (sitio) y <http://localhost:3000/admin> (panel). En el primer arranque la app migra
el esquema, carga el contenido inicial (`db/seed/content.json` si existe; si no, el de ejemplo del repositorio) y crea el
administrador.

**Opción A: ya tienes una base PostgreSQL** (servidor propio, Neon, Supabase, Railway, RDS…). Define en `.env`
tu `DATABASE_URL` y no actives el perfil `local-db`; Compose no levantará ningún contenedor de base de datos.

```bash
DATABASE_URL=postgres://usuario:clave@host:5432/nombre_de_la_base?sslmode=require
```

- Los servicios en la nube casi siempre exigen SSL: añade `?sslmode=require`.
- Si tu plan limita las conexiones, baja `DB_POOL_MAX` (3–5).
- **Ojo con los poolers.** En modo *transaction* (p. ej. el puerto 6543 de Supabase) los bloqueos de sesión que usan las
  migraciones no son fiables. Usa la conexión directa o un pooler en modo *sesión*. Con Supabase, la conexión directa es
  solo IPv6: mira "Desplegar en Render con la base en Supabase".
- **Cifrado.** Sin `sslmode` en la URL la conexión va SIN cifrar. Si el servidor usa su propia CA (Supabase), pasa la CA en
  `DATABASE_SSL_CA`; con `sslmode=require` y sin la CA, esta librería falla al verificar el certificado.
- La base debe existir y el usuario necesita permiso para crear tablas en ella.

**Opción B: que Compose levante una PostgreSQL local.** En `.env` descomenta `COMPOSE_PROFILES=local-db` y
`POSTGRES_PASSWORD` (solo letras y números: va dentro de una URL).

> El `Dockerfile` y `docker-compose.yml` están validados (`docker compose config`) pero todavía no se han
> ejecutado de punta a punta. Antes de desplegar, corre `docker compose up --build` una vez.

## Desarrollo sin Docker

**Con tu propia base PostgreSQL** (la forma más simple si ya tienes una):

```bash
npm install
# en .env: DATABASE_URL, ADMIN_USERNAME, ADMIN_PASSWORD y SEED_ON_EMPTY=true
npm run dev                 # http://127.0.0.1:3000
```

Con `SEED_ON_EMPTY=true` el primer arranque carga el contenido inicial; sin ella, con la base vacía el sitio
falla con "No hay perfil". No pongas `NODE_ENV=production` si pruebas por `http://`: activa las cookies `Secure`
y el login no funciona.

**Sin ninguna base instalada** (PostgreSQL de desarrollo con PGlite):

```bash
npm install
cp .env.example .env        # y descomenta la sección "Desarrollo local"
npm run dev:db              # PostgreSQL en el puerto 5433 — déjalo abierto
npm run dev                 # en otra terminal: http://127.0.0.1:3000
```

`dev:db` usa PGlite (el motor de PostgreSQL compilado a WASM) y guarda sus datos en `.pglite/`. Es solo para
desarrollo: producción usa PostgreSQL real. PGlite comparte un solo backend, por eso el `.env` de desarrollo
lleva `DB_POOL_MAX=1`. Además, tras un error de SQL fuera de una transacción corta esa conexión y la petición
siguiente puede fallar una vez con `ECONNRESET` (observado en las pruebas; con PostgreSQL real no se ha podido
comprobar, pero no es de esperar).

## Comandos

| Comando | Qué hace |
|---|---|
| `npm run dev` | Servidor con recarga al guardar. Lee `.env`. |
| `npm run dev:db` | PostgreSQL de desarrollo (PGlite) en el puerto 5433. |
| `npm run build` / `npm start` | Compila a `dist/` y lo ejecuta (`start` no lee `.env`: usa `node --env-file=.env dist/server.js`). |
| `npm test` | 145 pruebas con PGlite, o contra un PostgreSQL real si defines `TEST_DATABASE_URL` (ver "Integración continua"): unidades + integración (alarma de alcance del panel, seguridad, integridad de la base, ruta de actualización de una base con datos antiguos, catálogo de tecnologías, importar y exportar y contador de visitas; más una comparación del sitio con el HTML original que solo corre en tu máquina, con tus archivos locales). |
| `npm run typecheck` | Comprueba tipos sin generar archivos. |
| `npm run migrate` | Aplica migraciones pendientes y muestra sus avisos (la app también lo hace al arrancar si `RUN_MIGRATIONS=true`). |
| `npm run seed` / `seed:force` | Carga el contenido inicial (`db/seed/content.json` si existe; si no, `content.example.json`) si la base está vacía / **reemplaza** todo el contenido editable. |
| `npm run catalog:import` | Convierte al catálogo de tecnologías los proyectos cuyo texto libre sigue siendo el original. Haz un respaldo antes (ver "Catálogo de tecnologías"). |
| `npm run admin:create` | Crea el administrador. Con `-- --reset` cambia su contraseña y cierra todas las sesiones. |

## Cómo se organiza

```
db/migrations/       Esquema en SQL, numerado (001_init … 006_row_level_security).
                     Nunca edites uno ya aplicado: crea el siguiente.
db/seed/             content.example.json: contenido de EJEMPLO (ficticio) que sí se versiona. content.json: tu contenido
                     real, en .gitignore (no se sube). Mismo formato que exporta el panel.
src/
  server.ts          Arranque: migra, siembra si está vacía, crea el admin y escucha. Cierre ordenado.
  app.ts             Fastify: cabeceras de seguridad, CSP, cookies, límites y rutas.
  config.ts          Variables de entorno, validadas al arrancar.
  db.ts / migrate.ts Pool de `pg` (fechas como texto), transacciones y ejecutor de migraciones.
  content.ts         Lee la base y arma lo que ve la plantilla (orden y formato). Caché de 60 s.
  format.ts          Periodos ("02/2023 – actualidad"), iniciales del favicon, párrafos.
  views.ts           Entorno de Nunjucks y sus filtros.
  visits.ts          Contador de visualizaciones y su privacidad.
  auth.ts            scrypt, sesiones (solo el hash del token vive en la BD) y CSRF.
  content-data.ts    Formato JSON del contenido: tipos, validación (cada error dice dónde) y lectura del contenido inicial.
  content-io.ts      Reemplazar (en una transacción) y exportar el contenido. Lo usan la siembra y el panel.
  seed.ts            Carga `db/seed/content.json` cuando la base está vacía.
  catalog-import.ts  Convierte proyectos con texto libre de tecnologías al catálogo (npm run catalog:import).
  routes/public.ts   Portada, favicon generado y /healthz.
  admin/
    resources.ts     Descripción declarativa de todo lo editable.
    crud.ts          Validación y SQL genéricos a partir de esa descripción.
    routes.ts        Login, cuenta y pantallas del panel.
  cli/               migrate, seed y create-admin.
views/               Plantillas del sitio (home.njk) y del panel (admin/).
public/              CSS y JavaScript estáticos (sin nada inline: la CSP no lo permite).
scripts/dev-db.ts    La base de desarrollo con PGlite.
scripts/smoke.sh     Comprobaciones de humo por HTTP contra una instancia en marcha (las usa la integración continua).
tests/               Unidades e integración (support/database.ts elige PGlite o PostgreSQL real).
.github/             Integración continua (ci.yml) y actualizaciones automáticas (dependabot.yml).
docs/decisions/      Decisiones de arquitectura: por qué se conserva esta y cuándo cambiarla.
legacy/              (solo local, en .gitignore) El sitio estático original; una prueba local lo usa como referencia.
```

## Base de datos

```mermaid
erDiagram
  experiences ||--o{ projects : "solo work"
  experiences ||--o{ courses : "solo teaching"
  experiences ||--o{ workshops : "solo teaching"
  projects ||--o{ project_highlights : "cascada"
  projects ||--o{ project_technologies : "cascada"
  technologies ||--o{ project_technologies : "restrict"
  tool_groups ||--o{ tool_group_items : "cascada"
  technologies ||--o{ tool_group_items : "restrict"
  certification_groups ||--o{ certifications : "restrict"
  admin_users ||--o{ sessions : "cascada"
```

Sin relaciones: `profile` (una sola fila), `now_items`, `education`, `contact_links` y las tres del contador
(`page_views`, `daily_visitors`, `daily_salts`).

**La base se defiende sola.** Las reglas no viven solo en el formulario; la base las exige aunque otra vía de
escritura (un script, una consola SQL, un fallo futuro) intente saltarlas:

| Regla | Cómo se garantiza |
|---|---|
| Un periodo no termina antes de empezar | `check (end_date is null or end_date >= start_date)` en experiencias, proyectos y materias |
| Los textos obligatorios no están vacíos ni en blanco | `check (btrim(x) <> '')` |
| Los enlaces son `https`, `http`, `mailto` o `tel`, sin espacios | `check (url ~* '^(https?://\|mailto:\|tel:)…')`. Es defensa en profundidad: las plantillas ponen los enlaces en un `href` y el escapado de HTML no neutraliza `javascript:` |
| Un proyecto solo cuelga de una experiencia de *trabajo*; una materia o taller, de una de *docencia* | Llave foránea compuesta `(experiencia, tipo)` con una columna constante (`experience_kind`). Además impide cambiar el tipo de una experiencia que ya tiene elementos |
| Borrar por error no arrastra datos | `on delete restrict` entre experiencia → proyectos/materias/talleres y grupo → certificaciones. Lo que se edita como hijo en el mismo formulario (herramientas, puntos de detalle) sí cae en cascada |
| Las cifras del contador tienen sentido | `check (visitors <= views)` y no negativas |

En el panel, cuando la base rechaza un borrado o un cambio, se muestra un mensaje claro en lugar de un error; y los
selectores de experiencia solo ofrecen las del tipo correcto.

**Rendimiento.** Con decenas de filas no hay nada que afinar y no se afinó: lo relevante es la integridad. Las
llaves foráneas tienen índice para que los borrados y las consultas por padre no dependan del tamaño, y el sitio
lee la base como mucho una vez por minuto (caché con una sola carga a la vez y sin datos obsoletos tras editar).

**Migraciones sobre una base con datos.** Las restricciones nuevas se crean `NOT VALID` (ya rigen para toda escritura
nueva) y luego se intentan validar contra lo existente. Si un dato antiguo no las cumple, la migración **no falla**
(tumbaría el sitio): deja solo esa restricción sin validar y muestra un `WARNING` con el comando exacto para validarla
cuando corrijas el dato, por ejemplo `alter table projects validate constraint projects_dates_check;`. Está
probado con una base "vieja" con datos incorrectos (`tests/migration.test.ts`).

## Agregar contenido

- **Un proyecto, certificación, materia…** → `/admin`, botón "Agregar". Se publica al guardar.
- **Una tecnología nueva** → "Tecnologías" (el catálogo). Luego se elige de la lista en "Herramientas" y en cada proyecto.
- **Ocultar algo sin borrarlo** → desmarca "Mostrar en el sitio" (proyectos y "Actualmente").
- **Otro empleo** → "Empleos y docencia" → agregar con tipo *Trabajo*; sus proyectos se enlazan a esa experiencia
  y el sitio muestra su propia línea de tiempo.
- **Un enlace más (GitHub, correo)** → "Enlaces de contacto". Acepta `https://`, `mailto:` y `tel:`.
- **El orden**: los periodos se ordenan solos por fecha (lo más reciente primero). El campo *Orden* solo desempata.
- Una sección sin contenido desaparece del sitio y de la barra de navegación.

### Una entidad nueva (por ejemplo, "Publicaciones")

1. Crea la siguiente migración (`db/migrations/007_publications.sql`) con la tabla. Activa Row Level Security en ella.
2. Descríbela en `src/admin/resources.ts` (campos, columnas de la lista). El panel obtiene lista, alta, edición y borrado.
3. Léela en `src/content.ts` y muéstrala en `views/home.njk`.
4. Inclúyela en el formato JSON: `src/content-data.ts` (tipo y validación) y `src/content-io.ts` (insertar, exportar y la
   lista de tablas que se vacían al reemplazar). Sin esto, una exportación perdería la entidad nueva. Una prueba de
   cobertura de `tests/app.test.ts` falla si una tabla de contenido gana una columna que el formato no contempla.
5. Añade pruebas en `tests/` y corre `npm test`.

## Importar y exportar el contenido

En el panel, **Importar y exportar** (`/admin/data`):

- **Exportar** descarga todo el contenido en un archivo JSON (`about-me-AAAA-MM-DD.json`): perfil, herramientas,
  empleos, proyectos, docencia, estudios y certificaciones. **No** incluye usuarios, contraseñas, sesiones ni visitas.
  Sirve de respaldo versionable (los proyectos gratuitos de Supabase se pausan tras una semana sin actividad) y para
  mover el sitio a otra base.
- **Importar** **reemplaza todo** el contenido por el del archivo. Primero lo valida completo (cada error dice dónde
  está, por ejemplo `experiences[0].projects[2].start_date`) y luego lo aplica en una sola transacción: si la
  validación o la base rechazan algo, no cambia nada. Exige marcar una casilla de confirmación. Descarga una
  exportación antes por si quieres volver atrás. Los usuarios, la contraseña y las estadísticas no se tocan.

El mismo formato es el del contenido inicial: se edita como texto, sin recompilar. **Tu contenido real no se sube al
repositorio**: `db/seed/content.json` y `legacy/` están en `.gitignore`, y el repositorio trae solo
`db/seed/content.example.json` (ficticio). Un despliegue desde el repositorio (por ejemplo en Render) arranca con esos
datos de ejemplo; entra a `/admin/data` e importa tu archivo para reemplazarlos. Guarda tu exportación en un lugar
privado: es tu respaldo. Reglas:

- Los nombres de campo son los de las columnas. El **orden de las listas es el orden** en el sitio (no hay `position`).
- Las fechas son `AAAA-MM-DD`; `end_date: null` significa "en curso".
- Las tecnologías van **por nombre** y se buscan o crean en el catálogo sin distinguir mayúsculas.
- Una experiencia `work` lleva `projects`; una `teaching` lleva `courses` y `workshops`. Los proyectos propios van en
  `personal_projects`.
- Un campo desconocido se rechaza (así un error de tipeo no se pierde en silencio). Lo opcional puede omitirse.
- `legacy_stack` solo lo lee `npm run catalog:import` (reconoce proyectos sin editar); la exportación no lo incluye.

El archivo lo lee tu navegador y se envía como texto: el panel no guarda archivos subidos (ver
`docs/decisions/0001-…`). El límite del cuerpo de la petición es de 512 KB, unas veinte veces el contenido actual.

## Catálogo de tecnologías

Las tecnologías ya no son texto libre: viven en un **catálogo** (`technologies`) y se eligen de una lista en dos
sitios, la sección Herramientas (`tool_group_items`) y cada proyecto (`project_technologies`, con una nota
opcional que se muestra entre paréntesis, como "Browsershot (generación de PDF)").

- **Se escribe una sola vez.** No hay "NodeJS" y "node js" por descuido; el nombre es único sin distinguir
  mayúsculas ni espacios. Renombrar una tecnología en el catálogo la actualiza en todo el sitio.
- **El catálogo dice cuánto se usa cada una** (columnas "En proyectos" y "En herramientas"), lo que también deja
  ver incoherencias entre las dos listas.
- **No se puede borrar una tecnología en uso**; el panel explica dónde está. Repetir la misma en un proyecto o en un
  grupo se rechaza con un mensaje claro.
- **Qué se pierde al pasar de texto a lista.** Una lista no puede reproducir prosa como "PHP con Symfony2" o
  "PostgreSQL en backend; Vue.js en frontend". Al convertir, esos matices se simplifican (la nota opcional sirve
  para conservar los que importen, como "React Native (app móvil)").

**Actualizar una base que ya tiene contenido.** La migración 005 convierte `Herramientas` sin pérdida (mismos nombres,
mismo orden, sin duplicados) y elimina la tabla vieja. Los **proyectos no se tocan**: mientras un proyecto no tenga
tecnologías del catálogo, el sitio sigue mostrando su texto libre de siempre. Para convertirlos:

1. Haz un respaldo (`pg_dump`).
2. Ejecuta `npm run catalog:import`. Convierte solo los proyectos cuyo texto es **exactamente el original**, y deja
   intactos (avisando por qué) los que editaste a mano, para que elijas sus tecnologías en el panel. No interpreta
   prosa: sería adivinar. Se puede repetir sin efectos y conserva el texto libre como respaldo.

## Contador de visualizaciones

El pie del sitio muestra cuántas veces se ha visto la portada, y el Panel trae el detalle de hoy, 7 días, 30 días
y una gráfica de los últimos 14. Se apaga o enciende en Perfil → "Mostrar el contador de visualizaciones".

**Qué cuenta.** Personas, no máquinas. No cuenta robots ni vistas previas de enlaces (Google, LinkedIn,
WhatsApp…), peticiones `HEAD`, a quien envía "No rastrear" o Global Privacy Control, ni las visitas del
administrador: al iniciar sesión el navegador queda marcado con una cookie `notrack` (sin identificador). Cada
carga cuenta como una visualización; quien recarga suma visualizaciones pero no visitantes.

**Privacidad.** No usa cookies de seguimiento ni guarda IPs. Para no contar dos veces al mismo visitante en un
día se guarda un hash (IP + navegador + una sal aleatoria que cambia cada día) en `daily_visitors`; ese hash y la
sal se borran a los 2 días, así que no permite reconocer a nadie de un día a otro. De largo plazo solo quedan los
totales por día en `page_views`. Aun así, si el sitio tiene visitantes en la UE o quieres cumplir a rajatabla la
LFPDPPP, conviene mencionarlo en un aviso de privacidad: es un hash derivado de la IP, es decir, un dato
pseudónimo.

**Límites.** Es un indicador, no una analítica de referencia: el cliente declara su navegador, así que un script
que finja ser un navegador sí cuenta (lo frena el límite general de 300 peticiones por minuto por IP). El "día"
empieza según `STATS_TIMEZONE`. El total se lee con una caché de 30 s por proceso.

## Variables de entorno

| Variable | Por defecto | Descripción |
|---|---|---|
| `DATABASE_URL` | — (obligatoria) | Conexión a PostgreSQL. |
| `PORT` / `HOST` | `3000` / `127.0.0.1` (`0.0.0.0` en producción) | Dónde escucha. |
| `DB_POOL_MAX` | `10` | Conexiones máximas (usa `1` con PGlite). |
| `RUN_MIGRATIONS` | `true` | Migra al arrancar. |
| `SEED_ON_EMPTY` | `false` (`true` en Compose) | Carga el contenido inicial si la base está vacía. |
| `ADMIN_USERNAME` / `ADMIN_PASSWORD` | — | Crean el administrador inicial (mín. 12 caracteres). |
| `COOKIE_SECURE` | `true` en producción | Marca las cookies como `Secure`. Necesita HTTPS. |
| `DATABASE_SSL_CA` | — | Certificado (PEM) de la CA de la base: cifra **y verifica** al servidor. Hace falta con Supabase (ver "Desplegar en Render con la base en Supabase"). |
| `TRUST_PROXY` | `false` | Cuántos proxies de confianza hay delante: `false`, un número (`1`, `2`…) o una lista de IP/CIDR. Evita `true` (ver "Desplegar en Render"). |
| `STATS_TIMEZONE` | `America/Hermosillo` | Zona horaria que decide cuándo empieza un "día" en el contador. |
| `LOGIN_MAX_ATTEMPTS` | `10` | Intentos de login por IP cada 15 minutos antes de responder 429. |

Solo para Docker Compose: `APP_PORT`, `POSTGRES_PASSWORD` y `COMPOSE_PROFILES=local-db` (ver `.env.example`).

## Integración continua, versiones y pruebas contra PostgreSQL real

**Pruebas contra un PostgreSQL de verdad.** Por defecto `npm test` usa PGlite (rápido, sin instalar nada), pero PGlite no
es PostgreSQL y se comporta distinto en detalles: un borrado bloqueado por `on delete restrict` responde `23503` en
PostgreSQL y `23001` en PGlite. Para probar contra el motor real apunta `TEST_DATABASE_URL` a un **servidor**:

```bash
TEST_DATABASE_URL=postgres://postgres:postgres@localhost:5432/postgres npm test
```

Cada archivo de pruebas crea su propia base temporal (`about_me_test_…`) y la elimina al terminar; nunca lee, escribe ni
borra otra base del servidor. Aun así, apúntala a un servidor de pruebas, no a uno con datos que te importen.

**Integración continua** (`.github/workflows/ci.yml`, en cada `push` y solicitud de cambio):

| Trabajo | Qué verifica |
|---|---|
| `postgres` | Tipos y toda la suite contra PostgreSQL 17 real, con Node 22 |
| `pglite` | La suite en el modo que se usa en local (que no se desvíe del anterior) y la compilación |
| `audit` | `npm audit` de lo que va a producción, solo avisos graves |
| `docker` | Construye la imagen, levanta `docker compose` y ejecuta `scripts/smoke.sh`, incluido un inicio de sesión real |

`bash scripts/smoke.sh http://127.0.0.1:3000` sirve también en local contra cualquier instancia en marcha; con
`SMOKE_ADMIN_USER` y `SMOKE_ADMIN_PASSWORD` prueba además el inicio de sesión.

> El flujo está definido y validado como YAML, y el script de humo se probó contra el servidor compilado con PostgreSQL
> 17.10, pero **el flujo todavía no se ha ejecutado en GitHub**. Su primera ejecución es la que lo valida, en especial
> el trabajo `docker`.

**Versiones fijadas.** Node **22** (`.nvmrc`), TypeScript **~5.9** y los tipos de Node **^22** (que coinciden con
producción). Dependabot (`.github/dependabot.yml`) propone cada semana las actualizaciones de npm, Docker, Compose y
GitHub Actions, y la integración continua las prueba. Tres reglas son decisiones, no descuidos: no salta solo a
TypeScript 7 ni a otro Node mayor, y **nunca** propone una versión mayor de PostgreSQL (exige exportar e importar los datos).

**Alcance del panel.** El panel es código propio de seguridad y CRUD, y se mantiene pequeño a propósito: un administrador y
campos simples. Si hace falta subir archivos, texto enriquecido, varios usuarios, varios idiomas, borradores o 2FA
(dos o más), lo acordado es migrar a un framework con panel incluido en lugar de construirlo a mano. La decisión, la
evidencia y las alternativas están en [`docs/decisions/0001-arquitectura-y-alcance-del-panel.md`](docs/decisions/0001-arquitectura-y-alcance-del-panel.md),
y `tests/scope.test.ts` avisa si algo empuja el alcance.

## Desplegar en Render con la base en Supabase

`render.yaml` crea un servicio web (construido con el `Dockerfile`); **no** crea base de datos: esa vive en tu proyecto de
Supabase. Está validado contra el [esquema oficial de Render](https://render.com/schema/render.yaml.json) con
`scripts/validate-render.py`, que también corre en la integración continua.

> **Lo que está verificado y lo que no.** Verifiqué en un servidor de pruebas: la conexión cifrada con una CA propia, la
> seguridad por filas con roles como los de Supabase, y el formato del Blueprint contra el esquema oficial. **No he
> podido probar nada contra Render ni contra Supabase reales**: los detalles de cada plataforma vienen de su
> documentación al 2026-09-24 y pueden cambiar. Revisa lo que te indican antes de confiar en cualquier cifra.

**1. Usa un proyecto de Supabase dedicado a este sitio.** Las migraciones crean tablas con nombres generales
(`profile`, `sessions`, `courses`, `education`…) en el esquema `public`. Si el proyecto ya tiene tablas con esos nombres, la
migración falla (sin dejar nada a medias). Y la seguridad por filas de la migración 006 solo afecta a las tablas de esta
aplicación, no a las ajenas.

**2. Qué URL de conexión copiar.** Supabase ofrece tres:

| Conexión | Puerto | Sirve aquí |
|---|---|---|
| Directa (`db.<ref>.supabase.co`) | 5432 | No: es solo IPv6 (con IPv4 solo por un complemento de pago) y no puedo confirmar que Render salga por IPv6 |
| **Session pooler** (`aws-…pooler.supabase.com`, usuario `postgres.<ref>`) | **5432** | **Sí.** IPv4 en todos los planes y conserva el estado de la sesión |
| Transaction pooler (`…pooler.supabase.com`) | 6543 | No: pierde los bloqueos de sesión (`pg_advisory_lock`) que usan las migraciones |

Si tu contraseña tiene caracteres especiales (`@ : / # %`), deben ir codificados en la URL (`@` → `%40`).

**3. El certificado (CA) — el fallo más probable del primer despliegue.** Supabase indica `sslmode=require`, pero con la
librería `pg` de este proyecto `require` *verifica* el certificado, y Supabase firma con **su propia CA**, que no viene en el
almacén de Node. Lo reproduje con un servidor de pruebas con CA propia:

| Configuración | Resultado |
|---|---|
| `sslmode=require` sin la CA | **Falla**: `unable to verify the first certificate` |
| CA en `DATABASE_SSL_CA` | Conecta, cifrado y verificando al servidor |
| `sslmode=no-verify` | Conecta y cifra, pero **no verifica** al servidor |
| Sin `sslmode` ni CA | Conecta **sin cifrar** |

Descarga el certificado en los ajustes de base de datos de tu proyecto (sección SSL) y pega su contenido **completo** en
`DATABASE_SSL_CA` (es un certificado público, no un secreto; se acepta en varias líneas o en una con `\n`). Con la CA, la
app quita `sslmode` de la URL a propósito: si no, `pg` le da prioridad a la URL y la CA no se usa. `?sslmode=no-verify` es
un último recurso: cifra pero no comprueba que hablas con Supabase.

**4. Riesgos propios de Supabase que conviene conocer**

- **Los proyectos gratuitos se pausan tras una semana de inactividad** (según su documentación). Con un portafolio de pocas
  visitas, el sitio podría quedarse sin base de datos hasta que la restaures desde su panel. El plan de pago lo evita.
- **La API de datos de Supabase expone el esquema `public`.** En proyectos existentes las tablas nuevas nacen con permisos
  para el rol público `anon`, cuya clave no es secreta: sin protección, serviría para leer `admin_users` (hashes de
  contraseña) y `sessions`. La migración **006** activa la seguridad por filas (RLS) en todas las tablas de la aplicación y
  retira esos permisos. Supabase anunció que a partir del 2026-10-30 las tablas nuevas no se expondrán por defecto en
  proyectos existentes, pero **las existentes conservan sus permisos**, así que la 006 sigue haciendo falta. Si no usas la
  API de datos, revisa en los ajustes del proyecto si puedes desactivarla.
- **La aplicación debe conectarse como dueña de las tablas** (en Supabase, el rol `postgres` de la URL del pooler), que no
  está sujeto a RLS. No uses la clave `service_role` ni un rol sin propiedad: con RLS activada y sin políticas, la app no
  vería nada.

**5. Pasos**

1. Sube el repositorio a GitHub (con al menos un commit) y conecta tu cuenta de GitHub en Render.
2. En Render: **New → Blueprint**, elige el repositorio y la rama.
3. Render pide cuatro secretos: `DATABASE_URL` (el session pooler), `DATABASE_SSL_CA` (el certificado),
   `ADMIN_USERNAME` y `ADMIN_PASSWORD` (mínimo 12 caracteres). No se guardan en el repositorio.
4. Revisa el plan del servicio y su costo **antes de confirmar**, y pulsa **Apply**.
5. En el primer arranque la app migra el esquema, carga el contenido **de ejemplo** (tu `content.json` no está en el
   repositorio) y crea el administrador. Entra a `https://<tu-servicio>.onrender.com/admin`, abre **Importar y exportar**
   e importa tu JSON.

**Costo y límites del servicio web (Render, 2026-09-24).** El plan gratuito se duerme tras 15 minutos sin visitas y tarda
cerca de un minuto en despertar: para un portafolio que verá un reclutador, la primera visita sería una espera de un minuto.
Por eso el Blueprint usa `starter` (de pago). Para probar sin gastar, cambia a `plan: free`. Consulta su página de precios.

**Verifica `TRUST_PROXY` tras el primer despliegue (importa para la seguridad).**

Render pone HTTPS y uno o más proxies delante de tu app; la IP real del visitante llega en `X-Forwarded-For`. De esa IP
dependen el límite de intentos de login y el contador de visitas únicas, y **no debes usar `TRUST_PROXY=true`**: con
`true` la app toma la IP más a la izquierda de la cabecera, que el propio visitante puede escribir, y así evadir el límite
(lo comprobé con el servidor real: con `true` registra la IP falsa). Un número fija cuántos proxies de confianza cuentas
desde la app hacia afuera. No sé cuántos hay en Render, así que:

1. Con `TRUST_PROXY=1` (el valor inicial del Blueprint), envía una petición con una IP falsa:
   `curl -s -o /dev/null -H "X-Forwarded-For: 203.0.113.9" https://<tu-servicio>.onrender.com/healthz`
2. En el panel de Render → **Logs**, busca esa petición y mira `remoteAddress` (es la IP que la app usa):
   - Es `203.0.113.9` → confías de más: **baja** el número (o pon `false`).
   - Es una dirección de Render o de Cloudflare (no la tuya) → confías de menos: **sube** el número en 1 y repite.
   - Es tu IP pública real, sin haber podido elegirla → es el valor correcto.
3. Cambia `TRUST_PROXY` en el panel (Environment) y repite hasta acertar.

Confiar de menos es seguro pero grueso (varios visitantes comparten IP); confiar de más es el error peligroso.

**Migraciones.** Se aplican al arrancar (`RUN_MIGRATIONS=true`). Render mantiene la versión vieja sirviendo hasta que la
nueva pasa el chequeo de salud, así que una migración destructiva futura podría romper unos segundos a la versión vieja.
Si algún día la hay, considera `preDeployCommand` de Render (recomendado por ellos para migraciones).

**Respaldos.** Verifica qué copias incluye tu plan de Supabase, y haz además un `pg_dump` periódico con la URL del pooler.

**Dominio propio.** Descomenta `domains` en `render.yaml` y crea el registro DNS que Render indique. Si usas Cloudflare,
Render indica dejar el registro "solo DNS" hasta que se emita el certificado y quitar los registros AAAA. Con
`renderSubdomainPolicy: disabled` el sitio deja de responder en `*.onrender.com`.

## Producción: lo que conviene saber

- **HTTPS.** Pon un proxy (Caddy o Nginx) delante, con certificado, y usa `COOKIE_SECURE=true` y `TRUST_PROXY=true`.
  Sin HTTPS las credenciales del panel viajarían en claro.
- **Respaldo.** El contenido son solo filas de PostgreSQL:
  `docker compose exec db pg_dump -U about_me about_me > respaldo.sql`.
- **Varias instancias.** Funciona (las sesiones están en la base), pero la caché de contenido es por proceso: tras
  editar, otra instancia tarda hasta 60 s en verlo.
- **Seguridad incluida.** CSP estricta (solo scripts propios), cabeceras de helmet, CSRF en todos los formularios
  del panel, sesiones con cookie `HttpOnly` + `SameSite`, límite de intentos de login por IP, enlaces
  restringidos a `https`/`http`/`mailto`/`tel` (no `javascript:`) y consultas siempre parametrizadas.
- **Restringe `/admin` si puedes.** El panel es código propio, así que conviene una capa extra delante del inicio de
  sesión (no lo sustituye). Con tu propio proxy (Caddy, Nginx) puedes limitar la ruta a tu red privada (por ejemplo
  Tailscale) o a una lista de IPs. **En Render no hay reglas por ruta:** su lista de IPs (`ipAllowList`) restringe
  TODO el servicio, no solo `/admin`, así que no sirve para un sitio público. Ver "Desplegar en Render" y la nota
  sobre el límite de intentos de login.
- **Contenido personal.** El teléfono y el correo no están en el sitio a propósito (el contacto es por
  LinkedIn). Si los agregas como "Enlaces de contacto", serán públicos.
