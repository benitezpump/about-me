# 0001. Arquitectura y alcance del panel

- **Estado:** aceptada. **La parte de arquitectura (conservar Fastify + Nunjucks) la reemplaza el [0002](0002-migracion-a-laravel-filament.md)**; el **alcance congelado del panel y sus señales de crecimiento siguen vigentes** (ahora las vigila `tests/Unit/ScopeTest.php`).
- **Fecha:** 2026-09-24
- **Decide:** Carlos Benítez, a partir de una evaluación de arquitectura hecha ese mismo día.

## Contexto

El sitio es el portafolio de una persona: contenido que cambia unas pocas veces al mes, tráfico bajo, un solo
administrador, y un requisito explícito: el contenido vive en **PostgreSQL** y se puede editar sin tocar código.

Se construyó como un monolito con HTML en el servidor (Node 22 + TypeScript + Fastify + Nunjucks + `pg`) con un panel
de administración propio. Al revisarlo surgió la pregunta de si convenía reescribirlo sobre un stack "más estable"
(un framework con panel incluido).

## Decisión

1. **Conservar la arquitectura.** Monolito con HTML en el servidor, modelo relacional y panel en la misma aplicación.
2. **Congelar el alcance del panel.** Se mantiene como está: un administrador, campos de texto, número, fecha,
   casilla, lista y enlace. **No se le añade a mano** nada de lo listado en "Cuándo revisar esta decisión".
3. **Reducir el riesgo sin reescribir:** integración continua contra PostgreSQL real, versiones de herramientas
   fijadas y actualizaciones automáticas controladas.

## Por qué no reescribir

La reescritura tiene un costo cierto (días de trabajo y volver a verificar la seguridad) para un beneficio que hoy no
se materializa: el problema no es el stack, es el código propio de infraestructura del panel, y solo duele si el panel
crece. Además la decisión es **reversible**: el contenido vive en un esquema SQL corriente, así que migrar la aplicación
más adelante no exige migrar datos.

## Alternativas consideradas

| Opción | Código propio a mantener | Encaje | Veredicto |
|---|---|---|---|
| **Actual** (Fastify + Nunjucks + `pg`) | Alto | Alto (Node, TypeScript, PostgreSQL) | **Elegida**, con el alcance congelado |
| Laravel + Filament | Bajo | Alto (Laravel, Inertia y Docker ya se usan en el trabajo) | La opción si se dispara alguna señal de abajo |
| Django + su admin | Muy bajo | Medio (otro lenguaje) | Descartada por lenguaje |
| Strapi + frontend aparte | Bajo | Alto | Descartada: dos aplicaciones y cambios fuertes entre versiones mayores |
| Sitio estático (Astro) | Mínimo | Medio | Descartada: pierde el panel y PostgreSQL, que son requisitos |

## Evidencia (medida el 2026-09-23 y 2026-09-24)

- **Tamaño:** `src/` tiene 2 501 líneas en 21 archivos. Unas **785 (31 %)** son infraestructura que un framework trae
  hecha y auditada: contraseñas y sesiones (`auth.ts`), CRUD del panel (`crud.ts`, `routes.ts`) y migraciones
  (`migrate.ts`). Ese es el riesgo real.
- **Dependencias:** 9 directas de producción, `npm audit` sin vulnerabilidades. Las que se revisaron (Fastify, sus
  plugins, `pg`, `qs`) se publicaron en las semanas previas. Nunjucks no publica desde 2023-04-13: estable, en
  mantenimiento.
- **TypeScript:** la 7.0.2 se publicó el 2026-07-08 (cambio mayor reciente). Se fija la **5.9.3**, que compila y
  construye el proyecto sin cambios. Los tipos de Node se alinean con producción (**22**).
- **Node:** producción usa 22 LTS; el equipo local usaba 26 (no LTS). La suite completa pasa en 22.23 y en 26.3.
- **PostgreSQL real:** la suite pasa (101/101 en su momento; 145/145 tras añadir importar y exportar) contra PostgreSQL 17.10, no solo contra PGlite. Al correrla apareció
  una diferencia: un borrado bloqueado por `on delete restrict` responde `23503` en PostgreSQL y `23001` en PGlite.
  Por eso existe esta verificación.
- **Sin verificar todavía:** la imagen de Docker y el `docker-compose.yml` no se han ejecutado de punta a punta, y la
  integración continua no se ha corrido en GitHub. Ambas quedan definidas en `.github/workflows/ci.yml`.

## Consecuencias

**Buenas:** una sola aplicación pequeña de operar; el contenido no depende de ningún framework; la seguridad tiene
pruebas propias (CSRF, CSP, límites de intentos, enlaces).

**Malas, y aceptadas:** el código de autenticación, sesiones y CSRF es nuestro y no está auditado por terceros. Faltan
recuperación de contraseña, verificación en dos pasos y bitácora. Nunjucks está en mantenimiento. Se compensa con
pruebas, con la restricción del panel a quien lo administra (ver README) y con este documento.

## Cuándo revisar esta decisión

Si se necesitan **dos o más** de las siguientes, la decisión acordada es **migrar el panel a un framework con panel
incluido (Laravel + Filament como primera opción) en lugar de construirlas a mano**:

1. Subir imágenes o archivos.
2. Texto enriquecido (negritas, listas, enlaces dentro de un párrafo).
3. Más de un usuario, o roles y permisos.
4. Varios idiomas.
5. Borradores, vista previa o historial de cambios.
6. Verificación en dos pasos o recuperación de contraseña por correo.

Con una sola señal se puede evaluar caso por caso, pero hay que dejarlo escrito aquí. Una señal ya presente no se
resuelve "un poquito a mano": ahí es donde el código propio se vuelve un riesgo.

## Cómo se hace cumplir

- `tests/scope.test.ts` falla si aparecen tipos de campo nuevos (archivo, texto enriquecido…), dependencias típicas de
  esas señales (subida de archivos, editores, internacionalización, 2FA, correo) o roles en el modelo de usuarios. No
  impide cambiar el alcance: obliga a hacerlo a propósito y a actualizar este documento.
- `AGENTS.md` indica a las personas y agentes que, ante una petición que cumpla dos señales, se detengan y lo planteen.
- La integración continua (`.github/workflows/ci.yml`) y Dependabot (`.github/dependabot.yml`) vigilan el resto.

## Anexo (2026-09-24): importar y exportar JSON no es una señal de crecimiento

Se añadió al panel una pantalla para **exportar** todo el contenido a un archivo JSON e **importarlo** (reemplaza todo).
Se evaluó contra las señales de arriba:

- **No es "subir archivos" (señal 1):** el navegador lee el `.json` y lo envía como texto de un formulario normal. No hay
  `multipart`, no se guarda nada en disco ni en la base, y `tests/scope.test.ts` sigue vigilando que no aparezca
  ninguna dependencia de subida.
- **No es "borradores ni historial" (señal 5):** es un respaldo manual que se descarga a demanda; no versiona ni deja
  vista previa. Si se pidiera historial de cambios, sí contaría.
- Sigue siendo un solo administrador, sin roles ni tipos de campo nuevos.

Costo asumido: el formato (`src/content-data.ts`, `src/content-io.ts`) debe mantenerse en sincronía con las tablas. Una
prueba de ida y vuelta lo vigila.
