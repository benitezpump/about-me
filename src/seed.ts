import { loadSeedContent } from './content-data.js';
import { replaceContent } from './content-io.js';
import type { Pool } from './db.js';

export async function isEmpty(pool: Pool): Promise<boolean> {
  const { rows } = await pool.query<{ n: string }>('select count(*)::text as n from profile');
  return rows[0]?.n === '0';
}

/**
 * Carga el contenido inicial (`db/seed/content.json` si existe; si no, el de ejemplo). Si la base ya tiene perfil no hace
 * nada, salvo con `force` (que borra TODO el contenido editable y lo vuelve a cargar; no toca usuarios, sesiones ni
 * visitas). `file` fija el archivo (lo usan las pruebas).
 */
export async function runSeed(pool: Pool, opts: { force?: boolean; file?: string } = {}): Promise<boolean> {
  if (!opts.force && !(await isEmpty(pool))) return false;
  // Se lee y valida ANTES de abrir la transacción: un archivo roto falla con un mensaje claro y no toca la base.
  await replaceContent(pool, loadSeedContent(opts.file));
  return true;
}
