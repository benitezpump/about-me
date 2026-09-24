import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { after, before, describe, test } from 'node:test';
import { importLegacyStacks } from '../src/catalog-import.js';
import { parseContent } from '../src/content-data.js';
import { ROOT } from '../src/config.js';
import { createPool, withTx, type Pool } from '../src/db.js';
import { migrate } from '../src/migrate.js';
import { startTestDatabase, type TestDatabase } from './support/database.js';

/**
 * La ruta de actualización: una base que ya está en producción, con datos escritos cuando todavía no existían las
 * restricciones de la migración 004 ni el catálogo de la 005. Las migraciones no deben fallar (tumbarían el sitio)
 * aunque algún dato antiguo no cumpla las reglas nuevas, y no deben perder nada.
 *
 * Los datos de partida se escriben a mano en SQL, no con la siembra actual: así este escenario no depende de cómo
 * evolucione el contenido inicial y prueba de verdad "una base vieja".
 */
const MIGRATIONS = path.join(ROOT, 'db', 'migrations');

/** El contenido inicial de una instalación antigua, con su texto libre original (`legacy_stack`). Ficticio. */
const legacyContent = (() => {
  const project = (title: string, legacy_stack: string, technologies: { name: string; note?: string }[]) =>
    ({ title, start_date: '2020-01-01', legacy_stack, technologies });
  const r = parseContent({
    profile: { first_names: 'A', last_names: 'B', display_name: 'A', site_title: 'A', headline: 'A', intro: ['A'] },
    experiences: [{ kind: 'work', company: 'Acme Software', role: 'Desarrollador de software', start_date: '2020-01-01', projects: [
      project('Proyecto Uno', 'Node.js, Express, TypeScript, TypeORM, PostgreSQL.',
        ['Node.js', 'Express', 'TypeScript', 'TypeORM', 'PostgreSQL'].map((name) => ({ name }))),
      project('Proyecto Dos', 'Python y Django en backend y frontend, React Native en la app móvil, AWS.',
        [{ name: 'Python' }, { name: 'Django' }, { name: 'React Native', note: 'app móvil' }, { name: 'AWS' }]),
      project('Proyecto Tres', 'Node.js, Strapi.js.', [{ name: 'Node.js' }, { name: 'Strapi.js' }]),
    ] }],
  });
  if (!r.ok) throw new Error(r.issues.join('\n'));
  return r.data;
})();

let testDb: TestDatabase;
let pool: Pool;
let tmp: string;

const copy = (file: string) => fs.copyFileSync(path.join(MIGRATIONS, file), path.join(tmp, file));

const codeOf = async (sql: string): Promise<string> => {
  class Undo extends Error {}
  try {
    await withTx(pool, async (c) => { await c.query(sql); throw new Undo(); });
    return 'ok';
  } catch (err) {
    return err instanceof Undo ? 'ok' : ((err as { code?: string }).code ?? String(err));
  }
};

const one = async <T = Record<string, unknown>>(sql: string, params: unknown[] = []): Promise<T> =>
  (await pool.query(sql, params)).rows[0] as T;

before(async () => {
  testDb = await startTestDatabase('migration'); // PGlite, o un PostgreSQL real si hay TEST_DATABASE_URL
  pool = createPool(testDb.url, testDb.poolMax);
  tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'about-me-migrations-'));
  for (const f of ['001_init.sql', '002_experience_article.sql', '003_page_views.sql']) copy(f);
});

after(async () => {
  await pool.end();
  await testDb.stop();
  fs.rmSync(tmp, { recursive: true, force: true });
});

describe('actualizar una base con datos antiguos', () => {
  const warnings: string[] = [];
  let workId = 0;
  let counts: Record<string, number> = {};
  let stacks: Record<string, string> = {};

  const count = async (t: string): Promise<number> => (await one<{ n: number }>(`select count(*)::int n from ${t}`)).n;

  test('una base en la versión 003 se llena con datos como los de una instalación real', async () => {
    assert.deepEqual(await migrate(pool, tmp), ['001_init.sql', '002_experience_article.sql', '003_page_views.sql']);

    workId = (await one<{ id: number }>(
      `insert into experiences (kind, company, role, location, start_date) values ('work', 'Acme Software', 'Desarrollador de software', 'Ciudad Ejemplo', '2015-03-01') returning id`)).id;

    const project = (title: string, stack: string, start: string, end: string | null) =>
      pool.query(`insert into projects (experience_id, kind, title, stack, start_date, end_date) values ($1, 'work', $2, $3, $4, $5)`,
        [workId, title, stack, start, end]);
    await project('Proyecto Uno', 'Node.js, Express, TypeScript, TypeORM, PostgreSQL.', '2020-12-01', '2022-04-01');
    await project('Proyecto Dos', 'Python y Django en backend y frontend, React Native en la app móvil, AWS.', '2017-01-01', '2018-06-01');
    await project('Proyecto Tres', 'Node.js, Strapi.js (lo edité yo a mano)', '2019-05-01', '2020-08-01');
    // Datos que el esquema viejo sí permitía y las reglas nuevas no: fechas al revés y un enlace peligroso.
    await project('Fechas al revés', '', '2024-05-01', '2024-01-01');
    await pool.query(`insert into contact_links (label, url) values ('Antiguo', 'javascript:alert(1)')`);

    // Herramientas escritas a mano, con lo que un texto libre acaba teniendo: duplicados, otra mayúscula, espacios.
    const groups = await pool.query(`insert into tool_groups (label, emphasis, position) values ('Principales', true, 0), ('En proyectos', false, 1) returning id, label`);
    const gp = groups.rows.find((r) => r.label === 'Principales').id;
    const ge = groups.rows.find((r) => r.label === 'En proyectos').id;
    const tool = (g: number, name: string, position: number) =>
      pool.query('insert into tools (group_id, name, position) values ($1, $2, $3)', [g, name, position]);
    for (const [i, n] of ['Node.js', 'Vue.js', 'PostgreSQL', 'postgresql', 'Docker'].entries()) await tool(gp, n, i);
    for (const [i, n] of ['Python', 'Node.js', '  Docker  ', 'Go'].entries()) await tool(ge, n, i);

    counts = { experiences: await count('experiences'), projects: await count('projects'), tool_groups: await count('tool_groups') };
    stacks = Object.fromEntries((await pool.query('select title, stack from projects')).rows.map((r) => [r.title, r.stack]));
    assert.equal(counts.projects, 4);
    assert.equal(await count('tools'), 9);
  });

  // --------------------------------------------------------------------------------------------- migración 004

  test('la migración 004 se aplica sin fallar y avisa de lo que no pudo validar', async () => {
    copy('004_integrity_and_indexes.sql');
    assert.deepEqual(await migrate(pool, tmp, (m) => warnings.push(m)), ['004_integrity_and_indexes.sql']);

    assert.equal(warnings.length, 2, warnings.join('\n'));
    assert.ok(warnings.some((w) => w.includes('projects_dates_check')), 'avisa de las fechas');
    assert.ok(warnings.some((w) => w.includes('contact_links_url_check')), 'avisa del enlace');
    assert.ok(warnings.every((w) => /alter table \S+ validate constraint/.test(w)), 'dice cómo validar después');
  });

  test('con la 004 no se pierde ni se altera ningún dato', async () => {
    assert.equal(await count('projects'), counts.projects);
    assert.deepEqual(
      await one(`select title, start_date, end_date from projects where title = 'Fechas al revés'`),
      { title: 'Fechas al revés', start_date: '2024-05-01', end_date: '2024-01-01' });
  });

  test('solo quedan sin validar las dos restricciones que los datos antiguos incumplen, y siguen exigiéndose', async () => {
    const { rows } = await pool.query(`select conname from pg_constraint where not convalidated order by conname`);
    assert.deepEqual(rows.map((r) => r.conname), ['contact_links_url_check', 'projects_dates_check']);

    assert.equal(await codeOf(
      `insert into projects (experience_id, kind, title, start_date, end_date) values (${workId}, 'work', 'Otro al revés', '2024-05-01', '2024-01-01')`), '23514');
    assert.equal(await codeOf(`insert into contact_links (label, url) values ('Otro', 'javascript:alert(2)')`), '23514');
  });

  test('corregidos los datos, el aviso indica un comando que funciona', async () => {
    await pool.query(`update projects set end_date = start_date where title = 'Fechas al revés'`);
    await pool.query(`delete from contact_links where label = 'Antiguo'`);
    await pool.query('alter table projects validate constraint projects_dates_check');
    await pool.query('alter table contact_links validate constraint contact_links_url_check');
    assert.deepEqual((await pool.query(`select 1 from pg_constraint where not convalidated`)).rows, []);
  });

  // --------------------------------------------------------------------------------------------- migración 005

  test('la migración 005 (catálogo) se aplica sin avisos y elimina la tabla vieja', async () => {
    const before = warnings.length;
    copy('005_technology_catalog.sql');
    assert.deepEqual(await migrate(pool, tmp, (m) => warnings.push(m)), ['005_technology_catalog.sql']);
    assert.equal(warnings.length, before, 'sin avisos nuevos');
    assert.equal((await one<{ t: string | null }>(`select to_regclass('public.tools') as t`)).t, null, 'la tabla `tools` ya no existe');
  });

  test('las herramientas pasan al catálogo sin duplicados, conservando la primera escritura y el orden', async () => {
    const names = (await pool.query('select name from technologies order by lower(name)')).rows.map((r) => r.name);
    assert.deepEqual(names, ['Docker', 'Go', 'Node.js', 'PostgreSQL', 'Python', 'Vue.js'],
      '"postgresql" se unifica con "PostgreSQL" y "  Docker  " con "Docker"');

    const group = async (label: string): Promise<string[]> => (await pool.query(
      `select t.name from tool_group_items i
         join technologies t on t.id = i.technology_id
         join tool_groups g on g.id = i.group_id
        where g.label = $1 order by i.position`, [label])).rows.map((r) => r.name);
    assert.deepEqual(await group('Principales'), ['Node.js', 'Vue.js', 'PostgreSQL', 'Docker'], 'sin el duplicado, mismo orden');
    assert.deepEqual(await group('En proyectos'), ['Python', 'Node.js', 'Docker', 'Go'], 'la misma tecnología puede estar en dos grupos');
  });

  test('los proyectos no se tocan: su texto libre queda como respaldo', async () => {
    assert.equal(await count('projects'), counts.projects);
    assert.equal(await count('project_technologies'), 0, 'no se adivina nada sobre prosa');
    const now = Object.fromEntries((await pool.query('select title, stack from projects')).rows.map((r) => [r.title, r.stack]));
    assert.deepEqual(now, stacks);
  });

  // --------------------------------------------------------------------------------------------- importador

  test('el importador convierte solo lo que coincide exactamente con el texto original y reporta lo editado', async () => {
    const report = await importLegacyStacks(pool, legacyContent);
    assert.deepEqual(report.converted, ['Proyecto Uno', 'Proyecto Dos']);
    assert.deepEqual(report.skipped.map((s) => s.title), ['Proyecto Tres']);
    assert.match(report.skipped[0]!.reason, /editado a mano/);

    const links = async (title: string) => (await pool.query(
      `select t.name, pt.note from project_technologies pt
         join technologies t on t.id = pt.technology_id join projects p on p.id = pt.project_id
        where p.title = $1 order by pt.position`, [title])).rows;
    assert.deepEqual(await links('Proyecto Uno'), ['Node.js', 'Express', 'TypeScript', 'TypeORM', 'PostgreSQL'].map((name) => ({ name, note: null })));
    assert.deepEqual(await links('Proyecto Dos'), [
      { name: 'Python', note: null }, { name: 'Django', note: null }, { name: 'React Native', note: 'app móvil' }, { name: 'AWS', note: null }]);
    assert.deepEqual(await links('Proyecto Tres'), [], 'lo editado a mano no se toca');

    const stack = (await one<{ stack: string }>(`select stack from projects where title = 'Proyecto Uno'`)).stack;
    assert.equal(stack, stacks['Proyecto Uno'], 'el texto original se conserva como respaldo');
  });

  test('el importador reutiliza el catálogo existente y solo crea lo que falta', async () => {
    const names = (await pool.query('select name from technologies order by lower(name)')).rows.map((r) => r.name);
    assert.deepEqual(names, ['AWS', 'Django', 'Docker', 'Express', 'Go', 'Node.js', 'PostgreSQL', 'Python', 'React Native', 'TypeORM', 'TypeScript', 'Vue.js']);
  });

  test('el importador se puede repetir sin efectos', async () => {
    const before = await count('project_technologies');
    const again = await importLegacyStacks(pool, legacyContent);
    assert.deepEqual(again.converted, []);
    assert.deepEqual(again.skipped.map((s) => s.title).sort(), ['Proyecto Dos', 'Proyecto Tres', 'Proyecto Uno']);
    assert.equal(await count('project_technologies'), before);
  });

  // --------------------------------------------------------------------------------------------- migración 006 (RLS)

  describe('seguridad por filas (006) con los roles de la API de Supabase', () => {
    // Los roles son del clúster, no de la base: solo se crean (y luego se borran) si no existían, y si ya existen
    // (por ejemplo si TEST_DATABASE_URL apuntara a un servidor tipo Supabase) no se toca nada.
    const created: string[] = [];
    let canRun = true;

    const asRole = async <T>(role: string, sql: string): Promise<{ rows?: T[]; code?: string }> => {
      class Undo extends Error {}
      let out: { rows?: T[]; code?: string } = {};
      try {
        await withTx(pool, async (c) => {
          await c.query(`set local role ${role}`);
          out = { rows: (await c.query(sql)).rows as T[] };
          throw new Undo();
        });
      } catch (err) {
        if (!(err instanceof Undo)) out = { code: (err as { code?: string }).code ?? String(err) };
      }
      return out;
    };

    test('preparación: roles anon/authenticated con los permisos que Supabase les da por defecto, y una tabla ajena', async () => {
      for (const role of ['anon', 'authenticated']) {
        const exists = (await pool.query('select 1 from pg_roles where rolname = $1', [role])).rows.length > 0;
        if (exists) { canRun = false; return; }
      }
      for (const role of ['anon', 'authenticated']) {
        await pool.query(`create role ${role} nologin`);
        created.push(role);
        await pool.query(`grant usage on schema public to ${role}`);
        await pool.query(`grant select, insert, update, delete on all tables in schema public to ${role}`);
      }
      await pool.query(`insert into admin_users (username, password_hash) values ('alguien', 'hash-secreto')`);
      // Una tabla que NO es de esta aplicación (otro uso del mismo proyecto): la migración no debe tocarla.
      await pool.query(`create table otra_app (id int primary key, dato text)`);
      await pool.query(`insert into otra_app values (1, 'de otra aplicación')`);
      await pool.query(`grant select on otra_app to anon`);

      // Antes de la 006, cualquiera con la clave `anon` lee los hashes de contraseña: el problema que se corrige.
      const before = await asRole<{ password_hash: string }>('anon', 'select password_hash from admin_users');
      assert.equal(before.rows?.[0]?.password_hash, 'hash-secreto');
    });

    test('la migración 006 se aplica sin avisos', async () => {
      const before = warnings.length;
      assert.deepEqual(await migrate(pool, undefined, (m) => warnings.push(m)), ['006_row_level_security.sql']);
      assert.equal(warnings.length, before, 'sin avisos nuevos');
    });

    test('todas las tablas de la aplicación quedan con RLS activada', async () => {
      const { rows } = await pool.query(
        `select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
          where n.nspname = 'public' and c.relkind = 'r' and not c.relrowsecurity and c.relname <> 'otra_app' order by 1`);
      assert.deepEqual(rows, []);
    });

    test('los roles de la API ya no pueden leer ni escribir las tablas de la aplicación', async () => {
      if (!canRun) return;
      for (const role of ['anon', 'authenticated']) {
        for (const table of ['admin_users', 'sessions', 'projects', 'profile']) {
          const read = await asRole(role, `select * from ${table}`);
          assert.equal(read.code, '42501', `${role} no debe leer ${table}`);
        }
        const write = await asRole(role, `insert into contact_links (label, url) values ('x', 'https://x.com')`);
        assert.equal(write.code, '42501', `${role} no debe escribir`);
      }
    });

    test('y aunque un permiso se volviera a conceder por descuido, RLS sin políticas sigue negando las filas', async () => {
      if (!canRun) return;
      await pool.query('grant select on admin_users to anon');
      const leaked = await asRole<{ password_hash: string }>('anon', 'select password_hash from admin_users');
      assert.deepEqual(leaked.rows, [], 'con el permiso concedido, RLS devuelve cero filas en lugar de los hashes');
      assert.equal((await pool.query('select count(*)::int n from admin_users')).rows[0].n, 1, 'el dueño sigue viendo su fila');
      await pool.query('revoke select on admin_users from anon');
    });

    test('la aplicación (dueño de las tablas) no se ve afectada', async () => {
      assert.ok((await count('projects')) > 0);
      assert.equal(await codeOf(`insert into contact_links (label, url) values ('x', 'https://x.com')`), 'ok');
    });

    test('lo que no es de la aplicación no se toca: sin RLS y con sus permisos intactos', async () => {
      if (!canRun) return;
      assert.equal((await one<{ relrowsecurity: boolean }>(`select relrowsecurity from pg_class where relname = 'otra_app'`)).relrowsecurity, false);
      const other = await asRole<{ dato: string }>('anon', 'select dato from otra_app');
      assert.equal(other.rows?.[0]?.dato, 'de otra aplicación');
    });

    test('limpieza de los roles y la tabla de prueba', async () => {
      await pool.query('drop table if exists otra_app');
      await pool.query(`delete from admin_users where username = 'alguien'`);
      for (const role of created) {
        await pool.query(`drop owned by ${role}`);
        await pool.query(`drop role ${role}`);
      }
      const left = (await pool.query(`select rolname from pg_roles where rolname in ('anon', 'authenticated')`)).rows;
      assert.deepEqual(left.map((r) => r.rolname).filter((r) => created.includes(r)), []);
    });
  });
});
