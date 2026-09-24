-- 005: catálogo de tecnologías.
--
-- Antes, las tecnologías eran texto libre en dos sitios: los nombres sueltos de `tools` y la columna de texto
-- `projects.stack`. Sin catálogo, "Node.js" se escribía a mano en cada grupo y en cada proyecto (con riesgo de
-- "NodeJS", "node js"…), no se podía renombrar en un solo lugar ni saber en qué proyectos se usa algo.
--
--   technologies         el catálogo: un nombre único (sin distinguir mayúsculas) por tecnología.
--   tool_group_items     qué tecnologías aparecen en cada grupo de "Herramientas" (reemplaza a `tools`).
--   project_technologies qué tecnologías usa cada proyecto, con una nota opcional ("generación de PDF").
--
-- Qué hace con los datos existentes:
--   * `tools` se convierte al catálogo sin perder nada visible (mismos nombres, mismo orden) y se elimina.
--   * `projects.stack` (texto libre) NO se toca: queda como respaldo y el sitio lo sigue mostrando mientras el
--     proyecto no tenga tecnologías del catálogo. Convertirlos sin mirar sería adivinar sobre prosa como
--     "PHP con Symfony2" o "Python y Django en backend y frontend"; para eso está `npm run catalog:import`,
--     que solo convierte lo que coincide exactamente con el texto original.

create table technologies (
  id         serial primary key,
  name       text not null,
  created_at timestamptz not null default now(),
  constraint technologies_name_check check (btrim(name) <> '' and char_length(name) <= 80)
);
-- Un nombre por tecnología, sin distinguir mayúsculas ni espacios de más: "Node.js" y "node.js " son la misma.
create unique index technologies_name_key on technologies (lower(btrim(name)));

create table tool_group_items (
  id            serial primary key,
  group_id      int not null references tool_groups (id) on delete cascade,
  technology_id int not null references technologies (id) on delete restrict,
  position      int not null default 0,
  constraint tool_group_items_unique unique (group_id, technology_id)
);
create index tool_group_items_tech_idx on tool_group_items (technology_id);

create table project_technologies (
  id            serial primary key,
  project_id    int not null references projects (id) on delete cascade,
  technology_id int not null references technologies (id) on delete restrict,
  note          text,
  position      int not null default 0,
  constraint project_technologies_unique unique (project_id, technology_id),
  constraint project_technologies_note_check check (note is null or (btrim(note) <> '' and char_length(note) <= 80))
);
create index project_technologies_tech_idx on project_technologies (technology_id);

-- Las herramientas existentes pasan al catálogo. Se unifican los duplicados (misma tecnología en dos grupos, o
-- escrita "postgresql" y "PostgreSQL"): se conserva la escritura de la primera aparición por orden.
insert into technologies (name)
select distinct on (lower(btrim(name))) left(btrim(name), 80)
  from tools
 where btrim(name) <> ''
 order by lower(btrim(name)), position, id;

insert into tool_group_items (group_id, technology_id, position)
select distinct on (t.group_id, lower(btrim(t.name))) t.group_id, c.id, t.position
  from tools t
  join technologies c on lower(btrim(c.name)) = lower(left(btrim(t.name), 80))
 where btrim(t.name) <> ''
 order by t.group_id, lower(btrim(t.name)), t.position, t.id;

drop table tools;

comment on table technologies         is 'Catálogo de tecnologías: un nombre único por tecnología (sin distinguir mayúsculas).';
comment on table tool_group_items     is 'Tecnologías de cada grupo de "Herramientas". Solo se puede quitar una tecnología del catálogo si nadie la usa.';
comment on table project_technologies is 'Tecnologías de cada proyecto, con una nota opcional que se muestra entre paréntesis.';
comment on column projects.stack      is 'Texto libre heredado. Solo se muestra si el proyecto no tiene tecnologías en project_technologies.';
