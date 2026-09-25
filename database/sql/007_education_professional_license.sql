-- Número de cédula profesional de un estudio (opcional: solo si el título la tiene). Es un dato público (se consulta en el
-- registro nacional de profesiones), por eso puede mostrarse en el sitio.
alter table education add column professional_license text;

-- Solo dígitos (6 a 10). NULL = sin cédula. La columna es nueva y todas sus filas son NULL, así que validar no puede fallar.
alter table education add constraint education_professional_license_check
  check (professional_license is null or professional_license ~ '^[0-9]{6,10}$') not valid;
alter table education validate constraint education_professional_license_check;

comment on column education.professional_license is 'Número de cédula profesional (solo dígitos, 6 a 10). NULL si el estudio no la tiene.';
