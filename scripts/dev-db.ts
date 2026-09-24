/**
 * Base de datos PostgreSQL de desarrollo SIN Docker.
 *
 * Usa PGlite (el motor real de PostgreSQL compilado a WASM) y lo expone por el protocolo
 * de PostgreSQL, así que la app se conecta con el mismo driver `pg` que en producción.
 * Los datos se guardan en `.pglite/`. Para producción usa el PostgreSQL de docker-compose.yml.
 *
 *   npm run dev:db
 *   DATABASE_URL=postgres://postgres:postgres@127.0.0.1:5433/postgres  DB_POOL_MAX=1
 */
import { PGlite } from '@electric-sql/pglite';
import { PGLiteSocketServer } from '@electric-sql/pglite-socket';

const port = Number(process.env.DEV_DB_PORT ?? 5433);
const dataDir = process.env.DEV_DB_DIR ?? '.pglite';

const db = await PGlite.create(dataDir);
const server = new PGLiteSocketServer({ db, port, host: '127.0.0.1' });
await server.start();

console.log(`PostgreSQL de desarrollo (PGlite) escuchando en 127.0.0.1:${port}`);
console.log(`DATABASE_URL=postgres://postgres:postgres@127.0.0.1:${port}/postgres`);
console.log('Usa DB_POOL_MAX=1 en la app: PGlite comparte un solo backend entre conexiones.');

const stop = async () => {
  await server.stop();
  await db.close();
  process.exit(0);
};
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
