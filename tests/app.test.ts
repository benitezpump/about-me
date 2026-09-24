import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { after, before, describe, test } from 'node:test';
import qs from 'qs';
import { buildApp } from '../src/app.js';
import { ensureAdmin } from '../src/auth.js';
import { ROOT, type Config } from '../src/config.js';
import { createPool, withTx, type Pool } from '../src/db.js';
import { migrate } from '../src/migrate.js';
import { runSeed } from '../src/seed.js';
import { EXAMPLE_SEED_FILE, loadSeedContent, type ProjectData } from '../src/content-data.js';
import { CONTENT_TABLES } from '../src/content-io.js';
import { startTestDatabase, type TestDatabase } from './support/database.js';

const PASSWORD = 'correct horse battery staple';

type App = Awaited<ReturnType<typeof buildApp>>;
let testDb: TestDatabase;
let pool: Pool;
let app: App;
let config: Config;

/** Cookies mínimas para simular un navegador. */
class Jar {
  private cookies = new Map<string, string>();
  update(res: { headers: Record<string, unknown> }): void {
    const raw = res.headers['set-cookie'];
    for (const h of ([] as string[]).concat((raw as string | string[] | undefined) ?? [])) {
      const [pair = ''] = h.split(';');
      const eq = pair.indexOf('=');
      const name = pair.slice(0, eq);
      if (/max-age=0|expires=thu, 01 jan 1970/i.test(h)) this.cookies.delete(name);
      else this.cookies.set(name, pair.slice(eq + 1));
    }
  }
  header(): string {
    return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
  }
  has(name: string): boolean { return this.cookies.has(name); }
}

async function request(jar: Jar, method: 'GET' | 'POST', url: string, form?: Record<string, unknown>) {
  const res = await app.inject({
    method, url,
    headers: { cookie: jar.header(), ...(form ? { 'content-type': 'application/x-www-form-urlencoded' } : {}) },
    ...(form ? { payload: qs.stringify(form) } : {}),
  });
  jar.update(res);
  return res;
}

const csrfOf = (html: string): string => /name="_csrf" value="([^"]+)"/.exec(html)?.[1] ?? '';

async function login(jar: Jar, password = PASSWORD) {
  const page = await request(jar, 'GET', '/admin/login');
  return request(jar, 'POST', '/admin/login', { _csrf: csrfOf(page.body), username: 'admin', password });
}

/** Devuelve el token CSRF de la sesión leyendo cualquier página protegida. */
async function sessionCsrf(jar: Jar): Promise<string> {
  return csrfOf((await request(jar, 'GET', '/admin/account')).body);
}

const textOf = (html: string): string =>
  html
    .replace(/<script[\s\S]*?<\/script>/g, '')
    .replace(/<[^>]+>/g, '')
    .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'")
    .replace(/\s+/g, '');

const mainOf = (html: string): string => /<main[\s\S]*<\/main>/.exec(html)?.[0] ?? '';

before(async () => {
  testDb = await startTestDatabase('app'); // PGlite, o un PostgreSQL real si hay TEST_DATABASE_URL

  config = {
    env: 'test', host: '127.0.0.1', port: 0, databaseUrl: testDb.url, databaseSslCa: undefined,
    poolMax: testDb.poolMax, cookieSecure: false, trustProxy: false, runMigrations: true, seedOnEmpty: false,
    statsTimezone: 'America/Hermosillo', loginMaxAttempts: 1000, adminUsername: 'admin', adminPassword: PASSWORD,
  };
  pool = createPool(config.databaseUrl, testDb.poolMax);
  await migrate(pool);
  await runSeed(pool, { file: EXAMPLE_SEED_FILE }); // siempre el contenido de ejemplo, nunca el personal
  await ensureAdmin(pool, 'admin', PASSWORD);
  app = await buildApp({ pool, config });
});

after(async () => {
  await app.close();
  await pool.end();
  await testDb.stop();
});

describe('base de datos', () => {
  test('las migraciones son idempotentes', async () => {
    assert.deepEqual(await migrate(pool), []);
  });

  test('la siembra no pisa contenido existente', async () => {
    assert.equal(await runSeed(pool), false);
  });

  test('el esquema rechaza un proyecto de trabajo sin experiencia', async () => {
    // Dentro de una transacción con rollback, como hace la app: PGlite corta la conexión tras un error suelto.
    await assert.rejects(
      withTx(pool, (c) => c.query(`insert into projects (kind, title, start_date) values ('work', 'x', '2020-01-01')`)),
      /projects_work_needs_experience/,
    );
  });
});

describe('sitio público', () => {
  test('responde 200 con cabeceras de seguridad', async () => {
    const res = await app.inject({ method: 'GET', url: '/' });
    assert.equal(res.statusCode, 200);
    assert.match(String(res.headers['content-security-policy']), /script-src 'self'/);
    assert.match(String(res.headers['content-security-policy']), /frame-ancestors 'none'/);
    assert.equal(res.headers['x-content-type-options'], 'nosniff');
  });

  test('conserva la estructura del contenido inicial: proyectos, en curso, detalles y certificados enlazados', async () => {
    const seed = loadSeedContent(EXAMPLE_SEED_FILE);
    const work = seed.experiences.flatMap((e) => e.projects);
    const withDetail = work.filter((p) => p.highlights.length > 0); // los proyectos propios no llevan <details>
    const linked = seed.certification_groups.flatMap((g) => g.items).filter((i) => i.url);

    const { body } = await app.inject({ method: 'GET', url: '/' });
    const timeline = /<ol class="timeline">([\s\S]*?)<\/ol>/.exec(body)?.[1] ?? '';
    assert.equal((timeline.match(/<li(?: class="current")?>\s*<p class="when">/g) ?? []).length, work.length, 'proyectos de trabajo');
    assert.equal((timeline.match(/<li class="current">/g) ?? []).length, work.filter((p) => !p.end_date).length, 'proyectos en curso');
    assert.equal((body.match(/<article class="project">/g) ?? []).length, seed.personal_projects.length, 'proyectos propios');
    assert.equal((body.match(/<details>/g) ?? []).length, withDetail.length, 'proyectos con detalle');

    const formacion = /<section class="sec" id="formacion"[\s\S]*?<\/section>/.exec(body)?.[0] ?? '';
    assert.equal((formacion.match(/<a href="https?:[^"]+" target="_blank" rel="noopener noreferrer">/g) ?? []).length, linked.length, 'certificados enlazados');
  });

  test('todo el JavaScript es externo (compatible con la CSP)', async () => {
    const { body } = await app.inject({ method: 'GET', url: '/' });
    assert.equal(/<script(?![^>]*\bsrc=)[^>]*>/.test(body), false);
    assert.equal(/\sstyle="/.test(body), false);
    assert.equal(/\sonclick=/.test(body), false);
  });

  test('sirve archivos estáticos y el favicon con las iniciales', async () => {
    assert.equal((await app.inject({ method: 'GET', url: '/static/css/site.css' })).statusCode, 200);
    const fav = await app.inject({ method: 'GET', url: '/favicon.svg' });
    assert.equal(fav.statusCode, 200);
    assert.match(fav.body, />AP</);
  });

  test('healthz confirma que la base responde', async () => {
    const res = await app.inject({ method: 'GET', url: '/healthz' });
    assert.equal(res.statusCode, 200);
    assert.deepEqual(res.json(), { status: 'ok' });
  });

  test('una ruta inexistente devuelve 404 con página propia', async () => {
    const res = await app.inject({ method: 'GET', url: '/no-existe' });
    assert.equal(res.statusCode, 404);
    assert.match(res.body, /Página no encontrada/);
  });
});

describe('acceso al panel', () => {
  test('sin sesión, /admin y las rutas protegidas redirigen al login', async () => {
    for (const url of ['/admin', '/admin/projects', '/admin/projects/1', '/admin/profile', '/admin/account']) {
      const res = await app.inject({ method: 'GET', url });
      assert.equal(res.statusCode, 302, url);
      assert.equal(res.headers.location, '/admin/login', url);
    }
    const post = await app.inject({ method: 'POST', url: '/admin/projects', payload: { title: 'x' } });
    assert.equal(post.statusCode, 302);
  });

  test('rechaza una contraseña incorrecta sin revelar si el usuario existe', async () => {
    const jar = new Jar();
    const res = await login(jar, 'incorrecta-incorrecta');
    assert.equal(res.statusCode, 401);
    assert.match(res.body, /Usuario o contraseña incorrectos/);
    assert.equal(jar.has('sid'), false);
  });

  test('el login exige el token CSRF del formulario', async () => {
    const jar = new Jar();
    await request(jar, 'GET', '/admin/login');
    const res = await request(jar, 'POST', '/admin/login', { _csrf: 'falso', username: 'admin', password: PASSWORD });
    assert.equal(res.statusCode, 400);
    assert.equal(jar.has('sid'), false);
  });

  test('con credenciales correctas abre sesión con cookie HttpOnly y SameSite', async () => {
    const jar = new Jar();
    const res = await login(jar);
    assert.equal(res.statusCode, 302);
    assert.equal(res.headers.location, '/admin');
    const cookie = ([] as string[]).concat(res.headers['set-cookie'] as string | string[]).find((c) => c.startsWith('sid='))!;
    assert.match(cookie, /HttpOnly/i);
    assert.match(cookie, /SameSite=Lax/i);
    assert.match(cookie, /Path=\/admin/i);
    const dash = await request(jar, 'GET', '/admin');
    assert.equal(dash.statusCode, 200);
    assert.match(dash.body, /Proyectos/);
    assert.equal(dash.headers['cache-control'], 'no-store');
  });

  test('cerrar sesión invalida el token en el servidor', async () => {
    const jar = new Jar();
    await login(jar);
    const stolen = jar.header();
    await request(jar, 'POST', '/admin/logout', { _csrf: await sessionCsrf(jar) });
    const res = await app.inject({ method: 'GET', url: '/admin', headers: { cookie: stolen } });
    assert.equal(res.statusCode, 302, 'la cookie robada ya no sirve');
  });

  test('un POST autenticado sin token CSRF válido se rechaza con 403', async () => {
    const jar = new Jar();
    await login(jar);
    const before = (await pool.query('select count(*)::int n from now_items')).rows[0].n;
    const res = await request(jar, 'POST', '/admin/now-items', { title: 'x', since_label: 'y', _csrf: 'falso' });
    assert.equal(res.statusCode, 403);
    const count = await pool.query('select count(*)::int n from now_items');
    assert.equal(count.rows[0].n, before);
  });

  test('limita los intentos de login (429)', async () => {
    const other = await buildApp({ pool, config: { ...config, loginMaxAttempts: 10 } });
    let last = 0;
    for (let i = 0; i < 12; i++) {
      const res = await other.inject({ method: 'POST', url: '/admin/login', payload: { username: 'x', password: 'y' } });
      last = res.statusCode;
    }
    await other.close();
    assert.equal(last, 429);
  });
});

describe('edición de contenido', () => {
  const jar = new Jar();
  let csrf = '';
  let createdId = 0;
  let experienceId = 0;

  before(async () => {
    await login(jar);
    csrf = await sessionCsrf(jar);
    experienceId = (await pool.query(`select id from experiences where kind = 'work'`)).rows[0].id;
  });

  test('crear un proyecto con puntos de detalle lo publica de inmediato y en orden cronológico', async () => {
    const res = await request(jar, 'POST', '/admin/projects', {
      _csrf: csrf, kind: 'work', experience_id: String(experienceId), title: 'Proyecto de prueba',
      description: 'Descripción de prueba', stack: 'Node.js, PostgreSQL', start_date: '2030-01-01', end_date: '',
      visible: '1', position: '0',
      children: { highlights: {
        0: { label: 'Etiqueta', body: 'Primer punto' },
        1: { label: '', body: 'Segundo punto' },
        2: { label: '', body: '' }, // fila vacía: se ignora
      } },
    });
    assert.equal(res.statusCode, 302, res.body);
    createdId = Number(/\/admin\/projects\/(\d+)\?ok=created/.exec(String(res.headers.location))?.[1]);
    assert.ok(createdId > 0);

    const home = (await app.inject({ method: 'GET', url: '/' })).body;
    assert.match(home, /Proyecto de prueba/);
    assert.match(home, /<strong>Etiqueta:<\/strong> Primer punto/);
    assert.match(home, /Segundo punto/);
    // 2030 es la fecha más reciente: debe ser el primero de la línea de tiempo y estar "en curso".
    assert.ok(home.indexOf('Proyecto de prueba') < home.indexOf('>Sistema Alfa<'));
    const rows = (await pool.query('select count(*)::int n from project_highlights where project_id = $1', [createdId])).rows[0].n;
    assert.equal(rows, 2);
  });

  test('editar reemplaza las filas hijas y respeta el orden enviado', async () => {
    const res = await request(jar, 'POST', `/admin/projects/${createdId}`, {
      _csrf: csrf, kind: 'work', experience_id: String(experienceId), title: 'Proyecto editado',
      description: '', stack: '', start_date: '2030-01-01', end_date: '2030-06-01', visible: '1', position: '0',
      children: { highlights: { 0: { label: '', body: 'Solo este' } } },
    });
    assert.equal(res.statusCode, 302);
    const home = (await app.inject({ method: 'GET', url: '/' })).body;
    assert.match(home, /Proyecto editado/);
    assert.doesNotMatch(home, /Proyecto de prueba/);
    assert.doesNotMatch(home, /Segundo punto/);
    assert.match(home, /Solo este/);
  });

  test('el contenido se escapa: un título con HTML no se ejecuta', async () => {
    await request(jar, 'POST', `/admin/projects/${createdId}`, {
      _csrf: csrf, kind: 'work', experience_id: String(experienceId), title: '<script>alert(1)</script>',
      description: '<img src=x onerror=alert(1)>', stack: '', start_date: '2030-01-01', end_date: '', visible: '1', position: '0',
    });
    const home = (await app.inject({ method: 'GET', url: '/' })).body;
    assert.doesNotMatch(home, /<script>alert\(1\)/);
    assert.doesNotMatch(home, /<img src=x/);
    assert.match(home, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
  });

  test('rechaza enlaces javascript: y data: en certificaciones', async () => {
    const groupId = (await pool.query('select id from certification_groups limit 1')).rows[0].id;
    for (const url of ['javascript:alert(1)', 'data:text/html,<b>x</b>', 'ftp://x.y/z']) {
      const res = await request(jar, 'POST', '/admin/certifications', {
        _csrf: csrf, group_id: String(groupId), name: 'Malicioso', year: '2026', url, position: '0',
      });
      assert.equal(res.statusCode, 422, url);
      assert.match(res.body, /empiece con https/);
    }
    const n = (await pool.query(`select count(*)::int n from certifications where name = 'Malicioso'`)).rows[0].n;
    assert.equal(n, 0);
    const ok = await request(jar, 'POST', '/admin/certifications', {
      _csrf: csrf, group_id: String(groupId), name: 'Legítima', year: '2026', url: 'https://example.com/c/1', position: '99',
    });
    assert.equal(ok.statusCode, 302);
  });

  test('valida campos obligatorios, fechas y reglas entre campos, y conserva lo escrito', async () => {
    const res = await request(jar, 'POST', '/admin/projects', {
      _csrf: csrf, kind: 'work', experience_id: '', title: '', start_date: '2025-05-01', end_date: '2025-01-01', position: '0',
    });
    assert.equal(res.statusCode, 422);
    assert.match(res.body, /Este campo es obligatorio/);
    assert.match(res.body, /no puede ser anterior al inicio/);
    assert.match(res.body, /value="2025-05-01"/, 'el formulario conserva lo escrito');
  });

  test('un año fuera de rango y una llave foránea inexistente se rechazan', async () => {
    const res = await request(jar, 'POST', '/admin/certifications', {
      _csrf: csrf, group_id: '99999', name: 'X', year: '1800', position: '0',
    });
    assert.equal(res.statusCode, 422);
    assert.match(res.body, /Elige una opción/);
    assert.match(res.body, /al menos 1990/);
  });

  test('editar el perfil cambia la portada, el <title> y el favicon', async () => {
    const profile = (await pool.query('select * from profile')).rows[0];
    const res = await request(jar, 'POST', '/admin/profile', {
      _csrf: csrf, first_names: profile.first_names, last_names: profile.last_names, display_name: 'Ana Pérez',
      site_title: 'Ana Pérez, ingeniera', headline: 'Nueva línea de presentación.', location: 'Hermosillo',
      intro: 'Primer párrafo.\n\nSegundo párrafo.', cta_label: 'Hablemos', cta_url: 'mailto:ana@example.com',
      contact_prompt: 'Hola', meta_description: 'd', og_description: 'd',
    });
    assert.equal(res.statusCode, 302);
    const home = (await app.inject({ method: 'GET', url: '/' })).body;
    assert.match(home, /<title>Ana Pérez, ingeniera<\/title>/);
    assert.match(home, /Nueva línea de presentación\./);
    assert.equal((home.match(/class="lead"/g) ?? []).length, 2);
    assert.match(home, /href="mailto:ana@example.com"/);
    assert.doesNotMatch(home, /href="mailto:ana@example.com" target=/, 'mailto no abre pestaña nueva');
    assert.match((await app.inject({ method: 'GET', url: '/favicon.svg' })).body, />AP</);
  });

  test('una sección sin contenido desaparece del sitio y de la navegación', async () => {
    await pool.query('update projects set visible = false where kind = $1', ['personal']);
    (await import('../src/content.js')).invalidateContent();
    const home = (await app.inject({ method: 'GET', url: '/' })).body;
    assert.doesNotMatch(home, /id="proyectos"/);
    assert.doesNotMatch(home, /href="#proyectos"/);
    await pool.query('update projects set visible = true where kind = $1', ['personal']);
    (await import('../src/content.js')).invalidateContent();
  });

  test('eliminar un proyecto lo quita del sitio y borra sus puntos en cascada', async () => {
    const res = await request(jar, 'POST', `/admin/projects/${createdId}/delete`, { _csrf: csrf });
    assert.equal(res.statusCode, 302);
    assert.equal((await pool.query('select count(*)::int n from project_highlights where project_id = $1', [createdId])).rows[0].n, 0);
    assert.doesNotMatch((await app.inject({ method: 'GET', url: '/' })).body, /Proyecto editado/);
  });

  test('ids inválidos o inexistentes devuelven 404 y el perfil no se puede borrar', async () => {
    assert.equal((await request(jar, 'GET', '/admin/projects/abc')).statusCode, 404);
    assert.equal((await request(jar, 'GET', '/admin/projects/999999')).statusCode, 404);
    assert.equal((await request(jar, 'GET', '/admin/nada')).statusCode, 404);
    assert.equal((await request(jar, 'GET', '/admin/profile/new')).statusCode, 404);
    assert.equal((await request(jar, 'POST', '/admin/projects/999999/delete', { _csrf: csrf })).statusCode, 404);
  });

  test('las listas del panel se muestran con datos de la base', async () => {
    for (const r of ['now-items', 'tools', 'experiences', 'projects', 'courses', 'workshops', 'education',
      'certification-groups', 'certifications', 'contact-links']) {
      const res = await request(jar, 'GET', `/admin/${r}`);
      assert.equal(res.statusCode, 200, r);
      const form = await request(jar, 'GET', `/admin/${r}/new`);
      assert.equal(form.statusCode, 200, `${r}/new`);
    }
    assert.match((await request(jar, 'GET', '/admin/projects')).body, /Sistema Alfa/);
    assert.match((await request(jar, 'GET', `/admin/projects/${experienceId}`)).body, /data-children="highlights"/);
  });
});

describe('cuenta', () => {
  test('cambiar la contraseña exige la actual, cierra otras sesiones y la nueva funciona', async () => {
    const jarA = new Jar();
    const jarB = new Jar();
    await login(jarA);
    await login(jarB);
    const csrf = await sessionCsrf(jarA);

    const weak = await request(jarA, 'POST', '/admin/account', { _csrf: csrf, current: PASSWORD, next: 'corta', confirm: 'corta' });
    assert.equal(weak.statusCode, 422);
    const wrong = await request(jarA, 'POST', '/admin/account', { _csrf: csrf, current: 'no-es-esta-1234', next: 'otra contraseña larga 1', confirm: 'otra contraseña larga 1' });
    assert.equal(wrong.statusCode, 422);
    assert.match(wrong.body, /contraseña actual no es correcta/);

    const NEW = 'una contraseña nueva y larga';
    const ok = await request(jarA, 'POST', '/admin/account', { _csrf: csrf, current: PASSWORD, next: NEW, confirm: NEW });
    assert.equal(ok.statusCode, 302);

    assert.equal((await request(jarB, 'GET', '/admin')).statusCode, 302, 'la otra sesión se cerró');
    assert.equal((await request(jarA, 'GET', '/admin')).statusCode, 200, 'la sesión actual sigue');
    assert.equal((await login(new Jar(), PASSWORD)).statusCode, 401, 'la contraseña vieja ya no sirve');
    assert.equal((await login(new Jar(), NEW)).statusCode, 302);

    // Deja la contraseña como estaba: las pruebas siguientes inician sesión con la original.
    const restore = await request(jarA, 'POST', '/admin/account', { _csrf: csrf, current: NEW, next: PASSWORD, confirm: PASSWORD });
    assert.equal(restore.statusCode, 302);
    assert.equal((await login(new Jar(), PASSWORD)).statusCode, 302);
  });
});

describe('contador de visualizaciones', () => {
  const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36';
  const OTHER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15';
  const visit = async (headers: Record<string, string> = {}, ip = '203.0.113.7', method: 'GET' | 'HEAD' = 'GET') => {
    const res = await app.inject({ method, url: '/', remoteAddress: ip, headers });
    await app.viewCounter.flush();
    return res;
  };
  const counts = async () =>
    (await pool.query('select coalesce(sum(views),0)::int v, coalesce(sum(visitors),0)::int u from page_views')).rows[0] as { v: number; u: number };

  before(async () => {
    await pool.query('update profile set show_view_count = true');
    (await import('../src/content.js')).invalidateContent();
  });

  test('cuenta una visita y no repite al mismo visitante como visitante único', async () => {
    const labelOf = (html: string) => Number((/class="views">[\s\S]*?<\/svg>([^<]+)</.exec(html)?.[1] ?? '').replace(/\D/g, ''));
    const start = await counts();
    const first = await visit({ 'user-agent': BROWSER });
    const second = await visit({ 'user-agent': BROWSER });
    const end = await counts();
    assert.equal(end.v - start.v, 2, 'dos visualizaciones');
    assert.equal(end.u - start.u, 1, 'un solo visitante único');
    // La página que recibe la persona ya la incluye a ella.
    assert.equal(labelOf(first.body), start.v + 1);
    assert.equal(labelOf(second.body), start.v + 2);
  });

  test('otro visitante (otra IP u otro navegador) sí es un visitante único más', async () => {
    const start = await counts();
    await visit({ 'user-agent': BROWSER }, '198.51.100.9');
    await visit({ 'user-agent': OTHER });
    const end = await counts();
    assert.equal(end.v - start.v, 2);
    assert.equal(end.u - start.u, 2);
  });

  test('no cuenta robots, HEAD, "No rastrear", Global Privacy Control ni peticiones sin navegador', async () => {
    const start = await counts();
    for (const ua of ['Googlebot/2.1 (+http://www.google.com/bot.html)', 'LinkedInBot/1.0', 'curl/8.4.0', 'python-requests/2.31', 'Mozilla/5.0 (compatible; UptimeRobot/2.0)']) {
      await visit({ 'user-agent': ua });
    }
    await visit({ 'user-agent': '' }); // el inyector añade un user-agent por defecto: se vacía a propósito
    await visit({ 'user-agent': BROWSER, dnt: '1' });
    await visit({ 'user-agent': BROWSER, 'sec-gpc': '1' });
    await visit({ 'user-agent': BROWSER }, '203.0.113.50', 'HEAD');
    assert.deepEqual(await counts(), start);
  });

  test('iniciar sesión deja una marca para que las visitas del administrador no cuenten', async () => {
    const jar = new Jar();
    const res = await login(jar);
    const notrack = ([] as string[]).concat(res.headers['set-cookie'] as string | string[]).find((c) => c.startsWith('notrack='))!;
    assert.ok(notrack, 'debe existir la cookie notrack');
    assert.match(notrack, /HttpOnly/i);
    assert.match(notrack, /Path=\//i);
    const start = await counts();
    await visit({ 'user-agent': BROWSER, cookie: jar.header() }, '203.0.113.99');
    assert.deepEqual(await counts(), start);
  });

  test('guarda solo un hash, nunca la IP ni el navegador', async () => {
    await visit({ 'user-agent': BROWSER }, '192.0.2.123');
    const { rows } = await pool.query('select hash from daily_visitors');
    assert.ok(rows.length > 0);
    for (const r of rows) {
      assert.match(r.hash, /^[0-9a-f]{64}$/);
      assert.equal(r.hash.includes('192.0.2.123'), false);
    }
    const cols = (await pool.query(`select column_name from information_schema.columns where table_name in ('page_views','daily_visitors','daily_salts')`)).rows.map((r) => r.column_name);
    assert.equal(cols.some((c: string) => /ip|agent|address/i.test(c)), false, `columnas: ${cols.join(', ')}`);
  });

  test('el pie muestra el total y se puede ocultar', async () => {
    const total = (await counts()).v;
    const shown = (await app.inject({ method: 'GET', url: '/', headers: { 'user-agent': '' } })).body; // no cuenta
    const label = /class="views">[\s\S]*?<\/svg>([^<]+)</.exec(shown)?.[1] ?? '';
    assert.match(label, /visualizaci(ón|ones)$/);
    assert.equal(Number(label.replace(/[^\d]/g, '')), total);

    await pool.query('update profile set show_view_count = false');
    (await import('../src/content.js')).invalidateContent();
    assert.doesNotMatch((await app.inject({ method: 'GET', url: '/' })).body, /class="views"/);

    await pool.query('update profile set show_view_count = true');
    (await import('../src/content.js')).invalidateContent();
  });

  test('el interruptor del perfil funciona desde el panel', async () => {
    const jar = new Jar();
    await login(jar);
    const csrf = await sessionCsrf(jar);
    const p = (await pool.query('select * from profile')).rows[0];
    const base = {
      _csrf: csrf, first_names: p.first_names, last_names: p.last_names, display_name: p.display_name,
      site_title: p.site_title, headline: p.headline, intro: p.intro.join('\n\n'), location: p.location,
      cta_label: p.cta_label, cta_url: p.cta_url, contact_prompt: p.contact_prompt,
      meta_description: p.meta_description, og_description: p.og_description,
    };
    // Sin la casilla marcada => oculto.
    assert.equal((await request(jar, 'POST', '/admin/profile', base)).statusCode, 302);
    assert.doesNotMatch((await app.inject({ method: 'GET', url: '/' })).body, /class="views"/);
    // Con la casilla => visible.
    assert.equal((await request(jar, 'POST', '/admin/profile', { ...base, show_view_count: '1' })).statusCode, 302);
    assert.match((await app.inject({ method: 'GET', url: '/' })).body, /class="views"/);
  });

  test('el panel muestra las estadísticas y las barras usan <progress> (compatible con la CSP)', async () => {
    const jar = new Jar();
    await login(jar);
    const html = (await request(jar, 'GET', '/admin')).body;
    assert.match(html, /Visitas a la portada/);
    assert.match(html, /Visualizaciones en total/);
    assert.equal((html.match(/<progress /g) ?? []).length, 14);
    assert.equal(/\sstyle="/.test(html), false);
  });

  test('purga los datos de identificación de hace más de 2 días', async () => {
    await pool.query(`insert into daily_visitors (day, hash) values (current_date - 5, 'viejo'), (current_date - 1, 'ayer')`);
    await pool.query(`insert into daily_salts (day, salt) values (current_date - 5, 'sal-vieja')`);
    const fresh = new (await import('../src/visits.js')).ViewCounter(pool, 'America/Hermosillo');
    fresh.record({ ip: '203.0.113.200', userAgent: BROWSER }); // primera visita de este contador: crea la sal y purga
    await fresh.flush();
    const hashes = (await pool.query(`select hash from daily_visitors where hash in ('viejo','ayer')`)).rows.map((r) => r.hash);
    assert.deepEqual(hashes.sort(), ['ayer']);
    assert.equal((await pool.query(`select count(*)::int n from daily_salts where salt = 'sal-vieja'`)).rows[0].n, 0);
  });
});

describe('integridad de la base de datos', () => {
  class Undo extends Error {}

  /**
   * Ejecuta `sql` dentro de una transacción que SIEMPRE se revierte, para no ensuciar los datos.
   * Devuelve 'ok' si la sentencia fue aceptada, o el código de error de PostgreSQL si la rechazó.
   */
  const codeOf = async (sql: string, params: unknown[] = []): Promise<string> => {
    try {
      await withTx(pool, async (c) => {
        await c.query(sql, params);
        throw new Undo();
      });
      return 'ok';
    } catch (err) {
      return err instanceof Undo ? 'ok' : ((err as { code?: string }).code ?? String(err));
    }
  };

  const CHECK = '23514';
  const FK = '23503';        // llave foránea (también al cambiar una llave referenciada)
  // Un borrado bloqueado por `on delete restrict` responde 23503 en PostgreSQL real y 23001 en PGlite.
  const IN_USE = ['23503', '23001'];
  let workId = 0;
  let teachingId = 0;
  let groupId = 0;

  before(async () => {
    workId = (await pool.query(`select id from experiences where kind = 'work' limit 1`)).rows[0].id;
    teachingId = (await pool.query(`select id from experiences where kind = 'teaching' limit 1`)).rows[0].id;
    groupId = (await pool.query('select id from certification_groups limit 1')).rows[0].id;
  });

  test('en una base nueva todas las restricciones quedan validadas', async () => {
    const { rows } = await pool.query(`select conname from pg_constraint where not convalidated`);
    assert.deepEqual(rows, []);
  });

  test('toda tabla tiene Row Level Security activada (una tabla nueva sin ella queda expuesta en Supabase)', async () => {
    const { rows } = await pool.query(
      `select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind = 'r' and not c.relrowsecurity order by 1`);
    assert.deepEqual(rows.map((r) => r.relname), [],
      'Estas tablas no tienen RLS. En Supabase las expone su API de datos a quien tenga la clave pública. Añade ' +
      '`alter table <tabla> enable row level security;` en la migración que las crea (y agrégalas a la lista de 006 si ya se desplegaron).');
  });

  test('los periodos no pueden terminar antes de empezar (y un mismo día sí vale)', async () => {
    const exp = `insert into experiences (kind, company, role, start_date, end_date) values ('work', 'X', 'Y', $1, $2)`;
    assert.equal(await codeOf(exp, ['2024-05-01', '2024-01-01']), CHECK);
    assert.equal(await codeOf(exp, ['2024-05-01', '2024-05-01']), 'ok');
    assert.equal(await codeOf(exp, ['2024-05-01', null]), 'ok');
    assert.equal(await codeOf(
      `insert into projects (experience_id, kind, title, start_date, end_date) values ($1, 'work', 'P', '2024-05-01', '2024-01-01')`, [workId]), CHECK);
    assert.equal(await codeOf(
      `insert into courses (experience_id, subject, start_date, end_date) values ($1, 'C', '2024-05-01', '2024-01-01')`, [teachingId]), CHECK);
  });

  test('los textos obligatorios no pueden estar vacíos ni en blanco', async () => {
    assert.equal(await codeOf(`insert into projects (experience_id, kind, title, start_date) values ($1, 'work', '   ', '2024-01-01')`, [workId]), CHECK);
    assert.equal(await codeOf(`update profile set headline = '  '`), CHECK);
    assert.equal(await codeOf(`insert into technologies (name) values ('  ')`), CHECK);
    assert.equal(await codeOf(`insert into project_highlights (project_id, body) select id, ' ' from projects limit 1`), CHECK);
    assert.equal(await codeOf(`update certifications set name = '' where id = (select min(id) from certifications)`), CHECK);
  });

  test('los enlaces solo admiten https, http, mailto y tel, y sin espacios (defensa en profundidad contra javascript:)', async () => {
    const bad = ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', 'ftp://a.com', '//a.com', 'a.com', 'https://a.com/x y'];
    const cert = `update certifications set url = $1 where id = (select min(id) from certifications)`;
    const link = `insert into contact_links (label, url) values ('L', $1)`;
    const cta = `update profile set cta_url = $1`;
    for (const url of bad) {
      assert.equal(await codeOf(cert, [url]), CHECK, `certifications: ${url}`);
      assert.equal(await codeOf(link, [url]), CHECK, `contact_links: ${url}`);
      assert.equal(await codeOf(cta, [url]), CHECK, `profile.cta_url: ${url}`);
    }
    for (const url of ['https://a.com/x', 'http://a.com', 'mailto:a@b.co', 'tel:+526421234567']) {
      assert.equal(await codeOf(link, [url]), 'ok', url);
    }
    assert.equal(await codeOf(cta, ['']), 'ok', 'el botón principal puede quedar sin enlace');
    assert.equal(await codeOf(cert, [null]), 'ok', 'un certificado puede no tener enlace');
  });

  test('un proyecto, materia o taller solo cuelga de una experiencia de su tipo', async () => {
    assert.equal(await codeOf(`insert into projects (experience_id, kind, title, start_date) values ($1, 'work', 'P', '2024-01-01')`, [teachingId]), FK);
    assert.equal(await codeOf(`insert into projects (experience_id, kind, title, start_date) values ($1, 'work', 'P', '2024-01-01')`, [workId]), 'ok');
    assert.equal(await codeOf(`insert into projects (kind, title, start_date) values ('personal', 'P', '2024-01-01')`), 'ok', 'los propios no necesitan experiencia');
    assert.equal(await codeOf(`insert into courses (experience_id, subject, start_date) values ($1, 'C', '2024-01-01')`, [workId]), FK);
    assert.equal(await codeOf(`insert into courses (experience_id, subject, start_date) values ($1, 'C', '2024-01-01')`, [teachingId]), 'ok');
    assert.equal(await codeOf(`insert into workshops (experience_id, name, period_label, sort_date) values ($1, 'W', 'x', '2024-01-01')`, [workId]), FK);
    assert.equal(await codeOf(`insert into workshops (experience_id, name, period_label, sort_date) values ($1, 'W', 'x', '2024-01-01')`, [teachingId]), 'ok');
  });

  test('no se puede cambiar el tipo de una experiencia que ya tiene elementos asociados', async () => {
    assert.equal(await codeOf(`update experiences set kind = 'teaching' where id = $1`, [workId]), FK);
    assert.equal(await codeOf(`update experiences set kind = 'work' where id = $1`, [teachingId]), FK);
    assert.equal(await codeOf(`update projects set experience_kind = 'teaching' where id = (select min(id) from projects where kind = 'work')`), CHECK,
      'la columna constante tampoco se puede alterar');
  });

  test('borrar un padre con elementos asociados lo impide la base; los hijos del mismo formulario sí caen en cascada', async () => {
    assert.ok(IN_USE.includes(await codeOf(`delete from experiences where id = $1`, [workId])), 'experiencia con proyectos');
    assert.ok(IN_USE.includes(await codeOf(`delete from experiences where id = $1`, [teachingId])), 'experiencia con materias y talleres');
    assert.ok(IN_USE.includes(await codeOf(`delete from certification_groups where id = $1`, [groupId])), 'grupo con certificaciones');
    assert.equal(await codeOf(`delete from projects where id = (select min(id) from projects)`), 'ok', 'un proyecto y sus puntos');
    assert.equal(await codeOf(`delete from tool_groups where id = (select min(id) from tool_groups)`), 'ok', 'un grupo y sus herramientas');
  });

  test('el contador no admite cifras imposibles', async () => {
    assert.equal(await codeOf(`insert into page_views (day, views, visitors) values ('2001-01-01', 1, 2)`), CHECK);
    assert.equal(await codeOf(`insert into page_views (day, views, visitors) values ('2001-01-01', -1, 0)`), CHECK);
    assert.equal(await codeOf(`insert into page_views (day, views, visitors) values ('2001-01-01', 5, 3)`), 'ok');
  });

  test('las llaves foráneas tienen índice y las tablas están documentadas', async () => {
    const names = (await pool.query(`select indexname from pg_indexes where schemaname = 'public'`)).rows.map((r) => r.indexname);
    for (const idx of ['courses_experience_idx', 'workshops_experience_idx', 'sessions_user_idx', 'tool_group_items_tech_idx',
      'project_technologies_tech_idx', 'project_highlights_idx', 'certifications_group_idx', 'projects_experience_idx']) {
      assert.ok(names.includes(idx), `falta el índice ${idx}`);
    }
    const doc = (await pool.query(`select obj_description('public.experiences'::regclass) as d`)).rows[0].d;
    assert.match(doc, /Empleos/);
  });
});

describe('panel: reglas de la base', () => {
  const jar = new Jar();
  let csrf = '';
  let workId = 0;
  let teachingId = 0;

  before(async () => {
    await login(jar);
    csrf = await sessionCsrf(jar);
    workId = (await pool.query(`select id from experiences where kind = 'work' limit 1`)).rows[0].id;
    teachingId = (await pool.query(`select id from experiences where kind = 'teaching' limit 1`)).rows[0].id;
  });

  const selectOf = (html: string, id: string): string => new RegExp(`<select id="${id}"[\\s\\S]*?</select>`).exec(html)?.[0] ?? '';

  test('los selectores de experiencia solo ofrecen las del tipo correcto', async () => {
    const project = selectOf((await request(jar, 'GET', '/admin/projects/new')).body, 'f-experience_id');
    assert.match(project, /Acme Software/);
    assert.doesNotMatch(project, /Instituto Ejemplo/);

    for (const slug of ['courses', 'workshops']) {
      const html = selectOf((await request(jar, 'GET', `/admin/${slug}/new`)).body, 'f-experience_id');
      assert.match(html, /Instituto Ejemplo/, slug);
      assert.doesNotMatch(html, /Acme Software/, slug);
    }
  });

  test('enviar a mano una experiencia de otro tipo se rechaza con un mensaje claro', async () => {
    const res = await request(jar, 'POST', '/admin/projects', {
      _csrf: csrf, kind: 'work', experience_id: String(teachingId), title: 'Mal enlazado', start_date: '2024-01-01', position: '0',
    });
    assert.equal(res.statusCode, 422);
    assert.match(res.body, /Elige una opción de la lista/);
    assert.equal((await pool.query(`select count(*)::int n from projects where title = 'Mal enlazado'`)).rows[0].n, 0);
  });

  test('eliminar algo con elementos asociados no borra nada y explica por qué', async () => {
    const before = (await pool.query('select count(*)::int n from projects')).rows[0].n;
    const res = await request(jar, 'POST', `/admin/experiences/${workId}/delete`, { _csrf: csrf });
    assert.equal(res.statusCode, 302);
    assert.equal(res.headers.location, `/admin/experiences/${workId}?err=in-use`);

    const page = await request(jar, 'GET', String(res.headers.location));
    assert.equal(page.statusCode, 200);
    assert.match(page.body, /class="flash flash-error" role="alert">No se puede eliminar porque se está usando o tiene elementos asociados/);
    assert.equal((await pool.query('select count(*)::int n from projects')).rows[0].n, before, 'no se perdió ningún proyecto');

    const certsBefore = (await pool.query('select count(*)::int n from certifications')).rows[0].n;
    const group = (await pool.query('select id from certification_groups limit 1')).rows[0].id;
    const g = await request(jar, 'POST', `/admin/certification-groups/${group}/delete`, { _csrf: csrf });
    assert.equal(g.headers.location, `/admin/certification-groups/${group}?err=in-use`);
    assert.equal((await pool.query('select count(*)::int n from certifications')).rows[0].n, certsBefore, 'ningún certificado se perdió');
  });

  test('una experiencia sin nada asociado sí se puede eliminar', async () => {
    const created = await request(jar, 'POST', '/admin/experiences', {
      _csrf: csrf, kind: 'work', company: 'Empresa vacía', role: 'Rol', location: '', start_date: '2020-01-01', end_date: '', position: '9',
    });
    assert.equal(created.statusCode, 302, created.body);
    const id = Number(/\/admin\/experiences\/(\d+)/.exec(String(created.headers.location))?.[1]);
    const res = await request(jar, 'POST', `/admin/experiences/${id}/delete`, { _csrf: csrf });
    assert.equal(res.headers.location, '/admin/experiences?ok=deleted');
    assert.equal((await pool.query('select count(*)::int n from experiences where id = $1', [id])).rows[0].n, 0);
  });

  test('cambiar el tipo de una experiencia con elementos asociados se rechaza con un mensaje claro', async () => {
    const e = (await pool.query('select * from experiences where id = $1', [workId])).rows[0];
    const res = await request(jar, 'POST', `/admin/experiences/${workId}`, {
      _csrf: csrf, kind: 'teaching', company: e.company, role: e.role, location: e.location,
      start_date: e.start_date, end_date: '', show_since: '1', position: String(e.position),
    });
    assert.equal(res.statusCode, 422);
    assert.match(res.body, /dejaría elementos asociados sin una experiencia válida/);
    assert.equal((await pool.query('select kind from experiences where id = $1', [workId])).rows[0].kind, 'work');
  });

  test('el formulario conserva lo escrito y la conexión sigue sana tras un rechazo de la base', async () => {
    // Tras un error de SQL la siguiente petición debe funcionar sin ECONNRESET (van dentro de una transacción).
    assert.equal((await request(jar, 'GET', '/admin/projects')).statusCode, 200);
    assert.equal((await app.inject({ method: 'GET', url: '/healthz' })).statusCode, 200);
  });
});

describe('el catálogo de tecnologías', () => {
  class Undo extends Error {}
  const UNIQUE = '23505';
  const CHECK = '23514';
  const IN_USE = ['23503', '23001']; // 23503 en PostgreSQL real, 23001 en PGlite

  /** Ejecuta `sql` en una transacción que siempre se revierte; devuelve 'ok' o el código de error de PostgreSQL. */
  const codeOf = async (sql: string, params: unknown[] = []): Promise<string> => {
    try {
      await withTx(pool, async (c) => { await c.query(sql, params); throw new Undo(); });
      return 'ok';
    } catch (err) {
      return err instanceof Undo ? 'ok' : ((err as { code?: string }).code ?? String(err));
    }
  };

  const seed = loadSeedContent(EXAMPLE_SEED_FILE);
  const allProjects: ProjectData[] = [...seed.experiences.flatMap((e) => e.projects), ...seed.personal_projects];
  const escapeHtml = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  test('la siembra crea el catálogo sin duplicados, con las herramientas y las tecnologías de los proyectos', async () => {
    const expected = new Set<string>();
    for (const g of seed.tool_groups) for (const name of g.tools) expected.add(name.toLowerCase());
    for (const p of allProjects) for (const t of p.technologies) expected.add(t.name.toLowerCase());

    const names = (await pool.query('select name from technologies')).rows.map((r) => String(r.name));
    assert.equal(names.length, expected.size, 'una fila por tecnología distinta');
    assert.deepEqual(new Set(names.map((n) => n.toLowerCase())), expected);
    assert.ok(names.includes('Node.js') && names.includes('PostgreSQL'));
  });

  test('cada proyecto tiene sus tecnologías, en su orden y con sus notas', async () => {
    for (const p of allProjects) {
      const { rows } = await pool.query(
        `select t.name, pt.note from project_technologies pt
           join technologies t on t.id = pt.technology_id
           join projects pr on pr.id = pt.project_id
          where pr.title = $1 order by pt.position`, [p.title]);
      assert.deepEqual(rows.map((r) => ({ name: r.name, note: r.note })), p.technologies, p.title);
    }
    assert.equal((await pool.query(`select count(*)::int n from projects where stack <> ''`)).rows[0].n, 0,
      'el texto libre ya no se guarda al sembrar');
  });

  test('las líneas de tecnologías del sitio salen del catálogo', async () => {
    const { body } = await app.inject({ method: 'GET', url: '/' });
    for (const p of allProjects) {
      const line = p.technologies.map((t) => (t.note ? `${t.name} (${t.note})` : t.name)).join(', ') + '.';
      assert.ok(body.includes(`<p class="stack">${escapeHtml(line)}</p>`), `${p.title}: ${line}`);
    }
  });

  test('el nombre es único sin distinguir mayúsculas ni espacios, y no puede estar vacío ni ser larguísimo', async () => {
    assert.equal(await codeOf(`insert into technologies (name) values ('node.js')`), UNIQUE);
    assert.equal(await codeOf(`insert into technologies (name) values ('  NODE.JS  ')`), UNIQUE);
    assert.equal(await codeOf(`insert into technologies (name) values ('')`), CHECK);
    assert.equal(await codeOf(`insert into technologies (name) values ($1)`, ['x'.repeat(81)]), CHECK);
    assert.equal(await codeOf(`insert into technologies (name) values ('Rust')`), 'ok');
  });

  test('una tecnología en uso no se puede borrar; una sin uso, sí', async () => {
    assert.ok(IN_USE.includes(await codeOf(`delete from technologies where name = 'PostgreSQL'`)), 'la usan proyectos y herramientas');
    assert.equal(await codeOf(`insert into technologies (name) values ('Sin uso'); delete from technologies where name = 'Sin uso'`), 'ok');
  });

  test('la misma tecnología no puede repetirse en un proyecto ni en un grupo', async () => {
    assert.equal(await codeOf(
      `insert into project_technologies (project_id, technology_id) select project_id, technology_id from project_technologies limit 1`), UNIQUE);
    assert.equal(await codeOf(
      `insert into tool_group_items (group_id, technology_id) select group_id, technology_id from tool_group_items limit 1`), UNIQUE);
    assert.equal(await codeOf(
      `update project_technologies set note = '   ' where id = (select min(id) from project_technologies)`), CHECK, 'una nota en blanco no vale');
  });

  test('al borrar un proyecto o un grupo caen sus enlaces, pero el catálogo queda intacto', async () => {
    const techsBefore = (await pool.query('select count(*)::int n from technologies')).rows[0].n;
    await assert.rejects(
      withTx(pool, async (c) => {
        const pid = (await c.query(`select project_id from project_technologies limit 1`)).rows[0].project_id;
        const gid = (await c.query(`select group_id from tool_group_items limit 1`)).rows[0].group_id;
        await c.query('delete from projects where id = $1', [pid]);
        await c.query('delete from tool_groups where id = $1', [gid]);
        assert.equal((await c.query('select count(*)::int n from project_technologies where project_id = $1', [pid])).rows[0].n, 0);
        assert.equal((await c.query('select count(*)::int n from tool_group_items where group_id = $1', [gid])).rows[0].n, 0);
        assert.equal((await c.query('select count(*)::int n from technologies')).rows[0].n, techsBefore);
        throw new Undo();
      }),
      Undo,
    );
  });
});

describe('panel: catálogo de tecnologías', () => {
  const jar = new Jar();
  let csrf = '';

  before(async () => {
    await login(jar);
    csrf = await sessionCsrf(jar);
  });

  const techId = async (name: string): Promise<number> =>
    (await pool.query('select id from technologies where lower(name) = lower($1)', [name])).rows[0].id;
  const home = async () => (await app.inject({ method: 'GET', url: '/' })).body;
  const idFrom = (res: { headers: Record<string, unknown> }, slug: string): number =>
    Number(new RegExp(`/admin/${slug}/(\\d+)`).exec(String(res.headers.location))?.[1]);

  test('el catálogo se lista con cuántas veces se usa cada tecnología', async () => {
    const html = (await request(jar, 'GET', '/admin/technologies')).body;
    assert.match(html, /Tecnologías/);
    assert.match(html, /En proyectos/);
    const row = /<td data-primary><a[^>]*>PostgreSQL<\/a><\/td>\s*<td>(\d+)<\/td>\s*<td>(\d+)<\/td>/.exec(html);
    assert.ok(row, 'debe aparecer PostgreSQL con sus conteos');
    const seed = loadSeedContent(EXAMPLE_SEED_FILE);
    const projects = [...seed.experiences.flatMap((e) => e.projects), ...seed.personal_projects];
    assert.equal(Number(row[1]), projects.filter((p) => p.technologies.some((t) => t.name === 'PostgreSQL')).length, 'proyectos que la usan');
    assert.equal(Number(row[2]), seed.tool_groups.filter((g) => g.tools.includes('PostgreSQL')).length, 'grupos de herramientas que la incluyen');
  });

  test('crear una tecnología duplicada (aunque cambie la mayúscula) o vacía se rechaza con mensaje claro', async () => {
    const dup = await request(jar, 'POST', '/admin/technologies', { _csrf: csrf, name: 'node.JS' });
    assert.equal(dup.statusCode, 422);
    assert.match(dup.body, /Ya existe una tecnología con ese nombre/);
    assert.equal((await request(jar, 'POST', '/admin/technologies', { _csrf: csrf, name: '   ' })).statusCode, 422);
  });

  test('renombrar una tecnología la actualiza en todo el sitio; borrar una en uso se bloquea', async () => {
    const id = await techId('Express');
    const before = await home();
    assert.match(before, /Node\.js, Express, PostgreSQL, Vue\.js, Docker\./);

    assert.equal((await request(jar, 'POST', `/admin/technologies/${id}`, { _csrf: csrf, name: 'Express.js' })).statusCode, 302);
    const after = await home();
    assert.match(after, /Node\.js, Express\.js, PostgreSQL, Vue\.js, Docker\./, 'en el proyecto');
    assert.match(after, /Go, Inertia\.js, Express\.js\./, 'y en Herramientas');

    const del = await request(jar, 'POST', `/admin/technologies/${id}/delete`, { _csrf: csrf });
    assert.equal(del.headers.location, `/admin/technologies/${id}?err=in-use`);

    await request(jar, 'POST', `/admin/technologies/${id}`, { _csrf: csrf, name: 'Express' }); // se deja como estaba
    assert.match(await home(), /Node\.js, Express, PostgreSQL, Vue\.js, Docker\./);
  });

  test('una tecnología recién creada y sin uso se puede eliminar', async () => {
    const created = await request(jar, 'POST', '/admin/technologies', { _csrf: csrf, name: 'Tecnología de prueba' });
    assert.equal(created.statusCode, 302);
    const id = idFrom(created, 'technologies');
    assert.equal((await request(jar, 'POST', `/admin/technologies/${id}/delete`, { _csrf: csrf })).headers.location, '/admin/technologies?ok=deleted');
  });

  test('los formularios ofrecen el catálogo también en las filas nuevas (la plantilla que clona el navegador)', async () => {
    const html = (await request(jar, 'GET', '/admin/projects/new')).body;
    const template = /<template data-template>([\s\S]*?)<\/template>/.exec(html)?.[1] ?? '';
    assert.match(template, /<select[^>]*name="children\[technologies\]\[__i__\]\[technology_id\]"/);
    assert.match(template, /<option value="\d+">Node\.js<\/option>/);
    assert.match(template, /name="children\[technologies\]\[__i__\]\[note\]"/);
  });

  test('un proyecto con tecnologías del catálogo las muestra con sus notas, en orden y escapadas', async () => {
    const exp = (await pool.query(`select id from experiences where kind = 'work' limit 1`)).rows[0].id;
    const php = await techId('PHP');
    const laravel = await techId('Laravel');
    const created = await request(jar, 'POST', '/admin/projects', {
      _csrf: csrf, kind: 'work', experience_id: String(exp), title: 'Proyecto con catálogo', description: 'x',
      stack: 'TEXTO LIBRE DE RESPALDO', start_date: '2031-01-01', end_date: '', visible: '1', position: '0',
      children: { technologies: {
        0: { technology_id: String(laravel), note: '<b>framework</b>' },
        1: { technology_id: String(php), note: '' },
      } },
    });
    assert.equal(created.statusCode, 302, created.body);
    const id = idFrom(created, 'projects');

    const html = await home();
    assert.ok(html.includes('<p class="stack">Laravel (&lt;b&gt;framework&lt;/b&gt;), PHP.</p>'), 'notas escapadas y orden respetado');
    assert.doesNotMatch(html, /TEXTO LIBRE DE RESPALDO/, 'con catálogo, el texto libre no se usa');

    // Quitar todas sus tecnologías devuelve el texto libre de respaldo.
    const p = (await pool.query('select * from projects where id = $1', [id])).rows[0];
    await request(jar, 'POST', `/admin/projects/${id}`, {
      _csrf: csrf, kind: 'work', experience_id: String(exp), title: p.title, description: p.description,
      stack: p.stack, start_date: p.start_date, end_date: '', visible: '1', position: '0',
    });
    assert.ok((await home()).includes('<p class="stack">TEXTO LIBRE DE RESPALDO</p>'), 'sin catálogo vuelve el texto libre');

    await request(jar, 'POST', `/admin/projects/${id}/delete`, { _csrf: csrf });
  });

  test('repetir una tecnología, o enviar una que no existe, se rechaza con un mensaje claro', async () => {
    const exp = (await pool.query(`select id from experiences where kind = 'work' limit 1`)).rows[0].id;
    const base = { _csrf: csrf, kind: 'work', experience_id: String(exp), title: 'No se guarda', start_date: '2031-01-01', position: '0' };
    const nodeId = await techId('Node.js');

    const dup = await request(jar, 'POST', '/admin/projects', { ...base, children: { technologies: { 0: { technology_id: String(nodeId) }, 1: { technology_id: String(nodeId) } } } });
    assert.equal(dup.statusCode, 422);
    assert.match(dup.body, /«Node\.js» está repetida/);

    const ghost = await request(jar, 'POST', '/admin/projects', { ...base, children: { technologies: { 0: { technology_id: '99999' } } } });
    assert.equal(ghost.statusCode, 422);
    assert.match(ghost.body, /Elige una opción de la lista/);
    assert.equal((await pool.query(`select count(*)::int n from projects where title = 'No se guarda'`)).rows[0].n, 0);
  });

  test('los grupos de Herramientas se editan eligiendo del catálogo', async () => {
    const created = await request(jar, 'POST', '/admin/tools', {
      _csrf: csrf, label: 'Grupo de prueba', position: '9',
      children: { tools: { 0: { technology_id: String(await techId('Go')) }, 1: { technology_id: String(await techId('Docker')) } } },
    });
    assert.equal(created.statusCode, 302, created.body);
    const id = idFrom(created, 'tools');
    assert.match(await home(), /<dt>Grupo de prueba<\/dt>\s*<dd>Go, Docker\.<\/dd>/);

    const dup = await request(jar, 'POST', `/admin/tools/${id}`, {
      _csrf: csrf, label: 'Grupo de prueba', position: '9',
      children: { tools: { 0: { technology_id: String(await techId('Go')) }, 1: { technology_id: String(await techId('go')) } } },
    });
    assert.equal(dup.statusCode, 422);
    assert.match(dup.body, /está repetida/);

    await request(jar, 'POST', `/admin/tools/${id}/delete`, { _csrf: csrf });
    assert.doesNotMatch(await home(), /Grupo de prueba/);
    assert.equal((await pool.query(`select count(*)::int n from technologies where name = 'Go'`)).rows[0].n, 1, 'el catálogo no pierde la tecnología');
  });
});

describe('panel: importar y exportar', () => {
  const jar = new Jar();
  let csrf = '';

  before(async () => {
    await login(jar);
    csrf = await sessionCsrf(jar);
  });

  const exportText = async (): Promise<string> => {
    const res = await request(jar, 'GET', '/admin/data/export');
    assert.equal(res.statusCode, 200);
    return res.body;
  };
  const importText = (data: string, extra: Record<string, unknown> = {}) =>
    request(jar, 'POST', '/admin/data/import', { _csrf: csrf, confirm: '1', data, ...extra });
  // Sin user-agent el contador no lo toma por una persona: estas lecturas no alteran las visitas que se comprueban abajo.
  const home = async () => (await app.inject({ method: 'GET', url: '/', headers: { 'user-agent': '' } })).body;
  const count = async (table: string): Promise<number> =>
    (await pool.query(`select count(*)::int n from ${table}`)).rows[0].n;

  test('cobertura: cada columna de cada tabla de contenido está en el formato o se deriva a propósito', async () => {
    // Si añades una columna o una tabla editable, esta prueba falla hasta que decidas qué hace el formato JSON con ella
    // (content-data.ts y content-io.ts) y la anotes aquí. Sin esto, exportar/importar perdería el dato en silencio.
    const derived = ['id', 'position', 'created_at', 'updated_at', 'experience_kind']; // las genera la base o salen del orden
    const covered: Record<string, string[]> = {
      profile: ['first_names', 'last_names', 'display_name', 'site_title', 'headline', 'location', 'intro', 'cta_label', 'cta_url',
        'contact_prompt', 'meta_description', 'og_description', 'show_view_count'],
      contact_links: ['label', 'url'],
      now_items: ['since_label', 'title', 'body', 'visible'],
      tool_groups: ['label', 'emphasis'],
      technologies: ['name'],
      tool_group_items: ['group_id', 'technology_id'],
      experiences: ['kind', 'company', 'article', 'role', 'location', 'start_date', 'end_date', 'show_since', 'workshops_title'],
      projects: ['experience_id', 'kind', 'title', 'description', 'stack', 'start_date', 'end_date', 'period_label', 'visible'],
      project_highlights: ['project_id', 'label', 'body'],
      project_technologies: ['project_id', 'technology_id', 'note'],
      courses: ['experience_id', 'subject', 'start_date', 'end_date'],
      workshops: ['experience_id', 'name', 'period_label', 'sort_date'],
      education: ['title', 'institution', 'period_label'],
      certification_groups: ['title'],
      certifications: ['group_id', 'name', 'issuer', 'year', 'note', 'url'],
    };
    assert.deepEqual(Object.keys(covered).sort(), [...CONTENT_TABLES].sort(), 'las tablas de contenido cambiaron');
    for (const table of CONTENT_TABLES) {
      const cols = (await pool.query<{ column_name: string }>(
        `select column_name from information_schema.columns where table_schema = 'public' and table_name = $1`, [table],
      )).rows.map((r) => r.column_name);
      const known = new Set([...covered[table]!, ...derived]);
      const unknown = cols.filter((c) => !known.has(c));
      assert.deepEqual(unknown, [], `${table}: columnas que el formato JSON no contempla (content-data.ts / content-io.ts)`);
    }
  });

  test('exige sesión: sin ella ni se descarga ni se importa', async () => {
    const anon = new Jar();
    const get = await request(anon, 'GET', '/admin/data/export');
    assert.equal(get.statusCode, 302);
    assert.match(String(get.headers.location), /\/admin\/login/);
    const post = await request(anon, 'POST', '/admin/data/import', { data: '{}', confirm: '1' });
    assert.equal(post.statusCode, 302);
  });

  test('la pantalla explica qué hace y aparece en la navegación', async () => {
    const html = (await request(jar, 'GET', '/admin/data')).body;
    assert.match(html, /Importar y exportar/);
    assert.match(html, /href="\/admin\/data\/export"/);
    assert.match(html, /Reemplaza <strong>todo<\/strong>/);
    assert.match((await request(jar, 'GET', '/admin')).body, /href="\/admin\/data"/);
  });

  test('exporta un JSON descargable, sin caché y sin datos de acceso', async () => {
    const res = await request(jar, 'GET', '/admin/data/export');
    assert.match(String(res.headers['content-type']), /application\/json/);
    assert.match(String(res.headers['content-disposition']), /^attachment; filename="about-me-\d{4}-\d{2}-\d{2}\.json"$/);
    assert.equal(res.headers['cache-control'], 'no-store');
    const data = JSON.parse(res.body) as Record<string, unknown>;
    assert.equal(data.format, 'about-me-content');
    assert.doesNotMatch(res.body, /password|hash|csrf|token|admin_users|page_views/i);
  });

  test('importar lo exportado deja el sitio y el contenido idénticos', async () => {
    const before = await exportText();
    const siteBefore = mainOf(await home());
    const res = await importText(before);
    assert.equal(res.statusCode, 302, res.body);
    assert.match(String(res.headers.location), /ok=imported/);
    assert.equal(await exportText(), before, 'ida y vuelta sin pérdidas');
    assert.equal(mainOf(await home()), siteBefore, 'el sitio se ve igual');
    assert.match((await request(jar, 'GET', '/admin/data?ok=imported')).body, /Se importó el contenido/);
  });

  test('reemplaza el contenido, invalida la caché y no toca usuarios ni visitas', async () => {
    const original = await exportText();
    const users = await count('admin_users');
    const views = (await pool.query('select coalesce(sum(views),0)::int n from page_views')).rows[0].n;
    try {
      const data = JSON.parse(original) as Record<string, any>;
      data.profile.headline = 'Titular importado por la prueba.';
      data.personal_projects.push({
        title: 'Proyecto importado', description: 'Llegó por JSON.', start_date: '2024-05-01', end_date: null,
        technologies: [{ name: 'node.js' }, { name: 'Zig', note: 'nueva' }],
      });
      data.now_items = [{ since_label: 'Desde hoy', title: 'Probando', body: 'la importación.' }];

      await home(); // calienta la caché: el cambio debe verse sin esperar los 60 s
      const res = await importText(JSON.stringify(data));
      assert.equal(res.statusCode, 302, res.body);

      const html = await home();
      assert.match(html, /Titular importado por la prueba\./);
      assert.match(html, /Proyecto importado/);
      assert.match(html, /<p class="stack">Node\.js, Zig \(nueva\)\.<\/p>/, 'reutiliza "Node.js" del catálogo aunque llegue en minúsculas');
      assert.equal((await pool.query(`select count(*)::int n from technologies where lower(name) = 'node.js'`)).rows[0].n, 1);
      assert.equal(await count('now_items'), 1, 'lo que no viene en el archivo desaparece');
      assert.equal(await count('admin_users'), users);
      assert.equal((await pool.query('select coalesce(sum(views),0)::int n from page_views')).rows[0].n, views);
      assert.equal((await request(jar, 'GET', '/admin')).statusCode, 200, 'la sesión sigue viva');
    } finally {
      assert.equal((await importText(original)).statusCode, 302, 'se restaura el contenido para las demás pruebas');
    }
    assert.equal(await exportText(), original);
  });

  test('un archivo inválido no cambia nada y dice dónde está cada problema', async () => {
    const original = await exportText();
    const data = JSON.parse(original) as Record<string, any>;
    data.experiences[0].start_date = '2020-99-99';
    data.contact_links = [{ label: 'x', url: 'javascript:alert(1)' }];
    const res = await importText(JSON.stringify(data));
    assert.equal(res.statusCode, 422);
    assert.match(res.body, /No se importó nada/);
    assert.match(res.body, /experiences\[0\]\.start_date/);
    assert.match(res.body, /contact_links\[0\]\.url/);
    assert.match(res.body, /<textarea[^>]*>[\s\S]*contact_links/, 'conserva el texto para poder corregirlo');
    assert.equal(await exportText(), original, 'la base quedó intacta');

    const broken = await importText('{ esto no es json');
    assert.equal(broken.statusCode, 422);
    assert.match(broken.body, /no es JSON válido/);
    assert.equal(await exportText(), original);
  });

  test('el texto pegado se escapa al mostrarlo de vuelta (no ejecuta nada)', async () => {
    const res = await importText('</textarea><script>alert(1)</script>');
    assert.equal(res.statusCode, 422);
    assert.doesNotMatch(res.body, /<script>alert\(1\)/);
    assert.match(res.body, /&lt;\/textarea&gt;&lt;script&gt;/);
  });

  test('exige confirmar, contenido y el token CSRF', async () => {
    const original = await exportText();
    const noConfirm = await request(jar, 'POST', '/admin/data/import', { _csrf: csrf, data: original });
    assert.equal(noConfirm.statusCode, 422);
    assert.match(noConfirm.body, /Marca la casilla/);
    const empty = await importText('   ');
    assert.equal(empty.statusCode, 422);
    assert.match(empty.body, /Pega el contenido/);
    const noCsrf = await request(jar, 'POST', '/admin/data/import', { confirm: '1', data: original });
    assert.equal(noCsrf.statusCode, 403);
    assert.equal(await exportText(), original);
  });

  test('si la base rechaza algo, revierte todo (nada queda a medias)', async () => {
    const original = await exportText();
    // Pasa el validador pero la base lo rechaza: una restricción que el validador no replica a propósito.
    await pool.query(`alter table education add constraint tmp_test_reject check (institution <> 'RECHAZAR')`);
    try {
      const data = JSON.parse(original) as Record<string, any>;
      data.profile.headline = 'No debe quedar guardado.';
      data.education.push({ title: 'x', institution: 'RECHAZAR', period_label: 'p' });
      const res = await importText(JSON.stringify(data));
      assert.equal(res.statusCode, 422);
      assert.match(res.body, /La base de datos rechazó el contenido \(regla tmp_test_reject\)\. No se cambió nada\./);
    } finally {
      await pool.query('alter table education drop constraint tmp_test_reject');
    }
    assert.equal(await exportText(), original, 'el truncado se revirtió junto con lo demás');
    assert.doesNotMatch(await home(), /No debe quedar guardado/);
  });

  test('sin perfil no hay nada que exportar: avisa en lugar de descargar un archivo roto', async () => {
    const original = await exportText();
    try {
      await pool.query('delete from profile');
      const res = await request(jar, 'GET', '/admin/data/export');
      assert.equal(res.statusCode, 302);
      assert.match(String(res.headers.location), /err=no-content/);
      assert.match((await request(jar, 'GET', '/admin/data?err=no-content')).body, /Todavía no hay perfil/);
    } finally {
      await importText(original);
    }
    assert.equal(await exportText(), original);
  });
});
