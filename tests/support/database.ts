import { randomBytes } from 'node:crypto';
import net from 'node:net';
import { PGlite } from '@electric-sql/pglite';
import { PGLiteSocketServer } from '@electric-sql/pglite-socket';
import pg from 'pg';

/**
 * Base de datos para las pruebas, con dos modos:
 *
 *  - Sin variables (lo normal en tu equipo): PGlite en el mismo proceso. Rápido y sin instalar nada.
 *  - Con `TEST_DATABASE_URL`: un servidor PostgreSQL REAL (así corre la integración continua). La URL apunta al
 *    SERVIDOR, no a una base concreta: cada archivo de pruebas crea su propia base `about_me_test_<nombre>_<azar>`
 *    y la elimina al terminar. Nunca lee, escribe ni borra otra base del servidor.
 *
 * Ejemplo: TEST_DATABASE_URL=postgres://postgres:postgres@localhost:5432/postgres npm test
 */
export interface TestDatabase {
  kind: 'postgres' | 'pglite';
  url: string;
  /** PGlite comparte un solo backend, así que con él el pool es de 1 conexión; un servidor real admite más. */
  poolMax: number;
  stop(): Promise<void>;
}

const SCRATCH_PREFIX = 'about_me_test_';
const SAFE_NAME = /^about_me_test_[a-z0-9_]+$/;

export async function startTestDatabase(label: string): Promise<TestDatabase> {
  const serverUrl = process.env.TEST_DATABASE_URL;
  return serverUrl ? startPostgres(serverUrl, label) : startPglite();
}

async function startPostgres(serverUrl: string, label: string): Promise<TestDatabase> {
  const name = `${SCRATCH_PREFIX}${label.toLowerCase().replace(/[^a-z0-9]+/g, '_')}_${randomBytes(4).toString('hex')}`;
  if (!SAFE_NAME.test(name)) throw new Error(`Nombre de base de pruebas no válido: ${name}`);

  const admin = new pg.Client({ connectionString: serverUrl });
  await admin.connect();
  await admin.query(`create database "${name}"`);

  const url = new URL(serverUrl);
  url.pathname = `/${name}`;

  return {
    kind: 'postgres',
    url: url.toString(),
    poolMax: 5,
    async stop() {
      // Solo se borra lo que este archivo creó (el nombre lo comprueba SAFE_NAME); `force` cierra conexiones abiertas.
      if (SAFE_NAME.test(name)) await admin.query(`drop database if exists "${name}" with (force)`);
      await admin.end();
    },
  };
}

async function startPglite(): Promise<TestDatabase> {
  const db = await PGlite.create();
  const port = await freePort();
  const socket = new PGLiteSocketServer({ db, port, host: '127.0.0.1' });
  await socket.start();
  return {
    kind: 'pglite',
    url: `postgres://postgres:postgres@127.0.0.1:${port}/postgres`,
    poolMax: 1,
    async stop() {
      await socket.stop();
      await db.close();
    },
  };
}

/** Pide un puerto libre al sistema: evita chocar cuando varios archivos de pruebas corren en paralelo. */
function freePort(): Promise<number> {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address() as net.AddressInfo;
      server.close(() => resolve(port));
    });
  });
}
