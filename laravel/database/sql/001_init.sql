-- Esquema inicial del sitio personal.
-- Convenciones:
--   * `position` ordena manualmente (menor primero) cuando el orden no sale de una fecha.
--   * Las fechas de periodos tienen precisión de mes: el día se ignora al mostrarlas.
--   * `end_date` nulo significa "en curso" (se muestra como "actualidad").

create function set_updated_at() returns trigger
language plpgsql as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

-- Perfil: una sola fila (id = 1).
create table profile (
  id               smallint primary key default 1 check (id = 1),
  first_names      text not null,
  last_names       text not null,
  display_name     text not null,               -- nombre corto de la barra superior
  site_title       text not null,               -- <title> de la página
  headline         text not null,               -- línea bajo el nombre
  location         text not null default '',
  intro            text[] not null default '{}',-- un párrafo por elemento
  cta_label        text not null default '',
  cta_url          text not null default '',
  contact_prompt   text not null default '',
  meta_description text not null default '',
  og_description   text not null default '',
  updated_at       timestamptz not null default now()
);
create trigger profile_updated_at before update on profile
  for each row execute function set_updated_at();

-- Bloque "Actualmente" de la portada.
create table now_items (
  id          serial primary key,
  since_label text not null,
  title       text not null,                    -- parte en negritas
  body        text not null default '',
  position    int  not null default 0,
  visible     boolean not null default true
);

-- Herramientas agrupadas.
create table tool_groups (
  id       serial primary key,
  label    text not null,
  emphasis boolean not null default false,      -- resalta el grupo principal
  position int  not null default 0
);
create table tools (
  id       serial primary key,
  group_id int  not null references tool_groups(id) on delete cascade,
  name     text not null,
  position int  not null default 0
);
create index tools_group_idx on tools (group_id, position);

-- Empleos y docencia.
create table experiences (
  id              serial primary key,
  kind            text not null check (kind in ('work', 'teaching')),
  company         text not null,
  role            text not null,
  location        text not null default '',
  start_date      date not null,
  end_date        date,
  show_since      boolean not null default true, -- muestra "desde <mes año>" en la introducción
  workshops_title text,
  position        int  not null default 0,
  updated_at      timestamptz not null default now()
);
create trigger experiences_updated_at before update on experiences
  for each row execute function set_updated_at();

-- Proyectos: de trabajo (cuelgan de un empleo) o propios.
create table projects (
  id            serial primary key,
  experience_id int references experiences(id) on delete cascade,
  kind          text not null default 'work' check (kind in ('work', 'personal')),
  title         text not null,
  description   text not null default '',
  stack         text not null default '',
  start_date    date not null,
  end_date      date,
  period_label  text,                            -- sustituye al periodo calculado
  visible       boolean not null default true,
  position      int  not null default 0,
  updated_at    timestamptz not null default now(),
  constraint projects_work_needs_experience check (kind <> 'work' or experience_id is not null)
);
create index projects_experience_idx on projects (experience_id, start_date desc);
create index projects_kind_idx on projects (kind, start_date desc);
create trigger projects_updated_at before update on projects
  for each row execute function set_updated_at();

create table project_highlights (
  id         serial primary key,
  project_id int  not null references projects(id) on delete cascade,
  label      text,                               -- se muestra en negritas antes del texto
  body       text not null,
  position   int  not null default 0
);
create index project_highlights_idx on project_highlights (project_id, position);

-- Materias y talleres de la experiencia docente.
create table courses (
  id            serial primary key,
  experience_id int  not null references experiences(id) on delete cascade,
  subject       text not null,
  start_date    date not null,
  end_date      date,
  position      int  not null default 0
);
create table workshops (
  id            serial primary key,
  experience_id int  not null references experiences(id) on delete cascade,
  name          text not null,
  period_label  text not null,
  sort_date     date not null,
  position      int  not null default 0
);

-- Formación.
create table education (
  id           serial primary key,
  title        text not null,
  institution  text not null default '',
  period_label text not null,
  position     int  not null default 0
);

create table certification_groups (
  id       serial primary key,
  title    text not null,
  position int  not null default 0
);
create table certifications (
  id       serial primary key,
  group_id int  not null references certification_groups(id) on delete cascade,
  name     text not null,
  issuer   text,
  year     smallint not null check (year between 1990 and 2100),
  note     text,
  url      text,
  position int  not null default 0,
  updated_at timestamptz not null default now()
);
create index certifications_group_idx on certifications (group_id, year desc, position);
create trigger certifications_updated_at before update on certifications
  for each row execute function set_updated_at();

-- Enlaces de contacto adicionales (GitHub, correo, etc.).
create table contact_links (
  id       serial primary key,
  label    text not null,
  url      text not null,
  position int  not null default 0
);

-- Acceso al panel de administración.
create table admin_users (
  id            serial primary key,
  username      text not null unique,
  password_hash text not null,
  created_at    timestamptz not null default now()
);
create table sessions (
  token_hash text primary key,
  user_id    int  not null references admin_users(id) on delete cascade,
  csrf       text not null,
  expires_at timestamptz not null,
  created_at timestamptz not null default now()
);
create index sessions_expires_idx on sessions (expires_at);
