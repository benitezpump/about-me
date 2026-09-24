# 0002. Migración a Laravel + Filament

- **Estado:** en curso (fase 0 completada en la rama `laravel`)
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

Migrar a **Laravel 13 (PHP ≥ 8.3) + Filament 5**, en la carpeta `laravel/` de la rama `laravel`, sin tocar la app Node hasta
que la nueva la iguale. Al cortar, `laravel/` sube a la raíz y la app Node se archiva.

- **Base de datos: la misma.** Laravel adopta el esquema SQL existente (`laravel/database/sql/*.sql`, copia de
  `db/migrations/`). Una sola migración de Laravel los aplica con el mismo registro (`schema_migrations`) que usa la app
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
| 0 | Esqueleto, adopción del esquema, `/healthz`, portada, favicon | **hecha** (34 pruebas; HTML idéntico al de Node) |
| 1 | Cabeceras de seguridad (CSP, HSTS), `TRUST_PROXY`, conexión con CA (`DATABASE_SSL_CA`), caché de contenido con invalidación | pendiente |
| 2 | Panel Filament: acceso de un solo administrador, recursos (perfil, tecnologías, herramientas, experiencias, proyectos, materias, talleres, estudios, certificaciones, enlaces), límite de intentos de login | pendiente |
| 3 | Importar y exportar JSON (validador y prueba de cobertura de columnas) + contenido inicial de ejemplo | pendiente |
| 4 | Contador de visitas con privacidad (hash diario, `notrack`, DNT/GPC, robots) | pendiente |
| 5 | Dockerfile (PHP-FPM + nginx o FrankenPHP), `render.yaml`, integración continua con PostgreSQL | pendiente |
| 6 | Corte: subir `laravel/` a la raíz, archivar la app Node, actualizar guías | pendiente |

## Consecuencias

**Buenas:** estructura por convención; panel mantenido por terceros; menos código propio sensible.

**Malas, y aceptadas:** se reescribe y se reverifica la seguridad; se pierde TypeScript estricto; la imagen de Docker es
más pesada; hay que mantener PHP y Composer además de Node mientras convivan.

## Riesgos abiertos

- **Nada de esto se ha ejecutado en Docker, Render ni GitHub Actions** (la app Node tampoco). La fase 5 lo cubre.
- **Sesiones y autenticación:** la tabla `sessions` y `admin_users` de la app Node tienen otro formato que las de Laravel.
  Se resolverá en la fase 2 (tablas propias de Laravel con otro nombre; el administrador se recrea desde variables de
  entorno, sin migrar contraseñas).
- **`migrate:fresh` no elimina funciones de PostgreSQL:** por eso las pruebas reinician el esquema `public` de una base
  desechable (`tests/TestCase.php`).
