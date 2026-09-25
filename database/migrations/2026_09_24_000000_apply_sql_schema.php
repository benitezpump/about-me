<?php

use App\Support\SqlSchema;
use Illuminate\Database\Migrations\Migration;

/**
 * Aplica el esquema del sitio, que vive en SQL plano (database/sql/*.sql), no en el constructor de esquemas de Laravel:
 * usa restricciones NOT VALID, llaves foráneas compuestas, Row Level Security y comentarios que el constructor no expresa.
 *
 * Es compatible con la aplicación anterior (Node): usa la misma tabla `schema_migrations`, así que sobre una base que ya
 * estaba migrada por ella no repite nada, y las dos aplicaciones pueden convivir sobre la misma base durante la transición.
 * Regla del proyecto: nunca se edita un archivo SQL ya aplicado; se añade el siguiente.
 *
 * OJO: Laravel corre esta migración una sola vez por base. Los archivos SQL posteriores los aplica `SqlSchema` en cada
 * `migrate` (AppServiceProvider); no dependas de que esta clase vuelva a ejecutarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        SqlSchema::apply();
    }

    public function down(): void
    {
        // Sin marcha atrás a propósito: revertirlo borraría el contenido del sitio.
    }
};
