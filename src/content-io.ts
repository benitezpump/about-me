import { withTx } from './db.js';
import type { Client, Pool } from './db.js';
import type { ContentData, ExperienceData, ProjectData } from './content-data.js';

/** Tablas de contenido, hijas primero, para vaciarlas sin violar llaves foráneas. No incluye usuarios, sesiones ni visitas. */
export const CONTENT_TABLES = [
  'project_highlights', 'project_technologies', 'projects', 'courses', 'workshops', 'experiences',
  'tool_group_items', 'tool_groups', 'technologies',
  'now_items', 'education', 'certifications', 'certification_groups', 'contact_links', 'profile',
];

/** Busca una tecnología del catálogo (sin distinguir mayúsculas) y la crea si no existe. */
export async function ensureTechnology(c: Client, cache: Map<string, number>, name: string): Promise<number> {
  const clean = name.trim();
  const key = clean.toLowerCase();
  const cached = cache.get(key);
  if (cached) return cached;

  const found = await c.query<{ id: number }>('select id from technologies where lower(btrim(name)) = $1', [key]);
  const id = found.rows[0]?.id
    ?? (await c.query<{ id: number }>('insert into technologies (name) values ($1) returning id', [clean])).rows[0]!.id;
  cache.set(key, id);
  return id;
}

/**
 * Reemplaza TODO el contenido editable por `data`, en una sola transacción: si algo falla, la base queda como estaba.
 * No toca usuarios, sesiones ni visitas. Quien la llame debe invalidar la caché del sitio (`invalidateContent`).
 */
export async function replaceContent(pool: Pool, data: ContentData): Promise<void> {
  await withTx(pool, async (c) => {
    await c.query(`truncate ${CONTENT_TABLES.join(', ')} restart identity cascade`);
    await insertContent(c, data);
  });
}

async function insertContent(c: Client, data: ContentData): Promise<void> {
  const catalog = new Map<string, number>();
  for (const name of data.technologies) await ensureTechnology(c, catalog, name);

  const p = data.profile;
  await c.query(
    `insert into profile (first_names, last_names, display_name, site_title, headline, location, intro,
                          cta_label, cta_url, contact_prompt, meta_description, og_description, show_view_count)
     values ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13)`,
    [p.first_names, p.last_names, p.display_name, p.site_title, p.headline, p.location, p.intro,
     p.cta_label, p.cta_url, p.contact_prompt, p.meta_description, p.og_description, p.show_view_count],
  );

  for (const [i, l] of data.contact_links.entries()) {
    await c.query('insert into contact_links (label, url, position) values ($1,$2,$3)', [l.label, l.url, i]);
  }
  for (const [i, n] of data.now_items.entries()) {
    await c.query('insert into now_items (since_label, title, body, position, visible) values ($1,$2,$3,$4,$5)',
      [n.since_label, n.title, n.body, i, n.visible]);
  }

  for (const [gi, g] of data.tool_groups.entries()) {
    const { rows } = await c.query<{ id: number }>(
      'insert into tool_groups (label, emphasis, position) values ($1,$2,$3) returning id', [g.label, g.emphasis, gi]);
    for (const [ti, name] of g.tools.entries()) {
      const technologyId = await ensureTechnology(c, catalog, name);
      await c.query('insert into tool_group_items (group_id, technology_id, position) values ($1,$2,$3)',
        [rows[0]!.id, technologyId, ti]);
    }
  }

  for (const [i, e] of data.experiences.entries()) await insertExperience(c, catalog, e, i);
  for (const [i, proj] of data.personal_projects.entries()) await insertProject(c, catalog, proj, 'personal', null, i);

  for (const [i, e] of data.education.entries()) {
    await c.query('insert into education (title, institution, period_label, position) values ($1,$2,$3,$4)',
      [e.title, e.institution, e.period_label, i]);
  }

  for (const [gi, g] of data.certification_groups.entries()) {
    const { rows } = await c.query<{ id: number }>(
      'insert into certification_groups (title, position) values ($1,$2) returning id', [g.title, gi]);
    for (const [i, item] of g.items.entries()) {
      await c.query(
        'insert into certifications (group_id, name, issuer, year, note, url, position) values ($1,$2,$3,$4,$5,$6,$7)',
        [rows[0]!.id, item.name, item.issuer, item.year, item.note, item.url, i]);
    }
  }
}

async function insertExperience(c: Client, catalog: Map<string, number>, e: ExperienceData, position: number): Promise<void> {
  const { rows } = await c.query<{ id: number }>(
    `insert into experiences (kind, company, article, role, location, start_date, end_date, show_since, workshops_title, position)
     values ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) returning id`,
    [e.kind, e.company, e.article, e.role, e.location, e.start_date, e.end_date, e.show_since, e.workshops_title, position]);
  const id = rows[0]!.id;

  for (const [i, proj] of e.projects.entries()) await insertProject(c, catalog, proj, 'work', id, i);
  for (const [i, course] of e.courses.entries()) {
    await c.query('insert into courses (experience_id, subject, start_date, end_date, position) values ($1,$2,$3,$4,$5)',
      [id, course.subject, course.start_date, course.end_date, i]);
  }
  for (const [i, ws] of e.workshops.entries()) {
    await c.query('insert into workshops (experience_id, name, period_label, sort_date, position) values ($1,$2,$3,$4,$5)',
      [id, ws.name, ws.period_label, ws.sort_date, i]);
  }
}

async function insertProject(
  c: Client, catalog: Map<string, number>, proj: ProjectData, kind: 'work' | 'personal',
  experienceId: number | null, position: number,
): Promise<void> {
  const { rows } = await c.query<{ id: number }>(
    `insert into projects (experience_id, kind, title, description, stack, start_date, end_date, period_label, visible, position)
     values ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) returning id`,
    [experienceId, kind, proj.title, proj.description, proj.stack, proj.start_date, proj.end_date, proj.period_label, proj.visible, position]);
  const projectId = rows[0]!.id;

  for (const [i, tech] of proj.technologies.entries()) {
    const technologyId = await ensureTechnology(c, catalog, tech.name);
    await c.query('insert into project_technologies (project_id, technology_id, note, position) values ($1,$2,$3,$4)',
      [projectId, technologyId, tech.note, i]);
  }
  for (const [i, h] of proj.highlights.entries()) {
    await c.query('insert into project_highlights (project_id, label, body, position) values ($1,$2,$3,$4)',
      [projectId, h.label, h.body, i]);
  }
}

// ------------------------------------------------------------------------------------------------ Exportar ----

/** Se lanza cuando no hay perfil: sin él no hay sitio que exportar (y el archivo no se podría importar). */
export class NoContentError extends Error {}

/**
 * Lee todo el contenido editable en el mismo formato que acepta la importación. Las listas salen en su orden
 * (`position`, luego `id`), así que importar el archivo reproduce el sitio tal cual. Usa una lectura consistente
 * (una sola instantánea) para que un cambio en medio de la exportación no mezcle dos versiones.
 */
export async function exportContent(pool: Pool): Promise<ContentData> {
  const client = await pool.connect();
  try {
    await client.query('begin isolation level repeatable read read only');
    const q = async <T extends object>(sql: string): Promise<T[]> => (await client.query<T>(sql)).rows;
    const byParent = <T extends { parent: number }>(rows: T[]): Map<number, T[]> => {
      const map = new Map<number, T[]>();
      for (const r of rows) (map.get(r.parent) ?? map.set(r.parent, []).get(r.parent)!).push(r);
      return map;
    };

    const [profile] = await q<ContentData['profile']>(
      `select first_names, last_names, display_name, site_title, headline, location, intro, cta_label, cta_url,
              contact_prompt, meta_description, og_description, show_view_count from profile where id = 1`);
    if (!profile) throw new NoContentError('No hay perfil.');

    const contact_links = await q<ContentData['contact_links'][number]>('select label, url from contact_links order by position, id');
    const now_items = await q<ContentData['now_items'][number]>('select since_label, title, body, visible from now_items order by position, id');

    const groups = await q<{ id: number; label: string; emphasis: boolean }>('select id, label, emphasis from tool_groups order by position, id');
    const groupTools = byParent(await q<{ parent: number; name: string }>(
      `select i.group_id as parent, t.name from tool_group_items i join technologies t on t.id = i.technology_id order by i.position, i.id`));

    const technologies = (await q<{ name: string }>('select name from technologies order by lower(name), id')).map((r) => r.name);

    const techs = byParent(await q<{ parent: number; name: string; note: string | null }>(
      `select pt.project_id as parent, t.name, pt.note from project_technologies pt
         join technologies t on t.id = pt.technology_id order by pt.position, pt.id`));
    const highlights = byParent(await q<{ parent: number; label: string | null; body: string }>(
      'select project_id as parent, label, body from project_highlights order by position, id'));
    const projectRows = await q<{ id: number; experience_id: number | null; kind: string } & Omit<ProjectData, 'technologies' | 'highlights'>>(
      `select id, experience_id, kind, title, description, stack, start_date, end_date, period_label, visible
         from projects order by position, id`);
    const toProject = (r: (typeof projectRows)[number]): ProjectData => ({
      title: r.title, description: r.description, stack: r.stack, start_date: r.start_date, end_date: r.end_date,
      period_label: r.period_label, visible: r.visible,
      technologies: (techs.get(r.id) ?? []).map((t) => ({ name: t.name, note: t.note })),
      highlights: (highlights.get(r.id) ?? []).map((h) => ({ label: h.label, body: h.body })),
    });

    const courses = byParent(await q<{ parent: number; subject: string; start_date: string; end_date: string | null }>(
      'select experience_id as parent, subject, start_date, end_date from courses order by position, id'));
    const workshops = byParent(await q<{ parent: number; name: string; period_label: string; sort_date: string }>(
      'select experience_id as parent, name, period_label, sort_date from workshops order by position, id'));
    const experiences = (await q<{ id: number } & Omit<ExperienceData, 'projects' | 'courses' | 'workshops'>>(
      `select id, kind, company, article, role, location, start_date, end_date, show_since, workshops_title
         from experiences order by position, id`)).map((e): ExperienceData => ({
      kind: e.kind, company: e.company, article: e.article, role: e.role, location: e.location,
      start_date: e.start_date, end_date: e.end_date, show_since: e.show_since, workshops_title: e.workshops_title,
      projects: projectRows.filter((p) => p.experience_id === e.id).map(toProject),
      courses: (courses.get(e.id) ?? []).map((x) => ({ subject: x.subject, start_date: x.start_date, end_date: x.end_date })),
      workshops: (workshops.get(e.id) ?? []).map((x) => ({ name: x.name, period_label: x.period_label, sort_date: x.sort_date })),
    }));

    const certGroups = await q<{ id: number; title: string }>('select id, title from certification_groups order by position, id');
    const certs = byParent(await q<{ parent: number; name: string; issuer: string | null; year: number; note: string | null; url: string | null }>(
      'select group_id as parent, name, issuer, year, note, url from certifications order by position, id'));

    const education = await q<ContentData['education'][number]>('select title, institution, period_label from education order by position, id');

    await client.query('commit');
    return {
      profile: { ...profile, intro: [...profile.intro] },
      contact_links, now_items,
      tool_groups: groups.map((g) => ({
        label: g.label, emphasis: g.emphasis, tools: (groupTools.get(g.id) ?? []).map((t) => t.name),
      })),
      technologies,
      experiences,
      personal_projects: projectRows.filter((p) => p.kind === 'personal').map(toProject),
      education,
      certification_groups: certGroups.map((g) => ({
        title: g.title,
        items: (certs.get(g.id) ?? []).map((i) => ({ name: i.name, issuer: i.issuer, year: i.year, note: i.note, url: i.url })),
      })),
    };
  } catch (err) {
    await client.query('rollback').catch(() => undefined);
    throw err;
  } finally {
    client.release();
  }
}
