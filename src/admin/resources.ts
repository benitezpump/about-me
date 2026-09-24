/**
 * Descripción declarativa de todo lo editable desde /admin.
 * Para agregar una entidad nueva: crea su tabla en una migración y descríbela aquí;
 * las pantallas de lista, alta, edición y borrado salen solas.
 */

// Lista cerrada a propósito: añadir un tipo (archivo, texto enriquecido…) cambia el alcance del panel.
// Ver docs/decisions/0001-arquitectura-y-alcance-del-panel.md (y tests/scope.test.ts, que la vigila).
export const FIELD_TYPES = ['text', 'textarea', 'paragraphs', 'number', 'date', 'checkbox', 'select', 'url'] as const;
export type FieldType = (typeof FIELD_TYPES)[number];

export interface Option { value: string; label: string }

/** Opciones tomadas de otra tabla (llaves foráneas). Los identificadores vienen de aquí, nunca del usuario. */
export interface OptionsFrom { table: string; value: string; label: string; orderBy: string; where?: string }

export interface Field {
  name: string;
  label: string;
  type: FieldType;
  required?: boolean;
  /** La columna admite NULL: un campo vacío se guarda como NULL en lugar de ''. */
  nullable?: boolean;
  help?: string;
  max?: number;
  min?: number;
  options?: Option[];
  optionsFrom?: OptionsFrom;
  default?: string | number | boolean;
}

export interface ChildResource {
  key: string;
  table: string;
  fk: string;
  label: string;
  itemLabel: string;
  /** Campo que no puede repetirse entre las filas (p. ej. la misma tecnología dos veces en un proyecto). */
  unique?: string;
  fields: Field[];
}

export interface Resource {
  slug: string;
  table: string;
  label: string;
  singular: string;
  description: string;
  fields: Field[];
  /** SELECT que alimenta la lista; debe devolver `id` y las columnas de `columns`. */
  listSql: string;
  columns: { key: string; label: string }[];
  children?: ChildResource[];
  /** Una sola fila (id = 1), sin lista ni borrado. */
  singleton?: boolean;
  /** Reglas entre campos; devuelve errores por nombre de campo. */
  validate?: (values: Record<string, unknown>) => Record<string, string>;
}

const position: Field = {
  name: 'position', label: 'Orden', type: 'number', default: 0, min: 0, max: 9999,
  help: 'Menor va primero. Sirve para desempatar cuando el orden no sale de una fecha.',
};

const experienceOptions: OptionsFrom = {
  table: 'experiences',
  value: 'id',
  label: "company || ' — ' || role",
  orderBy: 'start_date desc',
};

// El catálogo de tecnologías: se elige de aquí, no se escribe a mano.
const catalogOptions: OptionsFrom = { table: 'technologies', value: 'id', label: 'name', orderBy: 'lower(name)' };

// Cada elemento solo puede colgar de una experiencia de su tipo (la base también lo exige).
const workExperiences: OptionsFrom = { ...experienceOptions, where: "kind = 'work'" };
const teachingExperiences: OptionsFrom = { ...experienceOptions, where: "kind = 'teaching'" };

export const resources: Resource[] = [
  {
    slug: 'profile',
    table: 'profile',
    label: 'Perfil',
    singular: 'perfil',
    description: 'Nombre, presentación y datos de contacto de la portada.',
    singleton: true,
    fields: [
      { name: 'first_names', label: 'Nombres', type: 'text', required: true, help: 'Primera línea del título grande.' },
      { name: 'last_names', label: 'Apellidos', type: 'text', required: true, help: 'Segunda línea del título grande.' },
      { name: 'display_name', label: 'Nombre corto', type: 'text', required: true, help: 'Aparece en la barra superior.' },
      { name: 'site_title', label: 'Título de la página', type: 'text', required: true, help: 'Pestaña del navegador y vista previa al compartir.' },
      { name: 'headline', label: 'Línea bajo el nombre', type: 'text', required: true },
      { name: 'intro', label: 'Presentación', type: 'paragraphs', required: true, max: 3000, help: 'Separa los párrafos con una línea en blanco.' },
      { name: 'location', label: 'Ubicación', type: 'text' },
      { name: 'cta_label', label: 'Texto del botón principal', type: 'text' },
      { name: 'cta_url', label: 'Enlace del botón principal', type: 'url', help: 'Empieza con https://, mailto: o tel:.' },
      { name: 'contact_prompt', label: 'Invitación a contactar', type: 'text' },
      { name: 'meta_description', label: 'Descripción para buscadores', type: 'textarea', max: 300 },
      { name: 'og_description', label: 'Descripción al compartir el enlace', type: 'textarea', max: 300 },
      {
        name: 'show_view_count', label: 'Mostrar el contador de visualizaciones en el sitio', type: 'checkbox', default: true,
        help: 'Aparece en el pie de la página. Las estadísticas siguen disponibles en el Panel aunque lo ocultes.',
      },
    ],
    listSql: 'select id from profile',
    columns: [],
  },
  {
    slug: 'now-items',
    table: 'now_items',
    label: 'Actualmente',
    singular: 'elemento',
    description: 'Lo que haces hoy, en el recuadro de la portada.',
    fields: [
      { name: 'since_label', label: 'Desde', type: 'text', required: true, help: 'Texto libre, por ejemplo "Desde 03/2015".' },
      { name: 'title', label: 'Título (en negritas)', type: 'text', required: true },
      { name: 'body', label: 'Texto que sigue al título', type: 'textarea' },
      position,
      { name: 'visible', label: 'Mostrar en el sitio', type: 'checkbox', default: true },
    ],
    listSql: 'select id, since_label, title, position, visible from now_items order by position, id',
    columns: [
      { key: 'title', label: 'Título' },
      { key: 'since_label', label: 'Desde' },
      { key: 'visible', label: 'Visible' },
    ],
  },
  {
    slug: 'technologies',
    table: 'technologies',
    label: 'Tecnologías',
    singular: 'tecnología',
    description: 'El catálogo de tecnologías. Se elige de aquí en Herramientas y en cada proyecto, así el nombre se escribe una sola vez.',
    fields: [
      {
        name: 'name', label: 'Nombre', type: 'text', required: true, max: 80,
        help: 'No puede repetirse (sin distinguir mayúsculas). Renombrarla aquí la actualiza en todo el sitio.',
      },
    ],
    listSql: `select t.id, t.name,
                     (select count(*) from project_technologies pt where pt.technology_id = t.id)::int as projects,
                     (select count(*) from tool_group_items i where i.technology_id = t.id)::int as groups
                from technologies t order by lower(t.name)`,
    columns: [
      { key: 'name', label: 'Tecnología' }, { key: 'projects', label: 'En proyectos' }, { key: 'groups', label: 'En herramientas' },
    ],
  },
  {
    slug: 'tools',
    table: 'tool_groups',
    label: 'Herramientas',
    singular: 'grupo de herramientas',
    description: 'Grupos de tecnologías de la sección Herramientas. Las tecnologías se eligen del catálogo.',
    fields: [
      { name: 'label', label: 'Nombre del grupo', type: 'text', required: true },
      { name: 'emphasis', label: 'Resaltar este grupo', type: 'checkbox', help: 'Muestra sus herramientas con más contraste.' },
      position,
    ],
    children: [
      {
        key: 'tools', table: 'tool_group_items', fk: 'group_id', label: 'Herramientas del grupo', itemLabel: 'herramienta',
        unique: 'technology_id',
        fields: [{ name: 'technology_id', label: 'Tecnología', type: 'select', required: true, optionsFrom: catalogOptions }],
      },
    ],
    listSql: `select g.id, g.label, g.position, (select count(*) from tool_group_items i where i.group_id = g.id)::int as total
                from tool_groups g order by g.position, g.id`,
    columns: [{ key: 'label', label: 'Grupo' }, { key: 'total', label: 'Herramientas' }],
  },
  {
    slug: 'experiences',
    table: 'experiences',
    label: 'Empleos y docencia',
    singular: 'experiencia',
    description: 'Cada empleo o cargo docente. Los proyectos, materias y talleres se enlazan a una experiencia.',
    fields: [
      {
        name: 'kind', label: 'Tipo', type: 'select', required: true, default: 'work',
        options: [{ value: 'work', label: 'Trabajo (aparece en Trabajo)' }, { value: 'teaching', label: 'Docencia (aparece en Docencia)' }],
      },
      { name: 'company', label: 'Empresa o institución', type: 'text', required: true },
      { name: 'article', label: 'Artículo antes del nombre', type: 'text', nullable: true, max: 10, help: 'Por ejemplo "el" para escribir "en el Instituto…". Déjalo vacío si no lleva.' },
      { name: 'role', label: 'Cargo', type: 'text', required: true },
      { name: 'location', label: 'Ciudad', type: 'text' },
      { name: 'start_date', label: 'Inicio', type: 'date', required: true },
      { name: 'end_date', label: 'Fin', type: 'date', nullable: true, help: 'Déjalo vacío si sigue en curso.' },
      { name: 'show_since', label: 'Mostrar "desde <mes año>" en la introducción', type: 'checkbox', default: true },
      { name: 'workshops_title', label: 'Título de la lista de talleres', type: 'text', nullable: true, help: 'Solo para docencia.' },
      position,
    ],
    listSql: `select id, company, role, kind, to_char(start_date, 'YYYY-MM') as start_month, end_date is null as current
                from experiences order by start_date desc, id`,
    columns: [
      { key: 'company', label: 'Empresa' }, { key: 'role', label: 'Cargo' },
      { key: 'kind', label: 'Tipo' }, { key: 'start_month', label: 'Inicio' }, { key: 'current', label: 'En curso' },
    ],
  },
  {
    slug: 'projects',
    table: 'projects',
    label: 'Proyectos',
    singular: 'proyecto',
    description: 'Proyectos de trabajo (línea de tiempo) y proyectos propios.',
    fields: [
      {
        name: 'kind', label: 'Tipo', type: 'select', required: true, default: 'work',
        options: [{ value: 'work', label: 'De trabajo (cuelga de una experiencia)' }, { value: 'personal', label: 'Propio (sección Proyectos propios)' }],
      },
      {
        name: 'experience_id', label: 'Experiencia', type: 'select', nullable: true, optionsFrom: workExperiences,
        help: 'Obligatoria para proyectos de trabajo.',
      },
      { name: 'title', label: 'Título', type: 'text', required: true },
      { name: 'description', label: 'Descripción', type: 'textarea', max: 1500 },
      {
        name: 'stack', label: 'Tecnologías en texto libre (respaldo)', type: 'textarea', max: 500,
        help: 'Solo se muestra si el proyecto no tiene tecnologías del catálogo (más abajo). En cuanto elijas alguna, este texto deja de usarse.',
      },
      { name: 'start_date', label: 'Inicio', type: 'date', required: true },
      { name: 'end_date', label: 'Fin', type: 'date', nullable: true, help: 'Déjalo vacío si sigue en curso (se muestra como "actualidad").' },
      { name: 'period_label', label: 'Periodo con texto libre', type: 'text', nullable: true, help: 'Opcional. Sustituye al periodo calculado, por ejemplo "21 jul – 19 oct 2022".' },
      { name: 'visible', label: 'Mostrar en el sitio', type: 'checkbox', default: true },
      position,
    ],
    children: [
      {
        key: 'technologies', table: 'project_technologies', fk: 'project_id', label: 'Tecnologías del proyecto', itemLabel: 'tecnología',
        unique: 'technology_id',
        fields: [
          { name: 'technology_id', label: 'Tecnología', type: 'select', required: true, optionsFrom: catalogOptions },
          { name: 'note', label: 'Nota (opcional, se muestra entre paréntesis)', type: 'text', nullable: true, max: 80 },
        ],
      },
      {
        key: 'highlights', table: 'project_highlights', fk: 'project_id', label: 'Puntos del detalle', itemLabel: 'punto',
        fields: [
          { name: 'label', label: 'Etiqueta (negritas, opcional)', type: 'text', nullable: true, max: 80 },
          { name: 'body', label: 'Texto', type: 'textarea', required: true, max: 800 },
        ],
      },
    ],
    listSql: `select p.id, p.title, p.kind, e.company, to_char(p.start_date, 'YYYY-MM') as start_month, p.visible
                from projects p left join experiences e on e.id = p.experience_id
               order by p.start_date desc, p.id`,
    columns: [
      { key: 'title', label: 'Proyecto' }, { key: 'kind', label: 'Tipo' }, { key: 'company', label: 'Experiencia' },
      { key: 'start_month', label: 'Inicio' }, { key: 'visible', label: 'Visible' },
    ],
    validate: (v) => {
      const errors: Record<string, string> = {};
      if (v.kind === 'work' && v.experience_id == null) {
        errors.experience_id = 'Un proyecto de trabajo necesita una experiencia.';
      }
      return errors;
    },
  },
  {
    slug: 'courses',
    table: 'courses',
    label: 'Materias',
    singular: 'materia',
    description: 'Materias impartidas, por periodo.',
    fields: [
      { name: 'experience_id', label: 'Experiencia', type: 'select', required: true, optionsFrom: teachingExperiences },
      { name: 'subject', label: 'Materia', type: 'text', required: true },
      { name: 'start_date', label: 'Inicio', type: 'date', required: true },
      { name: 'end_date', label: 'Fin', type: 'date', nullable: true, help: 'Déjalo vacío si sigue en curso.' },
      position,
    ],
    listSql: `select c.id, c.subject, e.company, to_char(c.start_date, 'YYYY-MM') as start_month
                from courses c join experiences e on e.id = c.experience_id order by c.start_date desc, c.id`,
    columns: [{ key: 'subject', label: 'Materia' }, { key: 'company', label: 'Institución' }, { key: 'start_month', label: 'Inicio' }],
  },
  {
    slug: 'workshops',
    table: 'workshops',
    label: 'Talleres',
    singular: 'taller',
    description: 'Talleres y cursos que has impartido.',
    fields: [
      { name: 'experience_id', label: 'Experiencia', type: 'select', required: true, optionsFrom: teachingExperiences },
      { name: 'name', label: 'Nombre', type: 'text', required: true },
      { name: 'period_label', label: 'Fechas (texto)', type: 'text', required: true, help: 'Se muestra tal cual, por ejemplo "07 – 10 nov 2024".' },
      { name: 'sort_date', label: 'Fecha para ordenar', type: 'date', required: true, help: 'Los más recientes salen primero.' },
      position,
    ],
    listSql: `select w.id, w.name, w.period_label from workshops w order by w.sort_date desc, w.id`,
    columns: [{ key: 'name', label: 'Taller' }, { key: 'period_label', label: 'Fechas' }],
  },
  {
    slug: 'education',
    table: 'education',
    label: 'Estudios',
    singular: 'estudio',
    description: 'Formación académica.',
    fields: [
      { name: 'title', label: 'Título o carrera', type: 'text', required: true },
      { name: 'institution', label: 'Institución', type: 'text' },
      { name: 'period_label', label: 'Periodo (texto)', type: 'text', required: true, help: 'Por ejemplo "2010 – 2014".' },
      position,
    ],
    listSql: 'select id, title, institution, period_label from education order by position, id',
    columns: [{ key: 'title', label: 'Estudio' }, { key: 'institution', label: 'Institución' }, { key: 'period_label', label: 'Periodo' }],
  },
  {
    slug: 'certification-groups',
    table: 'certification_groups',
    label: 'Grupos de certificaciones',
    singular: 'grupo de certificaciones',
    description: 'Los encabezados bajo los que se agrupan las certificaciones.',
    fields: [{ name: 'title', label: 'Título del grupo', type: 'text', required: true }, position],
    listSql: `select g.id, g.title, g.position, (select count(*) from certifications c where c.group_id = g.id)::int as total
                from certification_groups g order by g.position, g.id`,
    columns: [{ key: 'title', label: 'Grupo' }, { key: 'total', label: 'Certificaciones' }],
  },
  {
    slug: 'certifications',
    table: 'certifications',
    label: 'Certificaciones',
    singular: 'certificación',
    description: 'Certificados, con enlace para verificarlos.',
    fields: [
      {
        name: 'group_id', label: 'Grupo', type: 'select', required: true,
        optionsFrom: { table: 'certification_groups', value: 'id', label: 'title', orderBy: 'position, id' },
      },
      { name: 'name', label: 'Nombre', type: 'text', required: true },
      { name: 'issuer', label: 'Emisor', type: 'text', nullable: true, help: 'Se muestra después del nombre. Déjalo vacío si el grupo ya lo indica.' },
      { name: 'year', label: 'Año', type: 'number', required: true, min: 1990, max: 2100 },
      { name: 'note', label: 'Nota', type: 'text', nullable: true, help: 'Por ejemplo "Vigente hasta 2029."' },
      { name: 'url', label: 'Enlace al certificado', type: 'url', nullable: true },
      position,
    ],
    listSql: `select c.id, c.name, c.year, g.title as group_title
                from certifications c join certification_groups g on g.id = c.group_id
               order by g.position, c.year desc, c.position, c.id`,
    columns: [{ key: 'name', label: 'Certificación' }, { key: 'year', label: 'Año' }, { key: 'group_title', label: 'Grupo' }],
  },
  {
    slug: 'contact-links',
    table: 'contact_links',
    label: 'Enlaces de contacto',
    singular: 'enlace',
    description: 'Enlaces extra en la sección Contacto (GitHub, correo, etc.).',
    fields: [
      { name: 'label', label: 'Etiqueta', type: 'text', required: true, max: 60 },
      { name: 'url', label: 'Enlace', type: 'url', required: true, help: 'Empieza con https://, mailto: o tel:.' },
      position,
    ],
    listSql: 'select id, label, url from contact_links order by position, id',
    columns: [{ key: 'label', label: 'Etiqueta' }, { key: 'url', label: 'Enlace' }],
  },
];

export const resourceBySlug = new Map(resources.map((r) => [r.slug, r]));

// --- Comprobación al arrancar: los identificadores SQL de esta configuración nunca vienen del usuario,
// --- pero se validan igual para que un error de tipeo no acabe en una consulta rara.
const IDENT = /^[a-z_][a-z0-9_]*$/;
const assertIdent = (s: string, where: string): void => {
  if (!IDENT.test(s)) throw new Error(`Identificador SQL inválido en ${where}: "${s}"`);
};
for (const r of resources) {
  assertIdent(r.table, `resource ${r.slug}`);
  for (const f of r.fields) assertIdent(f.name, `${r.slug}.${f.name}`);
  for (const c of r.children ?? []) {
    assertIdent(c.table, `${r.slug}.${c.key}`);
    assertIdent(c.fk, `${r.slug}.${c.key}.fk`);
    for (const f of c.fields) assertIdent(f.name, `${r.slug}.${c.key}.${f.name}`);
  }
}
