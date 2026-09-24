import fs from 'node:fs';
import path from 'node:path';
import { isSafeUrl, isValidDate } from './admin/crud.js';
import { ROOT } from './config.js';

/**
 * Formato de intercambio del contenido del sitio (JSON). Lo usan tres cosas: el contenido inicial
 * (`db/seed/content.json`), la exportación del panel y la importación del panel. Como las tres pasan por este
 * mismo validador, un archivo exportado siempre se puede volver a importar.
 *
 * Reglas de forma: los nombres de campo son los de las columnas de la base; el orden de las listas ES el orden
 * (`position` no se escribe: sale del lugar en la lista); las fechas son 'AAAA-MM-DD'; las tecnologías van por nombre
 * (se buscan o crean en el catálogo sin distinguir mayúsculas). Los textos se recortan; las reglas repiten las de los
 * formularios del panel (longitudes, enlaces seguros, fechas coherentes) y la base las vuelve a exigir.
 */
export const CONTENT_FORMAT = 'about-me-content';
export const CONTENT_VERSION = 1;

export interface ProfileData {
  first_names: string; last_names: string; display_name: string; site_title: string; headline: string;
  location: string; intro: string[]; cta_label: string; cta_url: string; contact_prompt: string;
  meta_description: string; og_description: string; show_view_count: boolean;
}
export interface ProjectData {
  title: string; description: string;
  /** Texto libre heredado; solo se muestra si el proyecto no tiene tecnologías. */
  stack: string;
  start_date: string; end_date: string | null; period_label: string | null; visible: boolean;
  technologies: { name: string; note: string | null }[];
  highlights: { label: string | null; body: string }[];
  /** Solo lo lee `catalog:import` (reconoce proyectos sin editar). No se guarda ni se exporta. */
  legacy_stack?: string;
}
export interface ExperienceData {
  kind: 'work' | 'teaching'; company: string; article: string | null; role: string; location: string;
  start_date: string; end_date: string | null; show_since: boolean; workshops_title: string | null;
  /** Solo en experiencias de trabajo. */
  projects: ProjectData[];
  /** Solo en experiencias de docencia. */
  courses: { subject: string; start_date: string; end_date: string | null }[];
  workshops: { name: string; period_label: string; sort_date: string }[];
}
export interface ContentData {
  profile: ProfileData;
  contact_links: { label: string; url: string }[];
  now_items: { since_label: string; title: string; body: string; visible: boolean }[];
  tool_groups: { label: string; emphasis: boolean; tools: string[] }[];
  /** Catálogo completo. En una importación es opcional: las tecnologías usadas se crean solas. */
  technologies: string[];
  experiences: ExperienceData[];
  personal_projects: ProjectData[];
  education: { title: string; institution: string; period_label: string }[];
  certification_groups: {
    title: string;
    items: { name: string; issuer: string | null; year: number; note: string | null; url: string | null }[];
  }[];
}

type Obj = Record<string, unknown>;
const MAX_ISSUES = 25;

/** Lector con ruta: cada error dice DÓNDE está ("experiences[0].projects[2].title"). */
class Reader {
  constructor(private readonly issues: string[], private readonly path: string, private readonly o: Obj, allowed: string[]) {
    for (const key of Object.keys(o)) {
      if (!allowed.includes(key)) this.fail(key, `campo desconocido (¿está bien escrito? Permitidos: ${allowed.join(', ')})`);
    }
  }

  fail(key: string | null, message: string): void {
    this.issues.push(`${key === null ? this.path : `${this.path}${this.path ? '.' : ''}${key}`}: ${message}`);
  }

  private raw(key: string): unknown { return this.o[key]; }
  private absent(key: string): boolean { return this.o[key] === undefined || this.o[key] === null; }

  /** Texto recortado. `required`: no puede faltar ni estar vacío. Sin él, falta = ''. */
  text(key: string, opts: { required?: boolean; max?: number } = {}): string {
    const v = this.raw(key);
    if (this.absent(key)) {
      if (opts.required) this.fail(key, 'es obligatorio');
      return '';
    }
    if (typeof v !== 'string') { this.fail(key, 'debe ser texto'); return ''; }
    const s = v.trim();
    if (opts.required && s === '') this.fail(key, 'no puede estar vacío');
    if (opts.max !== undefined && s.length > opts.max) this.fail(key, `máximo ${opts.max} caracteres (tiene ${s.length})`);
    return s;
  }

  /** Texto que la base guarda como NULL cuando está vacío. */
  nullableText(key: string, opts: { max?: number } = {}): string | null {
    const s = this.text(key, opts);
    return s === '' ? null : s;
  }

  bool(key: string, fallback: boolean): boolean {
    const v = this.raw(key);
    if (v === undefined || v === null) return fallback;
    if (typeof v !== 'boolean') { this.fail(key, 'debe ser true o false'); return fallback; }
    return v;
  }

  date(key: string, required: true): string;
  date(key: string, required: false): string | null;
  date(key: string, required: boolean): string | null {
    const s = this.text(key, { required });
    if (s === '') return null;
    if (!isValidDate(s)) { this.fail(key, `"${s}" no es una fecha válida (usa AAAA-MM-DD)`); return required ? '1970-01-01' : null; }
    return s;
  }

  url(key: string, required: boolean): string {
    const s = this.text(key, { required, max: 2000 });
    if (s !== '' && !isSafeUrl(s)) this.fail(key, 'el enlace debe empezar con https://, http://, mailto: o tel: y no llevar espacios');
    return s;
  }

  year(key: string): number {
    const v = this.raw(key);
    if (typeof v !== 'number' || !Number.isInteger(v)) { this.fail(key, 'debe ser un año (número entero)'); return 2000; }
    if (v < 1990 || v > 2100) this.fail(key, 'debe estar entre 1990 y 2100');
    return v;
  }

  /** Lista de objetos; falta = []. Cada elemento se lee con `each`, que recibe su propio Reader. */
  list<T>(key: string, allowed: string[], each: (r: Reader, index: number) => T, opts: { required?: boolean } = {}): T[] {
    const v = this.raw(key);
    if (v === undefined || v === null) {
      if (opts.required) this.fail(key, 'es obligatorio');
      return [];
    }
    if (!Array.isArray(v)) { this.fail(key, 'debe ser una lista'); return []; }
    const out: T[] = [];
    v.forEach((item, i) => {
      const itemPath = `${this.path}${this.path ? '.' : ''}${key}[${i}]`;
      if (item === null || typeof item !== 'object' || Array.isArray(item)) { this.issues.push(`${itemPath}: debe ser un objeto`); return; }
      out.push(each(new Reader(this.issues, itemPath, item as Obj, allowed), i));
    });
    return out;
  }

  /** Lista de textos (p. ej. los párrafos o los nombres de tecnologías). */
  strings(key: string, opts: { max?: number; required?: boolean } = {}): string[] {
    const v = this.raw(key);
    if (v === undefined || v === null) {
      if (opts.required) this.fail(key, 'es obligatorio');
      return [];
    }
    if (!Array.isArray(v)) { this.fail(key, 'debe ser una lista de textos'); return []; }
    const out: string[] = [];
    v.forEach((item, i) => {
      if (typeof item !== 'string' || item.trim() === '') { this.fail(`${key}[${i}]`, 'debe ser un texto no vacío'); return; }
      const s = item.trim();
      if (opts.max !== undefined && s.length > opts.max) this.fail(`${key}[${i}]`, `máximo ${opts.max} caracteres (tiene ${s.length})`);
      out.push(s);
    });
    return out;
  }

  /** Avisa si `end` es anterior a `start` (la base también lo exige). */
  order(start: string | null, end: string | null, endKey = 'end_date'): void {
    if (start && end && end < start) this.fail(endKey, `no puede ser anterior al inicio (${start})`);
  }

  /** Avisa de nombres repetidos sin distinguir mayúsculas (el catálogo no admite duplicados). */
  noRepeats(key: string, names: string[]): void {
    const seen = new Set<string>();
    for (const n of names) {
      const k = n.toLowerCase();
      if (seen.has(k)) this.fail(key, `"${n}" está repetida (sin distinguir mayúsculas)`);
      seen.add(k);
    }
  }
}

const PROFILE_KEYS = [
  'first_names', 'last_names', 'display_name', 'site_title', 'headline', 'location', 'intro', 'cta_label', 'cta_url',
  'contact_prompt', 'meta_description', 'og_description', 'show_view_count',
];
const PROJECT_KEYS = [
  'title', 'description', 'stack', 'start_date', 'end_date', 'period_label', 'visible', 'technologies', 'highlights', 'legacy_stack',
];
const TOP_KEYS = [
  'format', 'version', 'profile', 'contact_links', 'now_items', 'tool_groups', 'technologies', 'experiences',
  'personal_projects', 'education', 'certification_groups',
];

function readProject(r: Reader): ProjectData {
  const start_date = r.date('start_date', true);
  const end_date = r.date('end_date', false);
  r.order(start_date, end_date);
  const technologies = r.list('technologies', ['name', 'note'], (t) => ({
    name: t.text('name', { required: true, max: 80 }), note: t.nullableText('note', { max: 80 }),
  }));
  r.noRepeats('technologies', technologies.map((t) => t.name));
  const project: ProjectData = {
    title: r.text('title', { required: true }),
    description: r.text('description', { max: 1500 }),
    stack: r.text('stack', { max: 500 }),
    start_date, end_date,
    period_label: r.nullableText('period_label'),
    visible: r.bool('visible', true),
    technologies,
    highlights: r.list('highlights', ['label', 'body'], (h) => ({
      label: h.nullableText('label', { max: 80 }), body: h.text('body', { required: true, max: 800 }),
    })),
  };
  const legacy = r.text('legacy_stack');
  if (legacy !== '') project.legacy_stack = legacy;
  return project;
}

function readProfile(r: Reader): ProfileData {
  const intro = r.strings('intro', { required: true });
  if (intro.join('\n\n').length > 3000) r.fail('intro', 'el texto completo pasa de 3000 caracteres');
  if (intro.length === 0) r.fail('intro', 'necesita al menos un párrafo');
  return {
    first_names: r.text('first_names', { required: true }), last_names: r.text('last_names', { required: true }),
    display_name: r.text('display_name', { required: true }), site_title: r.text('site_title', { required: true }),
    headline: r.text('headline', { required: true }), location: r.text('location'), intro,
    cta_label: r.text('cta_label'), cta_url: r.url('cta_url', false), contact_prompt: r.text('contact_prompt'),
    meta_description: r.text('meta_description', { max: 300 }), og_description: r.text('og_description', { max: 300 }),
    show_view_count: r.bool('show_view_count', true),
  };
}

export type ParseResult = { ok: true; data: ContentData } | { ok: false; issues: string[] };

/** Valida un valor ya convertido desde JSON y lo normaliza. Junta todos los problemas, no solo el primero. */
export function parseContent(input: unknown): ParseResult {
  const issues: string[] = [];
  if (input === null || typeof input !== 'object' || Array.isArray(input)) {
    return { ok: false, issues: ['El archivo debe ser un objeto JSON con el contenido del sitio.'] };
  }
  const root = input as Obj;
  const top = new Reader(issues, '', root, TOP_KEYS);

  if (root.format !== undefined && root.format !== CONTENT_FORMAT) top.fail('format', `debe ser "${CONTENT_FORMAT}"`);
  if (root.version !== undefined && root.version !== CONTENT_VERSION) {
    top.fail('version', `este sitio entiende la versión ${CONTENT_VERSION}, no ${JSON.stringify(root.version)}`);
  }

  // El perfil es un objeto, no una lista: se lee con su propio Reader.
  const profileRaw = root.profile;
  let profile: ProfileData | null = null;
  if (profileRaw === null || typeof profileRaw !== 'object' || Array.isArray(profileRaw)) {
    top.fail('profile', 'es obligatorio y debe ser un objeto');
  } else {
    profile = readProfile(new Reader(issues, 'profile', profileRaw as Obj, PROFILE_KEYS));
  }

  const contact_links = top.list('contact_links', ['label', 'url'], (r) => ({
    label: r.text('label', { required: true, max: 60 }), url: r.url('url', true),
  }));
  const now_items = top.list('now_items', ['since_label', 'title', 'body', 'visible'], (r) => ({
    since_label: r.text('since_label', { required: true }), title: r.text('title', { required: true }),
    body: r.text('body'), visible: r.bool('visible', true),
  }));
  const tool_groups = top.list('tool_groups', ['label', 'emphasis', 'tools'], (r) => {
    const tools = r.strings('tools', { max: 80 });
    r.noRepeats('tools', tools);
    return { label: r.text('label', { required: true }), emphasis: r.bool('emphasis', false), tools };
  });
  const technologies = top.strings('technologies', { max: 80 });

  const experiences = top.list(
    'experiences',
    ['kind', 'company', 'article', 'role', 'location', 'start_date', 'end_date', 'show_since', 'workshops_title', 'projects', 'courses', 'workshops'],
    (r): ExperienceData => {
      const kindText = r.text('kind', { required: true });
      const kind: 'work' | 'teaching' = kindText === 'teaching' ? 'teaching' : 'work';
      if (kindText !== '' && kindText !== 'work' && kindText !== 'teaching') r.fail('kind', 'debe ser "work" o "teaching"');
      const start_date = r.date('start_date', true);
      const end_date = r.date('end_date', false);
      r.order(start_date, end_date);

      const projects = r.list('projects', PROJECT_KEYS, readProject);
      const courses = r.list('courses', ['subject', 'start_date', 'end_date'], (c) => {
        const start = c.date('start_date', true);
        const end = c.date('end_date', false);
        c.order(start, end);
        return { subject: c.text('subject', { required: true }), start_date: start, end_date: end };
      });
      const workshops = r.list('workshops', ['name', 'period_label', 'sort_date'], (w) => ({
        name: w.text('name', { required: true }), period_label: w.text('period_label', { required: true }),
        sort_date: w.date('sort_date', true),
      }));
      if (kind === 'work' && (courses.length || workshops.length)) r.fail(null, 'una experiencia de trabajo no lleva "courses" ni "workshops" (son de docencia)');
      if (kind === 'teaching' && projects.length) r.fail(null, 'una experiencia de docencia no lleva "projects" (son de trabajo)');

      return {
        kind, company: r.text('company', { required: true }), article: r.nullableText('article', { max: 10 }),
        role: r.text('role', { required: true }), location: r.text('location'), start_date, end_date,
        show_since: r.bool('show_since', true), workshops_title: r.nullableText('workshops_title'),
        projects, courses, workshops,
      };
    },
  );
  const personal_projects = top.list('personal_projects', PROJECT_KEYS, readProject);
  const education = top.list('education', ['title', 'institution', 'period_label'], (r) => ({
    title: r.text('title', { required: true }), institution: r.text('institution'),
    period_label: r.text('period_label', { required: true }),
  }));
  const certification_groups = top.list('certification_groups', ['title', 'items'], (r) => ({
    title: r.text('title', { required: true }),
    items: r.list('items', ['name', 'issuer', 'year', 'note', 'url'], (i) => ({
      name: i.text('name', { required: true }), issuer: i.nullableText('issuer'), year: i.year('year'),
      note: i.nullableText('note'), url: i.url('url', false) || null,
    })),
  }));

  if (issues.length || !profile) return { ok: false, issues };
  return {
    ok: true,
    data: { profile, contact_links, now_items, tool_groups, technologies, experiences, personal_projects, education, certification_groups },
  };
}

/** Convierte el texto de un archivo JSON. Tolera el BOM que algunos editores de Windows añaden. */
export function parseContentText(text: string): ParseResult {
  let value: unknown;
  try {
    value = JSON.parse(text.replace(/^﻿/, ''));
  } catch (err) {
    return { ok: false, issues: [`El texto no es JSON válido: ${(err as Error).message}`] };
  }
  const result = parseContent(value);
  if (result.ok || result.issues.length <= MAX_ISSUES) return result;
  return { ok: false, issues: [...result.issues.slice(0, MAX_ISSUES), `…y ${result.issues.length - MAX_ISSUES} problemas más.`] };
}

/** El JSON legible que se descarga: cada experiencia solo lleva las listas de su tipo y no repite lo vacío. */
export function serializeContent(data: ContentData): string {
  const project = (p: ProjectData) => ({
    title: p.title, description: p.description, ...(p.stack ? { stack: p.stack } : {}),
    start_date: p.start_date, end_date: p.end_date, period_label: p.period_label, visible: p.visible,
    technologies: p.technologies, highlights: p.highlights,
    ...(p.legacy_stack ? { legacy_stack: p.legacy_stack } : {}),
  });
  const out = {
    format: CONTENT_FORMAT,
    version: CONTENT_VERSION,
    profile: data.profile,
    contact_links: data.contact_links,
    now_items: data.now_items,
    tool_groups: data.tool_groups,
    technologies: data.technologies,
    experiences: data.experiences.map((e) => ({
      kind: e.kind, company: e.company, article: e.article, role: e.role, location: e.location,
      start_date: e.start_date, end_date: e.end_date, show_since: e.show_since, workshops_title: e.workshops_title,
      ...(e.kind === 'work' ? { projects: e.projects.map(project) } : { courses: e.courses, workshops: e.workshops }),
    })),
    personal_projects: data.personal_projects.map(project),
    education: data.education,
    certification_groups: data.certification_groups,
  };
  return `${JSON.stringify(out, null, 2)}\n`;
}

/**
 * Contenido inicial. `db/seed/content.json` es el contenido REAL de quien despliega el sitio y NO se sube al repositorio
 * (está en .gitignore); `content.example.json` es ficticio y sí se versiona. Se usa el real si existe y, si no, el de
 * ejemplo: así un despliegue desde el repositorio arranca con datos de ejemplo y el contenido propio se carga después
 * desde el panel (Importar y exportar).
 */
export const SEED_FILE = path.join(ROOT, 'db', 'seed', 'content.json');
export const EXAMPLE_SEED_FILE = path.join(ROOT, 'db', 'seed', 'content.example.json');
export const seedFile = (): string => (fs.existsSync(SEED_FILE) ? SEED_FILE : EXAMPLE_SEED_FILE);

/** Lee y valida un archivo de contenido. Si está mal, el error dice dónde. */
export function loadSeedContent(file: string = seedFile()): ContentData {
  const result = parseContentText(fs.readFileSync(file, 'utf8'));
  if (!result.ok) throw new Error(`El contenido inicial (${file}) no es válido:\n  - ${result.issues.join('\n  - ')}`);
  return result.data;
}
