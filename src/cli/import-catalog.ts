import { importLegacyStacks } from '../catalog-import.js';
import { loadConfig } from '../config.js';
import { createPoolFromConfig } from '../db.js';
import { migrate } from '../migrate.js';
import { invalidateContent } from '../content.js';

/**
 * Convierte al catálogo de tecnologías los proyectos cuyo texto libre sigue siendo el original.
 * Solo escribe en la base a la que apunta DATABASE_URL: haz un respaldo (`pg_dump`) antes.
 */
const config = loadConfig();
const pool = createPoolFromConfig(config);
try {
  await migrate(pool);
  const report = await importLegacyStacks(pool);
  invalidateContent();

  console.log(report.converted.length
    ? `Convertidos al catálogo (${report.converted.length}):\n  - ${report.converted.join('\n  - ')}`
    : 'No había proyectos por convertir.');
  if (report.skipped.length) {
    console.log(`\nSe dejaron como estaban (${report.skipped.length}):`);
    for (const s of report.skipped) console.log(`  - ${s.title}: ${s.reason}`);
  }
  console.log('\nEl texto libre original se conserva como respaldo. Revisa el resultado en el sitio.');
} finally {
  await pool.end();
}
