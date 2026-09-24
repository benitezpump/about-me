import { buildApp } from './app.js';
import { ensureAdmin } from './auth.js';
import { loadConfig } from './config.js';
import { createPoolFromConfig } from './db.js';
import { migrate } from './migrate.js';
import path from 'node:path';
import { seedFile } from './content-data.js';
import { isEmpty, runSeed } from './seed.js';

const config = loadConfig();
const pool = createPoolFromConfig(config);
const app = await buildApp({ pool, config });

try {
  if (config.runMigrations) {
    const applied = await migrate(pool);
    if (applied.length) app.log.info({ applied }, 'Migraciones aplicadas');
  }
  if (config.seedOnEmpty && (await isEmpty(pool))) {
    await runSeed(pool);
    app.log.info({ file: path.basename(seedFile()) }, 'Contenido inicial cargado');
  }
  if (config.adminUsername && config.adminPassword) {
    if (await ensureAdmin(pool, config.adminUsername, config.adminPassword)) {
      app.log.info({ username: config.adminUsername }, 'Administrador inicial creado');
    }
  }
  await app.listen({ host: config.host, port: config.port });
} catch (err) {
  app.log.error(err);
  await pool.end().catch(() => undefined);
  process.exit(1);
}

// Cierre ordenado: deja de aceptar peticiones, espera las que están en curso y cierra el pool.
for (const signal of ['SIGINT', 'SIGTERM'] as const) {
  process.on(signal, async () => {
    app.log.info({ signal }, 'Cerrando');
    await app.close();
    await pool.end();
    process.exit(0);
  });
}
