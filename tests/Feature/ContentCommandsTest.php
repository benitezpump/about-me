<?php

namespace Tests\Feature;

use App\Content\ContentImporter;
use App\Content\SeedContent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentCommandsTest extends TestCase
{
    use DatabaseTransactions;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'content-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
        parent::tearDown();
    }

    public function test_seed_carga_el_contenido_si_la_base_esta_vacia_y_no_pisa_lo_existente(): void
    {
        $this->artisan('content:seed')->expectsOutputToContain('Contenido inicial cargado')->assertSuccessful();
        $this->assertSame(1, DB::table('profile')->count());

        DB::table('profile')->update(['headline' => 'Editado a mano.']);
        $this->artisan('content:seed')->expectsOutputToContain('ya tiene contenido')->assertSuccessful();
        $this->assertSame('Editado a mano.', DB::table('profile')->value('headline'), 'no se pisó');
    }

    public function test_seed_con_force_reemplaza_todo(): void
    {
        $this->artisan('content:seed')->assertSuccessful();
        DB::table('profile')->update(['headline' => 'Editado a mano.']);

        $this->artisan('content:seed', ['--force' => true])->expectsOutputToContain('Contenido reemplazado')->assertSuccessful();

        $this->assertNotSame('Editado a mano.', DB::table('profile')->value('headline'));
    }

    public function test_seed_if_empty_env_respeta_seed_on_empty(): void
    {
        config(['security.seed_on_empty' => false]);
        $this->artisan('content:seed', ['--if-empty-env' => true])->expectsOutputToContain('SEED_ON_EMPTY no está activo')->assertSuccessful();
        $this->assertSame(0, DB::table('profile')->count());

        config(['security.seed_on_empty' => true]);
        $this->artisan('content:seed', ['--if-empty-env' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('profile')->count());
    }

    public function test_seed_usa_el_de_ejemplo_si_no_hay_contenido_real_y_avisa_cual(): void
    {
        $esperado = basename(SeedContent::file());

        $this->artisan('content:seed')->expectsOutputToContain($esperado)->assertSuccessful();
        $this->assertContains($esperado, ['content.json', 'content.example.json']);
    }

    public function test_un_archivo_inicial_roto_falla_con_un_mensaje_claro_y_no_toca_la_base(): void
    {
        file_put_contents($this->tmp, '{"profile": {"first_names": "x"}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no es válido');
        try {
            SeedContent::run(false, $this->tmp);
        } finally {
            $this->assertSame(0, DB::table('profile')->count());
        }
    }

    public function test_export_imprime_o_escribe_el_json_y_sin_perfil_avisa(): void
    {
        $this->artisan('content:export')->expectsOutputToContain('Todavía no hay perfil')->assertFailed();

        (new ContentImporter)->replace(SeedContent::load(SeedContent::exampleFile()));
        $this->artisan('content:export', ['file' => $this->tmp])->expectsOutputToContain('exportado')->assertSuccessful();

        $json = json_decode((string) file_get_contents($this->tmp), true);
        $this->assertSame('about-me-content', $json['format']);
        $this->assertStringEndsWith("\n", (string) file_get_contents($this->tmp));
    }

    public function test_import_valida_pide_confirmacion_y_reemplaza(): void
    {
        (new ContentImporter)->replace(SeedContent::load(SeedContent::exampleFile()));
        $data = json_decode((string) file_get_contents(SeedContent::exampleFile()), true);
        $data['profile']['headline'] = 'Titular por CLI.';
        file_put_contents($this->tmp, json_encode($data));

        $this->artisan('content:import', ['file' => $this->tmp])->expectsConfirmation('Esto reemplaza TODO el contenido actual del sitio. ¿Continuar?', 'no')
            ->expectsOutputToContain('Cancelado')->assertFailed();
        $this->assertNotSame('Titular por CLI.', DB::table('profile')->value('headline'));

        $this->artisan('content:import', ['file' => $this->tmp, '--yes' => true])->expectsOutputToContain('Contenido importado')->assertSuccessful();
        $this->assertSame('Titular por CLI.', DB::table('profile')->value('headline'));
    }

    public function test_import_con_un_archivo_invalido_o_inexistente_no_cambia_nada(): void
    {
        (new ContentImporter)->replace(SeedContent::load(SeedContent::exampleFile()));
        $antes = DB::table('profile')->value('headline');

        $this->artisan('content:import', ['file' => $this->tmp.'.nada', '--yes' => true])->expectsOutputToContain('No existe')->assertFailed();

        file_put_contents($this->tmp, '{"profile": {"first_names": "x"}, "extra": 1}');
        $this->artisan('content:import', ['file' => $this->tmp, '--yes' => true])->expectsOutputToContain('No se importó nada')->assertFailed();

        $this->assertSame($antes, DB::table('profile')->value('headline'));
    }
}
