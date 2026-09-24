import { loadConfig } from '../config.js';
import { createPoolFromConfig } from '../db.js';
import { migrate } from '../migrate.js';

const config = loadConfig();
const pool = createPoolFromConfig(config);
try {
  const applied = await migrate(pool);
  console.log(applied.length ? `Migraciones aplicadas: ${applied.join(', ')}` : 'Base de datos al día.');
} finally {
  await pool.end();
}
