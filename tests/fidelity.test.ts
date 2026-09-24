import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { after, before, describe, test } from 'node:test';
import { buildApp } from '../src/app.js';
import { ROOT, type Config } from '../src/config.js';
import { SEED_FILE } from '../src/content-data.js';
import { createPool, type Pool } from '../src/db.js';
import { migrate } from '../src/migrate.js';
import { runSeed } from '../src/seed.js';
import { startTestDatabase, type TestDatabase } from './support/database.js';

/**
 * Fidelidad con el sitio original. Compara el sitio generado desde tu contenido REAL (`db/seed/content.json`) con el
 * HTML de origen (`legacy/index.static.html`). Ninguno de los dos archivos se sube al repositorio (contienen datos
 * personales; ver .gitignore), así que esta prueba solo corre en tu máquina y se omite donde no están, por ejemplo en la
 * integración continua. El resto de la suite usa contenido de ejemplo.
 */
const LEGACY = path.join(ROOT, 'legacy', 'index.static.html');
const available = fs.existsSync(LEGACY) && fs.existsSync(SEED_FILE);

const textOf = (html: string): string =>
  html
    .replace(/<script[\s\S]*?<\/script>/g, '')
    .replace(/<[^>]+>/g, '')
    .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'")
    .replace(/\s+/g, '');
const mainOf = (html: string): string => /<main[\s\S]*<\/main>/.exec(html)?.[0] ?? '';

describe('fidelidad con el HTML original (solo con tus archivos locales)', { skip: available ? false : 'faltan legacy/ o db/seed/content.json (no se suben al repositorio)' }, () => {
  let testDb: TestDatabase;
  let pool: Pool;
  let app: Awaited<ReturnType<typeof buildApp>>;

  before(async () => {
    testDb = await startTestDatabase('fidelity');
    const config: Config = {
      env: 'test', host: '127.0.0.1', port: 0, databaseUrl: testDb.url, databaseSslCa: undefined,
      poolMax: testDb.poolMax, cookieSecure: false, trustProxy: false, runMigrations: true, seedOnEmpty: false,
      statsTimezone: 'America/Hermosillo', loginMaxAttempts: 1000, adminUsername: undefined, adminPassword: undefined,
    };
    pool = createPool(config.databaseUrl, testDb.poolMax);
    await migrate(pool);
    await runSeed(pool, { file: SEED_FILE });
    app = await buildApp({ pool, config });
  });

  after(async () => {
    await app.close();
    await pool.end();
    await testDb.stop();
  });

  test('renderiza el mismo contenido que el index.html original', async () => {
    const legacy = fs.readFileSync(LEGACY, 'utf8');
    const res = await app.inject({ method: 'GET', url: '/' });
    // Las líneas de tecnologías ya no son texto libre sino que salen del catálogo (y pierden algo de prosa, p. ej.
    // "en backend"): se verifican con las pruebas del catálogo. Todo lo demás debe ser idéntico.
    const stripStack = (html: string) => html.replace(/<p class="stack">[\s\S]*?<\/p>/g, '');
    const expected = textOf(stripStack(mainOf(legacy)));
    const actual = textOf(stripStack(mainOf(res.body)));
    assert.ok(expected.length > 5000, 'el HTML original debería tener contenido');
    assert.equal(actual, expected);
  });
});
