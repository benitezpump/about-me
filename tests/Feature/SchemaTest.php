<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_aplica_los_archivos_sql_y_los_registra_como_la_app_anterior(): void
    {
        $this->assertSame(
            ['001_init.sql', '002_experience_article.sql', '003_page_views.sql', '004_integrity_and_indexes.sql', '005_technology_catalog.sql', '006_row_level_security.sql', '007_education_professional_license.sql'],
            DB::table('schema_migrations')->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_migrar_de_nuevo_no_repite_nada(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $this->assertStringContainsString('Nothing to migrate', Artisan::output());
        $this->assertSame(7, DB::table('schema_migrations')->count());
    }

    public function test_un_archivo_sql_nuevo_llega_a_una_base_que_ya_habia_migrado(): void
    {
        // Estado de la base desplegada antes del 007: Laravel ya registró `apply_sql_schema` y no la vuelve a correr, así que
        // el archivo nuevo solo llega si `migrate` lo aplica por su cuenta (falló en producción: la columna no existía).
        DB::unprepared('alter table education drop column professional_license');
        DB::table('schema_migrations')->where('name', '007_education_professional_license.sql')->delete();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertStringContainsString('Nothing to migrate', Artisan::output());
        $this->assertTrue(Schema::hasColumn('education', 'professional_license'));
        $this->assertSame(count(glob(database_path('sql/*.sql'))), DB::table('schema_migrations')->count());
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

    public function test_la_cedula_profesional_es_opcional_y_la_base_solo_acepta_digitos(): void
    {
        $insertar = fn (?string $cedula) => DB::table('education')->insert(
            ['title' => 'T', 'period_label' => 'P', 'professional_license' => $cedula],
        );
        $codigo = function (string $cedula) use ($insertar): string {
            try {
                DB::transaction(fn () => $insertar($cedula));
            } catch (\Illuminate\Database\QueryException $e) {
                return (string) $e->getCode();
            }

            return 'sin error';
        };

        $this->assertTrue($insertar(null));
        $this->assertTrue($insertar('12345678'));
        foreach (['', '12345', '12345678901', '12 345 67', 'ABC12345', '1234567a'] as $mala) {
            $this->assertSame('23514', $codigo($mala), "«{$mala}» debía violar el check");
        }
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
