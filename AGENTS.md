# AGENTS.md

Guía para agentes de IA que trabajan en este repositorio. Es la fuente única: `CLAUDE.md` la importa. Para las
instrucciones de uso y despliegue, ver `README.md`.

## Qué es

Sitio personal de una sola página (Carlos Benítez, desarrollador full stack) cuyo contenido está en PostgreSQL y
se edita desde `/admin`. Node 22 + TypeScript + Fastify 5 + Nunjucks (HTML en el servidor) + `pg` + migraciones
SQL. Sin ORM, sin framework de frontend, sin build de cliente.

## Comandos

```bash
npm test               # 145 pruebas (node:test + tsx). Sin variables usan PGlite en el mismo proceso.
TEST_DATABASE_URL=postgres://usuario:clave@host:5432/postgres npm test   # contra un PostgreSQL REAL (crea y borra su propia base)
bash scripts/smoke.sh http://127.0.0.1:3300   # comprobaciones de humo por HTTP contra una instancia en marcha
npm run typecheck      # tsc --noEmit (TypeScript estricto)
npm run build          # compila src/ a dist/
npm run dev            # tsx watch; LEE .env
npm run dev:db         # PostgreSQL de desarrollo (PGlite) en 127.0.0.1:5433
```

Antes de dar algo por terminado: `npm run typecheck && npm test`. Si tocaste vistas, CSS o JavaScript del
navegador, además míralo en un navegador real (ver `CLAUDE.md`); las pruebas HTTP no cubren `public/js/*`.

## Reglas que no se negocian

**Datos y seguridad**
- **Nunca leas, imprimas ni edites `.env`.** Puede tener la `DATABASE_URL` real del dueño. No ejecutes
  `migrate`, `seed`, `seed:force` (borra el contenido editable) ni `admin:create` contra una base que no sea la de
  desarrollo/pruebas sin permiso explícito. Ante la duda, usa `dev:db` o el PGlite de las pruebas.
- **El teléfono y el correo del dueño no van en el sitio, ni en el seed, ni en pruebas, ni en documentos.** El
  contacto público es LinkedIn.
- **El contenido personal del dueño no se sube al repositorio.** `db/seed/content.json` (su CV) y `legacy/` están en
  `.gitignore`; lo versionado es `db/seed/content.example.json` (ficticio). Las pruebas usan SIEMPRE el ejemplo
  (`EXAMPLE_SEED_FILE`), nunca datos reales: no pongas nombres de empresas, proyectos o personas reales en pruebas,
  fixtures, documentos ni comentarios. El correo con el que se firman los commits también es público en GitHub.
- **SQL siempre parametrizado.** Los identificadores dinámicos (tablas, columnas) salen únicamente de
  `src/admin/resources.ts`, que los valida al cargar. Jamás de la petición.
- **Nunca uses `| safe`** en plantillas con contenido de la base. Nunjucks tiene autoescape activo.
- **Los enlaces editables pasan por `isSafeUrl`** (`src/admin/crud.ts`): solo `https`, `http`, `mailto`, `tel`.
- **La CSP prohíbe scripts y estilos inline** (`script-src 'self'`; `style-src` sin `unsafe-inline`). Nada de
  `<script>` en línea, `style="…"` ni `onclick=`. Usa clases y archivos en `public/`. Una prueba lo vigila.
  Para barras usa `<progress>`, no un `div` con ancho en línea.
- **Migraciones:** nunca edites una ya aplicada; crea la siguiente (`db/migrations/006_…`). Las fechas de
  periodos tienen precisión de mes; `end_date` nulo significa "en curso".
- **Restricciones nuevas sobre tablas con datos: `NOT VALID` y luego validar sin fallar** (ver el bloque final de
  `004_integrity_and_indexes.sql`). Una migración que falla tumba el sitio al arrancar, y la base del dueño puede
  tener datos que tus pruebas no imaginan. Toda migración con restricciones debe tener una prueba de actualización
  como `tests/migration.test.ts`.
- **La base es la última defensa.** Las reglas de dominio (fechas, textos, enlaces, coherencia de tipos) están
  también como restricciones SQL; si añades un campo con reglas, ponlas en el formulario **y** en la base.
- **Cada tabla nueva debe activar Row Level Security en su migración** (`alter table … enable row level security;`).
  Supabase expone el esquema `public` por su API de datos: sin RLS, una tabla nueva queda legible/escribible para quien
  tenga la clave pública `anon`. Una prueba falla si alguna tabla no la tiene. La migración 006 usa una **lista
  explícita** de tablas a propósito: no toca las que no son de esta aplicación. Si añades una tabla ya desplegada,
  añádela a esa lista en una migración nueva (no edites la 006). La app debe conectarse como dueña de las tablas.
- **La conexión a la base se cifra y se verifica con `DATABASE_SSL_CA`.** `pg` da prioridad a lo que diga la URL
  (`sslmode`) sobre la opción `ssl`, y con `sslmode=require` verifica el certificado: por eso `createPool` quita
  `sslmode` de la URL cuando hay CA. Sin `sslmode` la conexión va sin cifrar. Crea los pools con
  `createPoolFromConfig(config)`, no con `createPool(url, max)` a mano.
- **`TRUST_PROXY` no debe ser `true` en producción** (un visitante elige su IP y evade el límite de login): usa el
  número de proxies. Ver `parseTrustProxy` y `toFastifyTrustProxy` en `config.ts`, y el README ("Verifica TRUST_PROXY").
- **Las tecnologías se eligen del catálogo, no se escriben.** `technologies` (nombre único sin distinguir
  mayúsculas) + `tool_group_items` + `project_technologies`. `projects.stack` es solo un respaldo heredado: se muestra
  únicamente si el proyecto no tiene tecnologías (`stackLine()` en `content.ts`). No escribas nombres de tecnologías a
  mano en plantillas, semillas ni pruebas: usa `ensureTechnology()` (`content-io.ts`).
- **Borrados:** entre entidades independientes usa `on delete restrict` (el panel lo explica con `?err=in-use`); solo
  los hijos que se editan dentro del formulario del padre (herramientas, puntos de detalle) van en cascada.
- **El alcance del panel está congelado** (`docs/decisions/0001-arquitectura-y-alcance-del-panel.md`). El panel es
  código propio de seguridad y CRUD. Si una petición pide subir archivos, texto enriquecido, varios usuarios o roles,
  varios idiomas, borradores/historial o 2FA/correo, **cuenta las señales**: con dos o más, no lo construyas a mano;
  detente y plantea migrar el panel a un framework. `tests/scope.test.ts` lo vigila (tipos de campo, dependencias y
  roles) y falla a propósito: no lo "arregles" editándolo sin decidirlo.
- **Versiones fijadas a propósito:** Node 22 LTS (`.nvmrc`, `Dockerfile`), TypeScript `~5.9` y `@types/node ^22` (los
  tipos deben coincidir con producción). No subas TypeScript a 7 ni Node a otra mayor sin decidirlo. Dependabot ya lo
  ignora, y **nunca** propone una versión mayor de PostgreSQL (exige exportar e importar datos).

**Estilo**
- Textos de interfaz y comentarios en **español**; identificadores de código en inglés. Copy claro, en oración
  (solo la primera palabra en mayúscula), verbos concretos ("Guardar cambios", no "Enviar").
- Imports con extensión `.js` (`moduleResolution: NodeNext`). Módulos ESM.
- Sigue el estilo del entorno: comentarios que explican el porqué, no el qué. No añadas dependencias sin motivo.
- No hay ORM ni utilidades de "framework": SQL claro y funciones pequeñas.

## Arquitectura en 60 segundos

```
GET /  →  routes/public.ts  →  content.ts (14 consultas en paralelo → vista lista, caché 60 s)  →  views/home.njk
/admin →  admin/routes.ts   →  admin/crud.ts + admin/resources.ts (CRUD declarativo)  →  views/admin/*.njk
                                  └─ tras escribir: invalidateContent()
```

- **`admin/resources.ts` describe todo lo editable** (campos, tipos, validación, listas, hijos). Agregar una
  entidad = migración + entrada aquí + leerla en `content.ts` + mostrarla en `home.njk`.
- **Orden:** proyectos, materias y experiencias se ordenan por `start_date desc` (`position` solo desempata);
  talleres por `sort_date`; certificaciones por `year desc, position`. Una sección vacía desaparece del sitio y
  de la navegación.
- **Contador** (`src/visits.ts`): `record()` escribe en segundo plano (una sola sentencia SQL) y no debe romper
  la página; `flush()` espera a los pendientes (cierre y pruebas). El pie muestra `total + 1` para incluir la
  visita actual. Cuenta personas: excluye robots, `HEAD`, DNT/GPC y la cookie `notrack` que deja el login.
- **Sesiones:** cookie `sid` (path `/admin`), solo el hash SHA-256 del token va a la BD. CSRF por sesión en cada
  POST del panel; el login usa doble cookie (`lcsrf`). Límite de login: `LOGIN_MAX_ATTEMPTS`.
- **Si añades una vía de escritura al contenido, llama a `invalidateContent()`** o el sitio seguirá mostrando la
  caché hasta 60 s. La caché (`createContentCache`) hace una sola carga a la vez y descarta el resultado de una carga
  que empezó antes de la invalidación.
- **Filas hijas con lista desplegable** (tecnologías de un grupo o proyecto): `ChildResource.unique` impide repetir la
  misma; las opciones se cargan por campo y llegan a la plantilla clonada por el navegador (`c.options[f.name]`). Si
  añades otro hijo con `select`, no pases `[]` como opciones.
- **El contenido tiene un formato JSON único** (`src/content-data.ts`): es el contenido inicial (`db/seed/content.json`),
  lo que descarga el panel y lo que acepta al importar. Los tres pasan por el mismo validador, así que un archivo
  exportado siempre se puede volver a importar. **Si añades un campo o una entidad editable, actualiza también** el tipo y
  la validación (`content-data.ts`) y el insertar/exportar (`content-io.ts`, incluida `CONTENT_TABLES` si es una tabla
  nueva); si no, el respaldo pierde datos en silencio. La prueba de cobertura (`tests/app.test.ts`, "importar y
  exportar") falla si una tabla de contenido gana una columna que el formato no contempla. Las longitudes del validador repiten las de `resources.ts`.
- **Importar reemplaza TODO el contenido** (`replaceContent`: `truncate … restart identity` + inserción, en una
  transacción). No toca usuarios, sesiones ni visitas. Quien lo llame debe invocar `invalidateContent()`. La lectura de
  `exportContent` es una instantánea (`repeatable read`). No hay subida de archivos: el navegador lee el `.json` y lo envía
  como texto en un `<textarea>` (no cuenta como señal de crecimiento del panel; ver el ADR 0001).
- **Actualizar bases existentes:** el contenido inicial conserva `legacy_stack` (el texto original de cada proyecto) solo
  para que `catalog-import.ts` reconozca proyectos sin editar. No se inserta al sembrar ni se exporta.
- **Coherencia de tipos:** proyectos → experiencias `work`; materias y talleres → experiencias `teaching`, con llave
  foránea compuesta y columnas constantes `experience_kind` (no se editan). Los selectores del panel se filtran con
  `optionsFrom.where` en `resources.ts`.

## Pruebas: cómo están hechas y sus trampas

- `tests/app.test.ts` migra, siembra y prueba la app con `app.inject`. `tests/support/database.ts` elige la base: PGlite
  por socket (pool de **1 conexión**: comparte un solo backend) o, con `TEST_DATABASE_URL`, un PostgreSQL real donde cada
  archivo crea y borra su propia base `about_me_test_*`. **Toda prueba nueva debe pasar en los dos modos.**
- **Errores de SQL a propósito → dentro de `withTx`.** Fuera de una transacción PGlite corta la conexión y la
  petición siguiente falla una vez con `ECONNRESET`. Es una peculiaridad de PGlite, no de PostgreSQL real. El helper
  `codeOf` de `tests/app.test.ts` ejecuta una sentencia en una transacción que siempre se revierte y devuelve el
  código de error, así que las pruebas de restricciones no ensucian los datos.
- **Códigos de error de PostgreSQL que importan aquí:** `23514` (check), `23505` (único) y `23503` (llave foránea).
  Un borrado bloqueado por `on delete restrict` responde **`23503` en PostgreSQL real pero `23001` en PGlite**
  (verificado en las dos): el panel usa `isInUseError()`, que reconoce ambos, y las pruebas aceptan ambos. No
  asumas que PGlite se comporta igual que PostgreSQL: por eso existe `TEST_DATABASE_URL` y la integración continua.
- **Las pruebas de migración escriben los datos viejos a mano en SQL** (`tests/migration.test.ts`), no con la siembra
  actual: así el escenario "base antigua" no depende de cómo evolucione el contenido inicial.
- **No hardcodees conteos** ("9 certificados") en pruebas: otras pruebas del mismo archivo crean datos. Compara contra
  el valor de antes.
- **`legacy/index.static.html` es el oráculo de fidelidad, solo en local:** `tests/fidelity.test.ts` siembra el contenido
  REAL (`db/seed/content.json`) y exige que el texto de `<main>` sea idéntico al del HTML original, salvo las líneas de
  tecnologías (`<p class="stack">`), que salen del catálogo. Como ninguno de los dos archivos está en el repositorio,
  la prueba se OMITE donde faltan (integración continua). Si cambias el contenido a propósito, actualiza esa referencia
  sabiendo qué haces; no borres la prueba.
- Las pruebas **no son independientes entre sí** en un punto: la de "cuenta" cambia la contraseña y la restaura al
  final. Si añades otra que la cambie, restáurala también.
- El inyector de Fastify manda `user-agent: lightMyRequest` por defecto, que el contador toma por una persona.
  Para simular "sin navegador" envía `'user-agent': ''`. Los límites de login de la instancia compartida se elevan
  con `loginMaxAttempts: 1000`; la prueba del 429 usa una instancia aparte.
- Para hacer determinista el contador, espera `app.viewCounter.flush()` tras cada visita simulada.

## Recetas

- **Nuevo campo en algo editable:** migración `alter table …` → `Field` en `resources.ts` → `content.ts` (si se
  muestra) → `home.njk` → **`content-data.ts` y `content-io.ts` (JSON de importar y exportar)** → prueba.
- **Nueva pantalla del panel:** ruta en `admin/routes.ts` (dentro del bloque protegido, que ya valida sesión y
  CSRF) + plantilla en `views/admin/`.
- **Cambiar el aspecto:** los colores y tipografías están como variables en `public/css/site.css`
  (`--bg`, `--accent`…), con tema claro en `:root[data-theme="light"]`. El panel reutiliza esas variables.

## Estado conocido (verifica antes de afirmar lo contrario)

- **`Dockerfile` y `docker-compose.yml` no se han ejecutado** de punta a punta (Docker Desktop estaba apagado al
  crearlos). El compose sí se validó con `docker compose config`.
- **Contra PostgreSQL real:** la suite completa pasó (145/145 en la última corrida) contra PostgreSQL 17.10 (`embedded-postgres`, sin
  Docker) y en Node 22.23. La primera vez encontró un supuesto falso (el código de `restrict`).
- **Render y Supabase reales: nunca contactados.** Verificado en local: el cifrado con una CA propia (servidor con TLS),
  la seguridad por filas con roles `anon`/`authenticated` simulados y `render.yaml` contra el esquema oficial. Las cifras
  y comportamientos de Render/Supabase vienen de su documentación al 2026-09-24. `render.yaml` cambia la base a un
  secreto (`sync: false`): no hay base de Render.
- **La integración continua (`.github/workflows/ci.yml`) está definida pero nunca se ha ejecutado en GitHub** (el
  repositorio aún no tenía commits). Su primera ejecución la valida; el trabajo `docker` es lo único que ejercita la imagen.
- El panel se revisó en escritorio; falta revisarlo en móvil.
