import { withTx } from './db.js';
import type { Pool } from './db.js';
import { loadSeedContent } from './content-data.js';
import type { ContentData, ProjectData } from './content-data.js';
import { ensureTechnology } from './content-io.js';

export interface CatalogImportReport {
  /** Proyectos convertidos al catálogo. */
  converted: string[];
  /** Proyectos que se dejaron como estaban, con el motivo. */
  skipped: { title: string; reason: string }[];
}

/**
 * Convierte al catálogo los proyectos cuyo texto libre de tecnologías sigue siendo EXACTAMENTE el original.
 *
 * No intenta interpretar prosa ("PHP con Symfony2", "Python y Django en backend y frontend"): eso sería adivinar
 * sobre datos que la persona pudo haber editado. En cambio, si el texto coincide letra por letra con el que traía
 * el sitio, se sabe que nadie lo tocó y se puede reemplazar sin riesgo por la lista estructurada equivalente.
 *
 * Es segura de repetir: no toca proyectos que ya tienen tecnologías. Conserva el texto libre como respaldo.
 */
export async function importLegacyStacks(pool: Pool, content: ContentData = loadSeedContent()): Promise<CatalogImportReport> {
  const report: CatalogImportReport = { converted: [], skipped: [] };
  // Solo sirven los proyectos del contenido inicial que conservan su texto libre original (`legacy_stack`).
  const known: ProjectData[] = [...content.experiences.flatMap((e) => e.projects), ...content.personal_projects]
    .filter((p) => p.legacy_stack !== undefined);

  await withTx(pool, async (c) => {
    const catalog = new Map<string, number>();

    for (const proj of known) {
      const { rows } = await c.query<{ id: number; stack: string; linked: number }>(
        `select p.id, p.stack, (select count(*)::int from project_technologies pt where pt.project_id = p.id) as linked
           from projects p where p.title = $1`, [proj.title]);
      const row = rows[0];
      if (!row) continue; // el proyecto no existe en esta base: no es ruido que valga la pena reportar

      if (row.linked > 0) {
        report.skipped.push({ title: proj.title, reason: 'ya tiene tecnologías del catálogo' });
      } else if (row.stack.trim() !== proj.legacy_stack) {
        report.skipped.push({
          title: proj.title,
          reason: row.stack.trim() === '' ? 'no tiene texto de tecnologías' : 'el texto fue editado a mano; elige sus tecnologías en el panel',
        });
      } else {
        for (const [i, tech] of proj.technologies.entries()) {
          const technologyId = await ensureTechnology(c, catalog, tech.name);
          await c.query('insert into project_technologies (project_id, technology_id, note, position) values ($1,$2,$3,$4)',
            [row.id, technologyId, tech.note, i]);
        }
        report.converted.push(proj.title);
      }
    }
  });
  return report;
}
