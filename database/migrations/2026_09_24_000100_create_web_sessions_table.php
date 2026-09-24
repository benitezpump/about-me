<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones del panel. La tabla `sessions` ya existe con el formato de la app anterior (token_hash, csrf…), por eso esta se
 * llama `web_sessions` (SESSION_TABLE). Las sesiones viven en la base y no en disco: el contenedor de Render es efímero y
 * cada despliegue cerraría la sesión del administrador.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('web_sessions')) {
            return;
        }

        Schema::create('web_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // Supabase expone `public` por su API de datos: sin RLS la tabla quedaría abierta a la clave pública.
        DB::unprepared('alter table web_sessions enable row level security');
    }

    public function down(): void
    {
        Schema::dropIfExists('web_sessions');
    }
};
