import { loadConfig } from '../config.js';
import { createPoolFromConfig } from '../db.js';
import { migrate } from '../migrate.js';
import { runSeed } from '../seed.js';

const force = process.argv.includes('--force');
const config = loadConfig();
const pool = createPoolFromConfig(config);
try {
  await migrate(pool);
  const done = await runSeed(pool, { force });
  console.log(
    done
      ? force
        ? 'Contenido reemplazado con los datos iniciales.'
        : 'Contenido inicial cargado.'
      : 'La base ya tiene contenido; no se cambió nada (usa --force para reemplazarlo).',
  );
} finally {
  await pool.end();
}
