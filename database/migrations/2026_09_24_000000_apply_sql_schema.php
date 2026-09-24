<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aplica el esquema del sitio, que vive en SQL plano (database/sql/*.sql), no en el constructor de esquemas de Laravel:
 * usa restricciones NOT VALID, llaves foráneas compuestas, Row Level Security y comentarios que el constructor no expresa.
 *
 * Es compatible con la aplicación anterior (Node): usa la misma tabla `schema_migrations`, así que sobre una base que ya
 * estaba migrada por ella no repite nada, y las dos aplicaciones pueden convivir sobre la misma base durante la transición.
 * Regla del proyecto: nunca se edita un archivo SQL ya aplicado; se añade el siguiente.
 */
return new class extends Migration
{
    public function up(): void
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

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            DB::unprepared(file_get_contents($file));
            DB::table('schema_migrations')->insert(['name' => $name]);
        }
    }

    public function down(): void
    {
        // Sin marcha atrás a propósito: revertirlo borraría el contenido del sitio.
    }
};
