-- 004: integridad en la base, borrados seguros e índices de llaves foráneas.
--
-- Hasta ahora las reglas (fechas coherentes, textos no vacíos, enlaces seguros) vivían solo en el código de la
-- app. Aquí se llevan a la base para que rijan también ante cualquier otra vía de escritura (un script, una
-- consola SQL, un fallo futuro del formulario).
--
-- Cómo se aplica sin poner en riesgo una base con datos reales: las restricciones nuevas se crean NOT VALID
-- (ya se exigen en toda escritura nueva) y al final se intenta VALIDAR contra los datos existentes. Si algún
-- dato antiguo no las cumple, la migración NO falla: deja esa restricción sin validar y emite un WARNING con
-- la instrucción para validarla cuando se corrija el dato. Así el sitio nunca queda caído por esto.

-- ---------------------------------------------------------------------------------------------------------
-- 1) Índices en llaves foráneas que no los tenían (acelera los borrados en cascada y las consultas por padre).
-- ---------------------------------------------------------------------------------------------------------
create index courses_experience_idx   on courses   (experience_id, start_date desc);
create index workshops_experience_idx on workshops (experience_id, sort_date desc);
create index sessions_user_idx        on sessions  (user_id);

-- ---------------------------------------------------------------------------------------------------------
-- 2) Un proyecto, una materia o un taller solo puede colgar de una experiencia del tipo que le corresponde.
--    Sin esto, un proyecto enlazado a una experiencia de docencia quedaba guardado pero invisible en el sitio.
--    Técnica: llave foránea compuesta (id, tipo) con una columna constante que fija el tipo esperado.
--    Como efecto, tampoco se puede cambiar el tipo de una experiencia que ya tiene elementos asociados.
-- ---------------------------------------------------------------------------------------------------------
alter table experiences add constraint experiences_id_kind_key unique (id, kind);

alter table projects add column experience_kind text not null default 'work';
alter table projects add constraint projects_experience_kind_check check (experience_kind = 'work');
alter table projects drop constraint projects_experience_id_fkey;
alter table projects add constraint projects_experience_fkey
  foreign key (experience_id, experience_kind) references experiences (id, kind) on delete restrict not valid;

alter table courses add column experience_kind text not null default 'teaching';
alter table courses add constraint courses_experience_kind_check check (experience_kind = 'teaching');
alter table courses drop constraint courses_experience_id_fkey;
alter table courses add constraint courses_experience_fkey
  foreign key (experience_id, experience_kind) references experiences (id, kind) on delete restrict not valid;

alter table workshops add column experience_kind text not null default 'teaching';
alter table workshops add constraint workshops_experience_kind_check check (experience_kind = 'teaching');
alter table workshops drop constraint workshops_experience_id_fkey;
alter table workshops add constraint workshops_experience_fkey
  foreign key (experience_id, experience_kind) references experiences (id, kind) on delete restrict not valid;

-- ---------------------------------------------------------------------------------------------------------
-- 3) Borrados seguros: lo que se edita como hijo dentro del mismo formulario (herramientas de un grupo, puntos
--    de un proyecto) sigue borrándose en cascada, pero borrar por error una experiencia o un grupo de
--    certificaciones ya no se lleva por delante, en silencio, todo lo que cuelga de ellos.
-- ---------------------------------------------------------------------------------------------------------
alter table certifications drop constraint certifications_group_id_fkey;
alter table certifications add constraint certifications_group_id_fkey
  foreign key (group_id) references certification_groups (id) on delete restrict;

-- ---------------------------------------------------------------------------------------------------------
-- 4) Restricciones de dominio.
-- ---------------------------------------------------------------------------------------------------------

-- Periodos coherentes: el fin no puede ser anterior al inicio.
alter table experiences add constraint experiences_dates_check check (end_date is null or end_date >= start_date) not valid;
alter table projects    add constraint projects_dates_check    check (end_date is null or end_date >= start_date) not valid;
alter table courses     add constraint courses_dates_check     check (end_date is null or end_date >= start_date) not valid;

-- Textos obligatorios no vacíos ni en blanco.
alter table profile add constraint profile_text_check check (
  btrim(first_names) <> '' and btrim(last_names) <> '' and btrim(display_name) <> ''
  and btrim(site_title) <> '' and btrim(headline) <> '') not valid;
alter table now_items           add constraint now_items_text_check           check (btrim(since_label) <> '' and btrim(title) <> '') not valid;
alter table tool_groups         add constraint tool_groups_label_check        check (btrim(label) <> '') not valid;
alter table tools               add constraint tools_name_check               check (btrim(name) <> '') not valid;
alter table experiences         add constraint experiences_text_check         check (btrim(company) <> '' and btrim(role) <> '') not valid;
alter table projects            add constraint projects_title_check           check (btrim(title) <> '') not valid;
alter table project_highlights  add constraint project_highlights_body_check  check (btrim(body) <> '') not valid;
alter table courses             add constraint courses_subject_check          check (btrim(subject) <> '') not valid;
alter table workshops           add constraint workshops_text_check           check (btrim(name) <> '' and btrim(period_label) <> '') not valid;
alter table education           add constraint education_text_check           check (btrim(title) <> '' and btrim(period_label) <> '') not valid;
alter table certification_groups add constraint certification_groups_title_check check (btrim(title) <> '') not valid;
alter table certifications      add constraint certifications_name_check      check (btrim(name) <> '') not valid;
alter table contact_links       add constraint contact_links_label_check      check (btrim(label) <> '') not valid;

-- Enlaces: solo https, http, mailto y tel, sin espacios. Es defensa en profundidad: las plantillas ponen estos
-- valores en un href, y el escapado de HTML no neutraliza esquemas como `javascript:`.
alter table profile       add constraint profile_cta_url_check
  check (cta_url = '' or cta_url ~* '^(https?://|mailto:|tel:)[^[:space:]]+$') not valid;
alter table certifications add constraint certifications_url_check
  check (url is null or url ~* '^(https?://|mailto:|tel:)[^[:space:]]+$') not valid;
alter table contact_links add constraint contact_links_url_check
  check (url ~* '^(https?://|mailto:|tel:)[^[:space:]]+$') not valid;

-- Contador: cifras no negativas y nunca más visitantes que visualizaciones.
alter table page_views add constraint page_views_counts_check
  check (views >= 0 and visitors >= 0 and visitors <= views) not valid;

-- ---------------------------------------------------------------------------------------------------------
-- 5) Validar contra los datos existentes, sin tumbar la migración si algún dato antiguo no cumple.
-- ---------------------------------------------------------------------------------------------------------
do $$
declare
  c record;
begin
  for c in
    select conrelid::regclass::text as tbl, conname
      from pg_constraint
     where not convalidated and connamespace = 'public'::regnamespace
     order by conrelid::regclass::text, conname
  loop
    begin
      execute format('alter table %s validate constraint %I', c.tbl, c.conname);
    exception when others then
      raise warning 'La restriccion % de % no se pudo validar con los datos existentes (%). Sigue vigente para escrituras nuevas. Corrige esos datos y ejecuta: alter table % validate constraint %;',
        c.conname, c.tbl, sqlerrm, c.tbl, c.conname;
    end;
  end loop;
end
$$;

-- ---------------------------------------------------------------------------------------------------------
-- 6) Documentación dentro de la base (visible en psql \d+, DBeaver, pgAdmin…).
-- ---------------------------------------------------------------------------------------------------------
comment on table profile             is 'Una sola fila (id = 1): nombre, presentación y contacto de la portada.';
comment on table experiences         is 'Empleos (kind = work) y cargos docentes (kind = teaching). De ellas cuelgan proyectos, materias y talleres.';
comment on table projects            is 'Proyectos de trabajo (cuelgan de una experiencia work) o propios (kind = personal). end_date nulo = en curso.';
comment on column projects.experience_kind is 'Constante que fija el tipo de experiencia permitido (llave foránea compuesta). No se edita.';
comment on column courses.experience_kind  is 'Constante que fija el tipo de experiencia permitido (llave foránea compuesta). No se edita.';
comment on column workshops.experience_kind is 'Constante que fija el tipo de experiencia permitido (llave foránea compuesta). No se edita.';
comment on table page_views          is 'Totales de visualizaciones por día. No guarda nada de los visitantes.';
comment on table daily_visitors      is 'Hash diario (IP + navegador + sal) solo para no contar dos veces al mismo visitante. Se purga a los 2 días.';
comment on table daily_salts         is 'Sal aleatoria de cada día para el hash de daily_visitors. Se purga a los 2 días.';
comment on table sessions            is 'Sesiones del panel. Guarda el SHA-256 del token, nunca el token.';
