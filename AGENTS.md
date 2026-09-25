# AGENTS.md

Guía para agentes de IA que trabajan en este repositorio. Es la fuente única: `CLAUDE.md` la importa. Para las
instrucciones de uso y despliegue, ver `README.md`.

## Qué es

Sitio personal de una sola página (Carlos Benítez, desarrollador full stack) cuyo contenido está en PostgreSQL y se edita
desde `/admin`. **Laravel 13 + Filament 5 + PHP 8.4**, HTML en el servidor (Blade), Eloquent y SQL plano, sin framework de
frontend ni build de cliente. La aplicación anterior (Node + Fastify) está archivada en la etiqueta `node-legacy`
(`git show node-legacy:src/app.ts`); la decisión y el plan de la migración están en `docs/decisions/0002-…`.

## Comandos

```bash
composer install
TEST_DATABASE_URL=postgres://usuario:clave@host:5432/base_DESECHABLE vendor/bin/phpunit   # 185+ pruebas; BORRA el esquema public de esa base
php artisan serve                       # LEE .env
php artisan migrate --force             # aplica database/sql/*.sql (idempotente)
php artisan content:seed [--force]      # contenido inicial (--force REEMPLAZA todo el contenido editable)
php artisan content:export|content:import
php artisan admin:create <usuario> [--reset]   ·   php artisan admin:ensure
bash scripts/smoke.sh http://127.0.0.1:8080   # comprobaciones HTTP contra una instancia en marcha
```

Antes de dar algo por terminado: `vendor/bin/phpunit`. Si tocaste vistas, CSS, JavaScript o el panel, además míralo en un
navegador real (ver `CLAUDE.md`): las pruebas HTTP y de Livewire no cubren el JavaScript de Alpine ni la CSP en el navegador.

## Reglas que no se negocian

**Datos y seguridad**
- **Nunca leas, imprimas ni edites `.env`.** Puede tener la `DATABASE_URL` real del dueño, la contraseña del administrador y
  la CA. No ejecutes `migrate`, `content:seed`, `content:seed --force` (borra el contenido editable), `content:import` ni
  `admin:create` contra una base que no sea la de desarrollo/pruebas sin permiso explícito. Ante la duda, pasa una base
  desechable por variables de entorno del proceso (`DB_URL=… php artisan …`, que tienen prioridad sobre `.env`).
- **El teléfono y el correo del dueño no van en el sitio, ni en el contenido inicial, ni en pruebas, ni en documentos.** El
  contacto público es LinkedIn.
- **El contenido personal del dueño no se sube al repositorio.** `database/seed/content.json` (su CV), `legacy/` y `db/` están
  en `.gitignore`; lo versionado es `database/seed/content.example.json` (ficticio). Las pruebas usan SIEMPRE el ejemplo o
  datos ficticios (`tests/Support/Fixture.php`): no pongas nombres de empresas, proyectos o personas reales en pruebas,
  documentos ni comentarios. La única prueba que usa el contenido real es `FidelityTest`, que se omite sin esos archivos.
  El correo con el que se firman los commits también es público en GitHub: usa el `noreply` del dueño, nunca su Gmail.
- **SQL siempre parametrizado.** Con Eloquent o el constructor de consultas; si escribes SQL crudo, con `?`. Los
  identificadores dinámicos (tablas, columnas) salen de constantes del código (`ContentImporter::TABLES`), jamás de la petición.
- **Blade escapa con `{{ }}`. Nunca `{!! !!}` con contenido de la base.** Solo se permite con cadenas constantes del propio
  archivo (hay dos, para `class="current"`/`class="emphasis"`).
- **Los enlaces editables pasan por `App\Rules\SafeUrl`** (en el panel) y por `ContentParser` (al importar): solo `https`,
  `http`, `mailto`, `tel`, sin espacios. La base también lo exige (`*_url_check`).
- **La CSP del sitio público prohíbe scripts y estilos en línea** (`script-src 'self'`). Nada de `<script>` en línea,
  `style="…"` ni `onclick=`: usa clases y archivos en `public/static/`. Una prueba lo vigila. El panel de Filament necesita
  `unsafe-inline`/`unsafe-eval` (Livewire y Alpine): es una concesión acotada a `/admin` y `/livewire` (`SecurityHeaders`).
- **Esquema: nunca edites un archivo de `database/sql/` ya aplicado; añade el siguiente** (`007_….sql`). No uses el constructor
  de esquemas de Laravel para las tablas de contenido: no expresa restricciones `NOT VALID`, llaves compuestas ni RLS. Las
  migraciones de Laravel (`database/migrations/`) solo aplican ese SQL (`App\Support\SqlSchema`, que también corre al final de
  cada `migrate`: Laravel ejecuta `apply_sql_schema` una sola vez y sin eso un `007` nunca llegaría a una base ya desplegada) y crean tablas propias del framework (`web_sessions`).
- **Restricciones nuevas sobre tablas con datos: `NOT VALID` y luego validar sin fallar** (ver el bloque final de
  `004_integrity_and_indexes.sql`). Una migración que falla tumba el sitio al arrancar, y la base del dueño puede tener datos
  que tus pruebas no imaginan. Añade una prueba de actualización como la de `SchemaTest`.
- **La base es la última defensa.** Las reglas de dominio (fechas, textos, enlaces, coherencia de tipos) están también como
  restricciones SQL; si añades un campo con reglas, ponlas en el formulario **y** en la base.
- **Cada tabla nueva debe activar Row Level Security en su migración** (`alter table … enable row level security;`).
  Supabase expone el esquema `public` por su API de datos: sin RLS, una tabla nueva queda legible/escribible para quien tenga
  la clave pública `anon`. Una prueba falla si alguna tabla de `public` no la tiene (incluidas las de Laravel: `migrations`,
  `web_sessions`). La migración `006` usa una **lista explícita** de tablas a propósito: si añades una tabla ya desplegada,
  añádela a una migración nueva (no edites la 006). La app debe conectarse como dueña de las tablas.
- **La conexión a la base se cifra y se verifica con `DATABASE_SSL_CA`** (`config/database.php` → `App\Support\DatabaseSsl`):
  con CA, `sslmode=verify-full` y se quita `sslmode` de la URL (podría pisar la verificación). **En Windows la ruta del archivo
  de la CA lleva `/`**: `libpq` trata `\` como escape (ya corregido en `materialize`).
- **`TRUST_PROXY` no debe ser `true` en producción** (un visitante elige su IP y evade el límite de login): usa el número de
  proxies. Ver `App\Support\TrustProxy` y `ApplyTrustedProxies` (que sustituye al `TrustProxies` de Laravel).
- **`env()` solo en `config/`.** Con `config:cache` (lo hace el arranque del contenedor) `env()` fuera de los archivos de
  configuración devuelve `null`. Variables nuevas → `config/security.php` y `config('security.…')`.
- **Las tecnologías se eligen del catálogo, no se escriben.** `technologies` (nombre único sin distinguir mayúsculas) +
  `tool_group_items` + `project_technologies`. `projects.stack` es solo un respaldo heredado: se muestra únicamente si el
  proyecto no tiene tecnologías (`Format::stackLine`). No escribas nombres de tecnologías a mano en plantillas ni pruebas
  de contenido inicial: el importador las busca o crea (`ContentImporter::technology`).
- **Borrados:** entre entidades independientes usa `on delete restrict` (el panel lo explica con `SafeDeleteAction`); solo
  los hijos que se editan dentro del formulario del padre (herramientas, puntos de detalle) van en cascada.
- **El alcance del panel está congelado** (`docs/decisions/0001-arquitectura-y-alcance-del-panel.md` y el anexo del 0002).
  Si una petición pide subir archivos, texto enriquecido, varios usuarios o roles, varios idiomas, borradores/historial o
  2FA/correo, **cuenta las señales**: con dos o más, no lo construyas a mano; detente y plantéalo. `tests/Unit/ScopeTest.php`
  lo vigila (componentes de formulario, dependencias, modelo de usuarios y opciones del panel) y falla a propósito: no lo
  "arregles" editándolo sin decidirlo. Importar/exportar JSON **no** es subida de archivos: el navegador lee el `.json` y lo
  envía como texto.
- **Versiones fijadas a propósito:** PHP 8.4 (Dockerfile, CI), y los cambios de versión **mayor** de Laravel, Filament y
  PHPUnit se deciden a mano (Dependabot ya los ignora). **Nunca** una versión mayor de PostgreSQL (exige exportar e importar).

**Estilo**
- Textos de interfaz y comentarios en **español**; identificadores de código en inglés. Copy claro, en oración (solo la primera
  palabra en mayúscula), verbos concretos ("Guardar cambios", no "Enviar").
- Sigue el estilo del entorno: comentarios que explican el porqué, no el qué. No añadas dependencias sin motivo.
- Modelos delgados, controladores que solo orquestan, lógica en `app/Services` y `app/Content`; SQL claro y funciones pequeñas.

## Arquitectura en 60 segundos

```
GET /  →  SiteController  →  SiteContent::get() (consultas con Eloquent → arrays/objetos, caché 60 s)  →  home.blade.php
/admin →  Filament (Resources/Pages/Widgets)  →  modelos Eloquent  →  Model events → SiteContent::forget()
```

- **`SiteContent`** arma el "modelo de vista" de la portada y lo guarda en caché **como JSON** (Laravel 13 prohíbe deserializar
  objetos desde la caché: `cache.serializable_classes`). Garantías: una sola carga a la vez, y una carga que empezó antes de
  una edición no se guarda (contador de generación).
- **Si añades una vía de escritura al contenido, invalida la caché:** los modelos con `ForgetsSiteContent` lo hacen solos al
  guardar/borrar; el SQL directo, `truncate` y las actualizaciones masivas de consulta **se saltan los modelos** y deben llamar a
  `SiteContent::forget()` (lo hace `ContentImporter`).
- **Contador** (`ViewCounter`): se registra con `defer()` **después** de la respuesta y nunca debe romper la página
  (`record()` y `total()` capturan y reportan). **No uses `dispatch()->afterResponse()`**: acumula callbacks en la aplicación y
  con varias peticiones en un proceso repite las anteriores. Cuenta personas: excluye robots, `HEAD`, DNT/GPC y la cookie
  `notrack` (sin cifrar a propósito: `encryptCookies(except:)`).
- **Sesiones y autenticación:** un solo `AdminUser` sobre la tabla `admin_users` (`password_hash`, bcrypt). Sesiones en
  `web_sessions` (la `sessions` de la app anterior tiene otro formato). Sin "recordarme". El login limita intentos
  (`LOGIN_MAX_ATTEMPTS`/15 min por IP). Un hash `scrypt$…` heredado no se puede verificar: `admin:ensure` lo reemplaza una vez.
- **Formato JSON de contenido** (`app/Content`): es el contenido inicial, lo que descarga el panel y lo que acepta al importar;
  los tres pasan por el mismo validador, así que un archivo exportado siempre se puede volver a importar. El serializador
  reproduce **al byte** el de la app Node (`tests/fixtures/content.node-serialized.json`).
  **Si añades un campo o una entidad editable, actualiza también** `ContentParser`, `ContentExporter`, `ContentImporter` (y
  `TABLES` si es una tabla nueva); si no, el respaldo pierde datos en silencio. La prueba de cobertura de
  `ContentImportExportTest` falla si una tabla de contenido gana una columna que el formato no contempla.
- **Importar reemplaza TODO el contenido** (`truncate … restart identity` + inserción, en una transacción). No toca
  administradores, sesiones ni visitas. Cada escritura de la base que no debe abortar la transacción de quien llama va en
  `DB::transaction()` (punto de guardado).
- **Coherencia de tipos:** proyectos → experiencias `work`; materias y talleres → experiencias `teaching`, con llave foránea
  compuesta y columnas constantes `experience_kind` (no se editan). Los selectores del panel se filtran (`Fields::experience`).

## Pruebas: cómo están hechas y sus trampas

- **PostgreSQL real, nunca SQLite.** `tests/TestCase.php` toma `TEST_DATABASE_URL`, **borra y recrea el esquema `public`** una
  vez por ejecución (no usa `migrate:fresh`: no elimina las funciones de PostgreSQL) y cada prueba corre en una transacción
  (`DatabaseTransactions`). Sin la variable, las de integración se omiten. **Nunca la apuntes a una base real.**
- Las pruebas **no dependen de `.env`**: `phpunit.xml` fija `APP_KEY` de prueba y vacía `DATABASE_URL`, `DB_URL`,
  `DATABASE_SSL_CA`, `ADMIN_*`. Si añades una variable sensible, vacíala ahí.
- **Un error de SQL dentro de la transacción de la prueba la aborta** (`25P02`). El código de producción que puede fallar a
  propósito (borrar con `restrict`, registrar una visita) usa `DB::transaction()` para que use un punto de guardado.
- **Filament:** los widgets cargan de forma diferida (la página trae un marcador): pruébalos con `Livewire::test(Widget::class)`.
  Las acciones de tabla: `->callAction(TestAction::make('delete')->table($record))`. `Filament::setCurrentPanel(...)` se hace en
  `Tests\Support\AsAdmin`.
- **Tests con varias peticiones en la misma prueba:** el helper `get()` construye la URL con el esquema de la petición anterior
  (usa URLs absolutas si pruebas `https`), y `call(..., $cookies)` con `[]` anula `withUnencryptedCookie`.
- **No hardcodees conteos** en pruebas de contenido: compáralos con el contenido de ejemplo o con el valor de antes.
- `Tests\Support\Fixture` inserta contenido ficticio con SQL directo (prueba la lectura contra el esquema real).
- Las pruebas de migración escriben los datos viejos a mano; no dependen de cómo evolucione el contenido inicial.

## Recetas

- **Nuevo campo en algo editable:** `database/sql/00N_….sql` (`alter table …` + restricción `NOT VALID`) → modelo (y
  `$emptyStrings` si la columna es `not null default ''`) → `Field` en el recurso de Filament → `SiteContent` (si se muestra) →
  `home.blade.php` → **`ContentParser`/`ContentExporter`/`ContentImporter`** → pruebas.
- **Nueva pantalla del panel:** una `Page` de Filament en `app/Filament/Pages` (se descubre sola).
- **Cambiar el aspecto del sitio:** los colores y tipografías son variables en `public/static/css/site.css` (`--bg`, `--accent`…),
  con tema claro en `:root[data-theme="light"]`. El panel usa el diseño de Filament (color primario ámbar en `AdminPanelProvider`).

## Estado conocido (verifica antes de afirmar lo contrario)

- **Nunca ejecutados:** `Dockerfile` y `docker/Caddyfile` (Docker estaba apagado), `docker-compose.yml`,
  `.github/workflows/ci.yml` (el repositorio nunca corrió en GitHub Actions), Render y Supabase reales. `render.yaml` solo se
  validó contra el esquema oficial de Render. Se verificó por separado: la conexión cifrada con `libpq`, la secuencia de
  arranque (`docker/entrypoint.sh`) contra una base vacía con las cachés de producción, y `scripts/smoke.sh` contra ella.
- **Con `verify-full`** el certificado del pooler de Supabase debe coincidir con su nombre: no se pudo comprobar. Si falla solo
  por eso, `DB_SSLMODE=verify-ca`.
- **`TRUST_PROXY` en Render** hay que verificarlo en el primer despliegue (README).
- **Primer despliegue en Render falló** con `frankenphp: Operation not permitted` (estado 126): el binario de la imagen base lleva la
  capacidad `cap_net_bind_service=+ep` y Render no la concede. El `Dockerfile` la quita copiando el binario y `docker-compose.yml`
  arranca con `cap_drop: ALL` para que la integración continua lo detecte. **Ninguna de las dos cosas se ha ejecutado aún.**
- **FrankenPHP** se eligió sobre nginx + php-fpm por tener un solo proceso; sus etiquetas de imagen y el `Caddyfile` no se han
  probado.
