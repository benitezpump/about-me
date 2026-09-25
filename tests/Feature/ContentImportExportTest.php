<?php

namespace Tests\Feature;

use App\Content\ContentExporter;
use App\Content\ContentImporter;
use App\Content\ContentParser;
use App\Content\ContentSerializer;
use App\Content\NoContentException;
use App\Content\SeedContent;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Services\SiteContent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Portada de "panel: importar y exportar" (Node): ida y vuelta, reemplazo transaccional y cobertura de columnas. */
class ContentImportExportTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array<string, mixed> */
    private function example(): array
    {
        return SeedContent::load(SeedContent::exampleFile());
    }

    private function cargarEjemplo(): void
    {
        (new ContentImporter)->replace($this->example());
    }

    private function exportText(): string
    {
        return ContentSerializer::serialize((new ContentExporter)->export());
    }

    private function home(): string
    {
        return $this->get('/', ['User-Agent' => ''])->assertOk()->getContent();
    }

    public function test_importar_lo_exportado_deja_el_contenido_y_el_sitio_identicos(): void
    {
        $this->cargarEjemplo();
        $antes = $this->exportText();
        $sitio = $this->home();

        (new ContentImporter)->replace(ContentParser::parseText($antes)->data);

        $this->assertSame($antes, $this->exportText(), 'ida y vuelta sin pérdidas');
        $this->assertSame($sitio, $this->home(), 'el sitio se ve igual');
    }

    public function test_el_contenido_inicial_de_ejemplo_se_carga_completo(): void
    {
        $this->cargarEjemplo();
        $data = $this->example();
        $proyectos = array_merge(...array_map(fn ($e) => $e['projects'], $data['experiences']));

        $this->assertSame(1, DB::table('profile')->count());
        $this->assertSame(count($data['experiences']), DB::table('experiences')->count());
        $this->assertSame(count($proyectos) + count($data['personal_projects']), DB::table('projects')->count());
        $this->assertSame(['Principales', 'En proyectos'], DB::table('tool_groups')->orderBy('position')->pluck('label')->all());
        $this->assertSame(0, DB::table('projects')->where('stack', '<>', '')->count(), 'el texto libre ya no se guarda al sembrar');
        $this->assertSame(
            DB::table('technologies')->count(),
            DB::table('technologies')->distinct()->count(DB::raw('lower(name)')),
            'una fila por tecnología distinta',
        );
    }

    public function test_las_tecnologias_se_reutilizan_del_catalogo_sin_distinguir_mayusculas(): void
    {
        $data = $this->example();
        $data['personal_projects'][] = [
            'title' => 'Otro', 'description' => '', 'stack' => '', 'start_date' => '2024-05-01', 'end_date' => null, 'period_label' => null,
            'visible' => true, 'technologies' => [['name' => 'node.js', 'note' => null], ['name' => 'Zig', 'note' => 'nueva']], 'highlights' => [],
        ];

        (new ContentImporter)->replace($data);

        $this->assertSame(1, DB::table('technologies')->whereRaw("lower(name) = 'node.js'")->count());
        $this->assertSame('Node.js', DB::table('technologies')->whereRaw("lower(name) = 'node.js'")->value('name'), 'gana la primera escritura');
        $this->assertStringContainsString('<ul class="stack chips" aria-label="Tecnologías"><li>Node.js</li><li>Zig (nueva)</li></ul>', $this->home());
    }

    public function test_reemplazar_borra_lo_que_no_viene_invalida_la_cache_y_no_toca_administradores_ni_visitas(): void
    {
        $this->cargarEjemplo();
        AdminUser::create(['username' => 'ana', 'password_hash' => Hash::make('una contraseña larga y segura')]);
        DB::table('page_views')->insert(['day' => '2026-01-01', 'views' => 5, 'visitors' => 3]);
        $this->home(); // calienta la caché

        $data = $this->example();
        $data['profile']['headline'] = 'Titular importado.';
        $data['now_items'] = [['since_label' => 'Desde hoy', 'title' => 'Probando', 'body' => 'la importación.', 'visible' => true]];
        (new ContentImporter)->replace($data);

        $html = $this->home();
        $this->assertStringContainsString('Titular importado.', $html, 'se ve sin esperar los 60 s de la caché');
        $this->assertSame(1, DB::table('now_items')->count(), 'lo que no viene en el archivo desaparece');
        $this->assertSame(1, AdminUser::count());
        $this->assertSame(5, (int) DB::table('page_views')->value('views'));
    }

    public function test_si_la_base_rechaza_algo_revierte_todo_nada_queda_a_medias(): void
    {
        $this->cargarEjemplo();
        $antes = $this->exportText();
        // Pasa el validador pero la base lo rechaza: una restricción que el validador no replica a propósito.
        DB::statement("alter table education add constraint tmp_test_reject check (institution <> 'RECHAZAR')");

        $data = $this->example();
        $data['profile']['headline'] = 'No debe quedar guardado.';
        $data['education'][] = ['title' => 'x', 'institution' => 'RECHAZAR', 'period_label' => 'p'];

        try {
            (new ContentImporter)->replace($data);
            $this->fail('la base debía rechazar el contenido');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('tmp_test_reject', $e->getMessage());
        }

        DB::statement('alter table education drop constraint tmp_test_reject');
        $this->assertSame($antes, $this->exportText(), 'el truncado se revirtió junto con lo demás');
        $this->assertStringNotContainsString('No debe quedar guardado', $this->home());
    }

    public function test_sin_perfil_no_hay_nada_que_exportar(): void
    {
        $this->expectException(NoContentException::class);

        (new ContentExporter)->export();
    }

    public function test_el_orden_de_las_listas_es_el_orden_en_el_sitio(): void
    {
        $data = $this->example();
        $data['tool_groups'] = array_reverse($data['tool_groups']);
        (new ContentImporter)->replace($data);

        $exportado = (new ContentExporter)->export();
        $this->assertSame(array_column($data['tool_groups'], 'label'), array_column($exportado['tool_groups'], 'label'));
        $this->assertSame([0, 1], DB::table('tool_groups')->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all());
    }

    public function test_los_identificadores_se_reinician_al_reemplazar(): void
    {
        $this->cargarEjemplo();
        $this->cargarEjemplo();

        $this->assertSame(1, (int) DB::table('experiences')->min('id'), 'restart identity: no se acumulan ids entre importaciones');
    }

    public function test_cobertura_cada_columna_de_cada_tabla_de_contenido_esta_en_el_formato_o_se_deriva_a_proposito(): void
    {
        // Si añades una columna o una tabla editable, esta prueba falla hasta que decidas qué hace el formato JSON con ella
        // (ContentParser, ContentExporter, ContentImporter) y la anotes aquí. Sin esto, exportar/importar perdería el dato.
        $derived = ['id', 'position', 'created_at', 'updated_at', 'experience_kind']; // las genera la base o salen del orden
        $covered = [
            'profile' => ['first_names', 'last_names', 'display_name', 'site_title', 'headline', 'location', 'intro', 'cta_label', 'cta_url',
                'contact_prompt', 'meta_description', 'og_description', 'show_view_count'],
            'contact_links' => ['label', 'url'],
            'now_items' => ['since_label', 'title', 'body', 'visible'],
            'tool_groups' => ['label', 'emphasis'],
            'technologies' => ['name'],
            'tool_group_items' => ['group_id', 'technology_id'],
            'experiences' => ['kind', 'company', 'article', 'role', 'location', 'start_date', 'end_date', 'show_since', 'workshops_title'],
            'projects' => ['experience_id', 'kind', 'title', 'description', 'stack', 'start_date', 'end_date', 'period_label', 'visible'],
            'project_highlights' => ['project_id', 'label', 'body'],
            'project_technologies' => ['project_id', 'technology_id', 'note'],
            'courses' => ['experience_id', 'subject', 'start_date', 'end_date'],
            'workshops' => ['experience_id', 'name', 'period_label', 'sort_date'],
            'education' => ['title', 'institution', 'period_label', 'professional_license'],
            'certification_groups' => ['title'],
            'certifications' => ['group_id', 'name', 'issuer', 'year', 'note', 'url'],
        ];

        $tablas = array_keys($covered);
        sort($tablas);
        $esperadas = ContentImporter::TABLES;
        sort($esperadas);
        $this->assertSame($esperadas, $tablas, 'las tablas de contenido cambiaron');

        foreach (ContentImporter::TABLES as $table) {
            $cols = DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', $table)->pluck('column_name')->all();
            $desconocidas = array_values(array_diff($cols, $covered[$table], $derived));
            $this->assertSame([], $desconocidas, "{$table}: columnas que el formato JSON no contempla (ContentParser / ContentExporter / ContentImporter)");
        }
    }

    public function test_ninguna_otra_tabla_de_la_aplicacion_se_vacia_al_importar(): void
    {
        $vaciadas = ContentImporter::TABLES;
        $noContenido = ['admin_users', 'web_sessions', 'sessions', 'page_views', 'daily_salts', 'daily_visitors', 'schema_migrations', 'migrations'];

        $this->assertSame([], array_values(array_intersect($vaciadas, $noContenido)), 'importar no debe tocar usuarios, sesiones ni visitas');
        $this->assertInstanceOf(Profile::class, new Profile);
        $this->assertTrue(class_exists(SiteContent::class));
    }
}
