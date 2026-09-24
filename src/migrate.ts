import fs from 'node:fs/promises';
import path from 'node:path';
import { ROOT } from './config.js';
import type { Pool } from './db.js';

const MIGRATIONS_DIR = path.join(ROOT, 'db', 'migrations');
const LOCK_KEY = 727274;

/**
 * Aplica en orden los archivos `db/migrations/*.sql` que aún no se hayan ejecutado.
 * Cada archivo corre en su propia transacción y queda registrado en `schema_migrations`.
 * Devuelve los nombres aplicados en esta ejecución.
 */
export async function migrate(
  pool: Pool,
  dir = MIGRATIONS_DIR,
  log: (message: string) => void = (message) => console.warn(message),
): Promise<string[]> {
  const client = await pool.connect();
  const applied: string[] = [];
  let current = '';
  // Las migraciones avisan con WARNING (p. ej. una restricción que no pudo validarse con datos antiguos).
  const onNotice = (notice: { severity?: string; message?: string }) => {
    if (notice.severity === 'WARNING') log(`[migración ${current}] ${notice.message}`);
  };
  client.on('notice', onNotice);
  try {
    // Evita que dos instancias migren a la vez.
    await client.query('select pg_advisory_lock($1)', [LOCK_KEY]);
    await client.query(`
      create table if not exists schema_migrations (
        name text primary key,
        applied_at timestamptz not null default now()
      )`);
    // Sin políticas, RLS niega todo a los roles de la API de Supabase; el dueño (esta conexión) no está sujeto a ella.
    await client.query('alter table schema_migrations enable row level security');

    const done = new Set(
      (await client.query<{ name: string }>('select name from schema_migrations')).rows.map((r) => r.name),
    );
    const files = (await fs.readdir(dir)).filter((f) => f.endsWith('.sql')).sort();

    for (const file of files) {
      if (done.has(file)) continue;
      const sql = await fs.readFile(path.join(dir, file), 'utf8');
      current = file;
      try {
        await client.query('begin');
        await client.query(sql);
        await client.query('insert into schema_migrations (name) values ($1)', [file]);
        await client.query('commit');
      } catch (err) {
        await client.query('rollback').catch(() => undefined);
        throw new Error(`Falló la migración ${file}: ${(err as Error).message}`);
      }
      applied.push(file);
    }
  } finally {
    client.removeListener('notice', onNotice);
    await client.query('select pg_advisory_unlock($1)', [LOCK_KEY]).catch(() => undefined);
    client.release();
  }
  return applied;
}
