<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_aplica_los_archivos_sql_y_los_registra_como_la_app_anterior(): void
    {
        $this->assertSame(
            ['001_init.sql', '002_experience_article.sql', '003_page_views.sql', '004_integrity_and_indexes.sql', '005_technology_catalog.sql', '006_row_level_security.sql'],
            DB::table('schema_migrations')->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_migrar_de_nuevo_no_repite_nada(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());
        $this->assertSame(6, DB::table('schema_migrations')->count());
    }

    public function test_una_base_ya_migrada_por_la_app_anterior_no_se_vuelve_a_tocar(): void
    {
        // Simula el estado de la base real: `schema_migrations` ya lista todo y las tablas existen; Laravel aún no corrió.
        DB::table('migrations')->delete();
        $antes = DB::table('schema_migrations')->pluck('applied_at', 'name')->all();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame($antes, DB::table('schema_migrations')->pluck('applied_at', 'name')->all());
        $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
    }

    public function test_toda_tabla_de_public_tiene_row_level_security(): void
    {
        // Supabase expone `public` por su API de datos: una tabla sin RLS queda abierta a la clave pública `anon`.
        $sinRls = DB::select(
            "select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
              where n.nspname = 'public' and c.relkind = 'r' and not c.relrowsecurity order by 1",
        );

        $this->assertSame([], array_map(fn ($r) => $r->relname, $sinRls));
    }

    public function test_los_datos_de_fecha_llegan_como_texto_sin_corrimientos_de_zona_horaria(): void
    {
        DB::table('experiences')->insert(['kind' => 'work', 'company' => 'X', 'role' => 'Y', 'start_date' => '2020-01-01']);

        $this->assertSame('2020-01-01', DB::table('experiences')->value('start_date'));
    }
}
