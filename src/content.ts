import type { Pool } from './db.js';
import { buildPeriod, longMonthYear, type Period } from './format.js';

export interface ProfileView {
  firstNames: string;
  lastNames: string;
  displayName: string;
  siteTitle: string;
  headline: string;
  location: string;
  intro: string[];
  ctaLabel: string;
  ctaUrl: string;
  contactPrompt: string;
  metaDescription: string;
  ogDescription: string;
  showViewCount: boolean;
}

export interface ProjectView {
  id: number;
  title: string;
  description: string;
  stack: string;
  period: Period;
  highlights: { label: string | null; body: string }[];
}

export interface ExperienceView {
  id: number;
  company: string;
  article: string | null;
  role: string;
  location: string;
  /** 'marzo de 2015' cuando la experiencia muestra "desde …". */
  since: string | null;
  projects: ProjectView[];
  courses: { subject: string; period: Period }[];
  workshopsTitle: string | null;
  workshops: { name: string; label: string }[];
}

export interface SiteContent {
  profile: ProfileView;
  now: { sinceLabel: string; title: string; body: string }[];
  toolGroups: { label: string; emphasis: boolean; tools: string[] }[];
  work: ExperienceView[];
  teaching: ExperienceView[];
  personalProjects: ProjectView[];
  education: { title: string; institution: string; periodLabel: string }[];
  certificationGroups: {
    title: string;
    items: { name: string; issuer: string | null; year: number; note: string | null; url: string | null }[];
  }[];
  contactLinks: { label: string; url: string }[];
}

/** Lee todo el contenido público y lo deja listo para la plantilla (orden y formato incluidos). */
export async function loadContent(pool: Pool): Promise<SiteContent> {
  const q = <T extends object>(sql: string) => pool.query<T>(sql).then((r) => r.rows);

  const [profile, now, groups, tools, experiences, projects, projectTechs, highlights, courses, workshops, education, certGroups, certs, links] =
    await Promise.all([
      q<any>('select * from profile where id = 1'),
      q<any>('select * from now_items where visible order by position, id'),
      q<any>('select * from tool_groups order by position, id'),
      q<any>(`select i.group_id, t.name from tool_group_items i join technologies t on t.id = i.technology_id
               order by i.position, i.id`),
      q<any>('select * from experiences order by start_date desc, position, id'),
      q<any>('select * from projects where visible order by start_date desc, position, id'),
      q<any>(`select pt.project_id, t.name, pt.note from project_technologies pt join technologies t on t.id = pt.technology_id
               order by pt.position, pt.id`),
      q<any>('select * from project_highlights order by position, id'),
      q<any>('select * from courses order by start_date desc, position, id'),
      q<any>('select * from workshops order by sort_date desc, position, id'),
      q<any>('select * from education order by position, id'),
      q<any>('select * from certification_groups order by position, id'),
      q<any>('select * from certifications order by year desc, position, id'),
      q<any>('select * from contact_links order by position, id'),
    ]);

  const p = profile[0];
  if (!p) throw new Error('No hay perfil en la base de datos. Ejecuta `npm run seed` o llénalo desde /admin/profile.');

  const highlightsByProject = groupBy(highlights, (h) => h.project_id);
  const techsByProject = groupBy(projectTechs, (t) => t.project_id);
  const toProject = (r: any): ProjectView => ({
    id: r.id,
    title: r.title,
    description: r.description,
    stack: stackLine((techsByProject.get(r.id) ?? []).map((t) => ({ name: t.name, note: t.note })), r.stack),
    period: buildPeriod(r.start_date, r.end_date, r.period_label),
    highlights: (highlightsByProject.get(r.id) ?? []).map((h) => ({ label: h.label, body: h.body })),
  });

  const projectsByExperience = groupBy(projects.filter((r) => r.kind === 'work'), (r) => r.experience_id);
  const coursesByExperience = groupBy(courses, (r) => r.experience_id);
  const workshopsByExperience = groupBy(workshops, (r) => r.experience_id);

  const toExperience = (r: any): ExperienceView => ({
    id: r.id,
    company: r.company,
    article: r.article,
    role: r.role,
    location: r.location,
    since: r.show_since ? longMonthYear(r.start_date) : null,
    projects: (projectsByExperience.get(r.id) ?? []).map(toProject),
    courses: (coursesByExperience.get(r.id) ?? []).map((c) => ({
      subject: c.subject,
      period: buildPeriod(c.start_date, c.end_date),
    })),
    workshopsTitle: r.workshops_title,
    workshops: (workshopsByExperience.get(r.id) ?? []).map((w) => ({ name: w.name, label: w.period_label })),
  });

  const toolsByGroup = groupBy(tools, (t) => t.group_id);
  const certsByGroup = groupBy(certs, (c) => c.group_id);

  return {
    profile: {
      firstNames: p.first_names,
      lastNames: p.last_names,
      displayName: p.display_name,
      siteTitle: p.site_title,
      headline: p.headline,
      location: p.location,
      intro: p.intro,
      ctaLabel: p.cta_label,
      ctaUrl: p.cta_url,
      contactPrompt: p.contact_prompt,
      metaDescription: p.meta_description,
      ogDescription: p.og_description,
      showViewCount: p.show_view_count,
    },
    now: now.map((n) => ({ sinceLabel: n.since_label, title: n.title, body: n.body })),
    toolGroups: groups
      .map((g) => ({
        label: g.label,
        emphasis: g.emphasis,
        tools: (toolsByGroup.get(g.id) ?? []).map((t) => t.name as string),
      }))
      .filter((g) => g.tools.length > 0),
    work: experiences.filter((e) => e.kind === 'work').map(toExperience),
    teaching: experiences.filter((e) => e.kind === 'teaching').map(toExperience),
    personalProjects: projects.filter((r) => r.kind === 'personal').map(toProject),
    education: education.map((e) => ({ title: e.title, institution: e.institution, periodLabel: e.period_label })),
    certificationGroups: certGroups
      .map((g) => ({
        title: g.title,
        items: (certsByGroup.get(g.id) ?? []).map((c) => ({
          name: c.name,
          issuer: c.issuer,
          year: c.year,
          note: c.note,
          url: c.url,
        })),
      }))
      .filter((g) => g.items.length > 0),
    contactLinks: links.map((l) => ({ label: l.label, url: l.url })),
  };
}

/**
 * Línea de tecnologías de un proyecto: sale del catálogo ("PHP, Laravel, Browsershot (generación de PDF).").
 * Solo si el proyecto aún no tiene tecnologías del catálogo se muestra su texto libre heredado.
 */
export function stackLine(techs: { name: string; note?: string | null }[], legacyText: string): string {
  if (!techs.length) return legacyText;
  return techs.map((t) => (t.note ? `${t.name} (${t.note})` : t.name)).join(', ') + '.';
}

function groupBy<T>(rows: T[], key: (row: T) => number | null): Map<number, T[]> {
  const map = new Map<number, T[]>();
  for (const row of rows) {
    const k = key(row);
    if (k === null) continue;
    const list = map.get(k);
    if (list) list.push(row);
    else map.set(k, [row]);
  }
  return map;
}

// --- Caché en memoria: el sitio es de lectura casi siempre; cada edición desde /admin la invalida. ---

export interface ContentCache<T> {
  get(): Promise<T>;
  invalidate(): void;
}

/**
 * Caché con dos garantías que la versión simple no daba:
 *  - Una sola carga a la vez: si expira y llegan 50 peticiones juntas, se hace una carga (13 consultas), no 50.
 *  - Sin datos obsoletos: si una edición invalida la caché mientras hay una carga en curso, el resultado de esa
 *    carga (que pudo leer antes de la edición) ya no se guarda.
 * Un error de carga no se cachea: la siguiente petición reintenta.
 */
export function createContentCache<T>(load: () => Promise<T>, ttlMs = 60_000, now: () => number = Date.now): ContentCache<T> {
  let cached: { at: number; value: T } | null = null;
  let loading: Promise<T> | null = null;
  let generation = 0;

  return {
    get() {
      if (cached && now() - cached.at < ttlMs) return Promise.resolve(cached.value);
      if (!loading) {
        const started = generation;
        loading = load()
          .then((value) => {
            if (started === generation) cached = { at: now(), value };
            return value;
          })
          .finally(() => {
            if (started === generation) loading = null;
          });
      }
      return loading;
    },
    invalidate() {
      generation += 1;
      cached = null;
      loading = null;
    },
  };
}

const caches = new Map<Pool, ContentCache<SiteContent>>();

export function getContent(pool: Pool): Promise<SiteContent> {
  let cache = caches.get(pool);
  if (!cache) {
    cache = createContentCache(() => loadContent(pool));
    caches.set(pool, cache);
  }
  return cache.get();
}

export function invalidateContent(): void {
  for (const cache of caches.values()) cache.invalidate();
}
