<?php

namespace Tests\Feature;

use App\Content\ContentImporter;
use App\Content\SeedContent;
use App\Filament\Pages\ImportarExportar;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AsAdmin;
use Tests\TestCase;

/** Pantalla "Importar y exportar" del panel (portada de tests "panel: importar y exportar" de la versión Node). */
class AdminImportExportPageTest extends TestCase
{
    use AsAdmin, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAsAdmin();
        (new ContentImporter)->replace(SeedContent::load(SeedContent::exampleFile()));
    }

    /** @return array<string, mixed> el contenido de ejemplo, ya decodificado */
    private function example(): array
    {
        return json_decode((string) file_get_contents(SeedContent::exampleFile()), true);
    }

    public function test_exige_sesion_y_aparece_en_la_navegacion(): void
    {
        auth()->logout();
        $this->get('/admin/importar-exportar')->assertRedirect('/admin/login');

        $this->loginAsAdmin2();
        $html = $this->get('/admin/importar-exportar')->assertOk()->getContent();
        $this->assertStringContainsString('Importar y exportar', $html);
        $this->assertStringContainsString('Reemplaza TODO el contenido actual', $html);
        $this->assertStringContainsString('Descargar contenido (JSON)', $html);
        $this->get('/admin/proyectos')->assertSee('href="'.url('/admin/importar-exportar').'"', false);
    }

    private function loginAsAdmin2(): void
    {
        $this->actingAs(AdminUser::firstOrFail());
    }

    public function test_el_archivo_lo_lee_el_navegador_no_se_sube_nada_al_servidor(): void
    {
        $html = $this->get('/admin/importar-exportar')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*type="file"[^>]*accept="\.json/', $html);
        $this->assertStringContainsString('FileReader', $html, 'el navegador lee el texto');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="file"[^>]*wire:model/', $html, 'sin subida de archivos de Livewire');
        $this->assertStringNotContainsString('livewire-tmp', $html);
    }

    public function test_exporta_un_json_descargable_sin_datos_de_acceso(): void
    {
        $page = Livewire::test(ImportarExportar::class)->callAction('export');

        $page->assertFileDownloaded('about-me-'.now()->format('Y-m-d').'.json');
    }

    public function test_sin_perfil_avisa_en_lugar_de_descargar_un_archivo_roto(): void
    {
        DB::table('profile')->delete();

        Livewire::test(ImportarExportar::class)->callAction('export')
            ->assertNotified('Todavía no hay perfil, así que no hay contenido que exportar.');
    }

    public function test_importar_un_contenido_valido_lo_reemplaza_y_el_sitio_lo_muestra(): void
    {
        $data = $this->example();
        $data['profile']['headline'] = 'Titular importado desde el panel.';

        Livewire::test(ImportarExportar::class)
            ->fillForm(['text' => json_encode($data), 'confirm' => true])
            ->call('import')
            ->assertHasNoFormErrors()
            ->assertSet('issues', [])
            ->assertNotified('Se importó el contenido. El sitio ya lo muestra.');

        $this->assertSame('Titular importado desde el panel.', DB::table('profile')->value('headline'));
        $this->get('/')->assertSee('Titular importado desde el panel.');
    }

    public function test_un_archivo_invalido_no_cambia_nada_y_dice_donde_esta_cada_problema(): void
    {
        $antes = DB::table('profile')->value('headline');
        $data = $this->example();
        $data['experiences'][0]['start_date'] = '2020-99-99';
        $data['contact_links'] = [['label' => 'x', 'url' => 'javascript:alert(1)']];

        $page = Livewire::test(ImportarExportar::class)
            ->fillForm(['text' => json_encode($data), 'confirm' => true])
            ->call('import');

        $issues = implode("\n", $page->get('issues'));
        $this->assertStringContainsString('experiences[0].start_date', $issues);
        $this->assertStringContainsString('contact_links[0].url', $issues);
        $this->assertSame($antes, DB::table('profile')->value('headline'));

        $page->assertSee('No se importó nada');
    }

    public function test_un_texto_que_no_es_json_da_un_mensaje_claro(): void
    {
        $page = Livewire::test(ImportarExportar::class)
            ->fillForm(['text' => '{ esto no es json', 'confirm' => true])
            ->call('import');

        $this->assertStringContainsString('no es JSON válido', $page->get('issues')[0]);
    }

    public function test_exige_confirmar_y_tener_contenido(): void
    {
        $antes = DB::table('profile')->value('headline');

        Livewire::test(ImportarExportar::class)
            ->fillForm(['text' => json_encode($this->example()), 'confirm' => false])
            ->call('import')
            ->assertHasFormErrors(['confirm']);

        Livewire::test(ImportarExportar::class)
            ->fillForm(['text' => '', 'confirm' => true])
            ->call('import')
            ->assertHasFormErrors(['text' => 'required']);

        $this->assertSame($antes, DB::table('profile')->value('headline'));
    }

    public function test_el_texto_pegado_se_escapa_al_mostrarlo_no_ejecuta_nada(): void
    {
        $page = Livewire::test(ImportarExportar::class)
            ->fillForm(['text' => '</textarea><script>alert(1)</script>', 'confirm' => true])
            ->call('import');

        $html = $page->html();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_si_la_base_rechaza_algo_revierte_y_dice_que_regla(): void
    {
        DB::statement("alter table education add constraint tmp_page_reject check (institution <> 'RECHAZAR')");
        $antes = DB::table('profile')->value('headline');
        $data = $this->example();
        $data['profile']['headline'] = 'No debe quedar guardado.';
        $data['education'][] = ['title' => 'x', 'institution' => 'RECHAZAR', 'period_label' => 'p'];

        $page = Livewire::test(ImportarExportar::class)
            ->fillForm(['text' => json_encode($data), 'confirm' => true])
            ->call('import');

        $this->assertStringContainsString('La base de datos rechazó el contenido (regla tmp_page_reject). No se cambió nada.', $page->get('issues')[0]);
        DB::statement('alter table education drop constraint tmp_page_reject');
        $this->assertSame($antes, DB::table('profile')->value('headline'));
    }
}
