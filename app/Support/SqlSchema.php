<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Aplica los archivos de `database/sql/*.sql` que falten y los anota en `schema_migrations`, la misma tabla que usaba la
 * aplicación anterior (Node): sobre una base ya migrada por ella no repite nada.
 *
 * Laravel registra su migración `apply_sql_schema` una sola vez en `migrations` y no la vuelve a correr, así que un archivo
 * SQL añadido después (007, 008…) nunca llegaba a una base ya desplegada. Por eso esto se ejecuta también en cada
 * `php artisan migrate` (ver AppServiceProvider), no solo desde esa migración. Es idempotente.
 */
final class SqlSchema
{
    /** @return list<string> nombres de los archivos aplicados en esta llamada */
    public static function apply(): array
    {
        DB::unprepared('create table if not exists schema_migrations (
            name text primary key,
            applied_at timestamptz not null default now()
        )');

        // Supabase expone el esquema `public` por su API de datos: sin RLS, una tabla queda abierta a la clave pública.
        // Sin políticas, RLS niega todo a esos roles; el dueño (esta conexión) no está sujeto a ella.
        DB::unprepared('alter table schema_migrations enable row level security');
        DB::unprepared('alter table migrations enable row level security');

        $applied = DB::table('schema_migrations')->pluck('name')->all();

        $files = glob(database_path('sql/*.sql')) ?: [];
        sort($files, SORT_STRING);

        $new = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            DB::unprepared(file_get_contents($file));
            DB::table('schema_migrations')->insert(['name' => $name]);
            $new[] = $name;
        }

        return $new;
    }
}
