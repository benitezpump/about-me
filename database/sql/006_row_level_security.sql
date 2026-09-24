-- 006: Row Level Security (RLS) en las tablas de la aplicación.
--
-- Por qué: Supabase (y cualquier despliegue con los roles `anon` y `authenticated`) expone las tablas del esquema
-- `public` por su API de datos (PostgREST). En proyectos existentes las tablas nuevas nacen con permisos para esos
-- roles, y cualquiera que tenga la clave pública `anon` del proyecto podría leerlas o escribirlas. Aquí eso incluye
-- `admin_users` (hashes de contraseña) y `sessions` (hashes de sesiones).
--
-- Qué hace:
--   1. Activa RLS en cada tabla de la aplicación. Sin ninguna política, RLS niega todo a quien no sea el dueño de la
--      tabla. La aplicación se conecta como el DUEÑO (en Supabase, el rol `postgres`), que no está sujeto a RLS, así
--      que no le afecta. (Por eso la app debe conectarse siempre con el rol que creó las tablas.)
--   2. Si existen los roles `anon` / `authenticated`, les retira además los permisos sobre estas tablas y sus
--      secuencias: una segunda barrera, por si algún día alguien añade una política por descuido.
--
-- Qué NO hace, a propósito: solo toca las tablas de esta aplicación (lista explícita). Si el proyecto de Supabase
-- aloja otras cosas, no se les activa RLS ni se les quitan permisos. Tampoco cambia los permisos por defecto de las
-- tablas futuras: por eso cada migración que cree una tabla debe activar RLS en ella (lo vigila una prueba).
--
-- En un PostgreSQL corriente (sin esos roles) el efecto es nulo para la aplicación: el dueño no está sujeto a RLS.

do $$
declare
  ours text[] := array[
    'profile', 'now_items', 'technologies', 'tool_groups', 'tool_group_items', 'experiences', 'projects',
    'project_highlights', 'project_technologies', 'courses', 'workshops', 'education', 'certification_groups',
    'certifications', 'contact_links', 'admin_users', 'sessions', 'page_views', 'daily_salts', 'daily_visitors',
    'schema_migrations'
  ];
  api_roles text[] := array['anon', 'authenticated'];
  t text;
  r text;
  seq text;
begin
  foreach t in array ours loop
    if to_regclass(format('public.%I', t)) is null then
      continue;
    end if;

    execute format('alter table public.%I enable row level security', t);

    -- pg_get_serial_sequence LANZA un error (no devuelve nulo) si la tabla no tiene esa columna: `sessions`,
    -- `page_views` o `profile` no tienen un `id` con secuencia, así que solo se consulta cuando la columna existe.
    seq := null;
    if exists (select 1 from pg_attribute
                where attrelid = format('public.%I', t)::regclass and attname = 'id' and not attisdropped) then
      seq := pg_get_serial_sequence(format('public.%I', t), 'id');
    end if;

    foreach r in array api_roles loop
      if exists (select 1 from pg_roles where rolname = r) then
        execute format('revoke all on table public.%I from %I', t, r);
        if seq is not null then
          execute format('revoke all on sequence %s from %I', seq, r);
        end if;
      end if;
    end loop;
  end loop;
end
$$;
