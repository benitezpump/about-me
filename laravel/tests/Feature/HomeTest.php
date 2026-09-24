<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixture;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use DatabaseTransactions;

    private function home(): string
    {
        return $this->get('/')->assertOk()->getContent();
    }

    public function test_muestra_el_perfil_y_los_parrafos_de_la_introduccion(): void
    {
        Fixture::insert();
        $html = $this->home();

        $this->assertStringContainsString('<title>Ana Prueba, desarrolladora</title>', $html);
        $this->assertStringContainsString('<h1><span>Ana</span><span>Prueba Ejemplo</span></h1>', $html);
        $this->assertStringContainsString('<p class="lead">Primer párrafo.</p>', $html);
        $this->assertStringContainsString('<p class="lead">Segundo, con coma y &quot;comillas&quot;.</p>', $html, 'el arreglo de PostgreSQL se lee bien');
    }

    public function test_los_proyectos_salen_del_mas_reciente_al_mas_antiguo_y_el_oculto_no_aparece(): void
    {
        Fixture::insert();
        $html = $this->home();

        $this->assertLessThan(strpos($html, 'Proyecto antiguo'), strpos($html, 'Proyecto reciente'));
        $this->assertStringNotContainsString('Proyecto oculto', $html);
        $this->assertStringContainsString('<li class="current">', $html, 'el proyecto sin fecha de fin está en curso');
        $this->assertSame(1, substr_count($html, 'class="current"'));
        $this->assertMatchesRegularExpression('#02/2023 – <span class="now">actualidad</span>#', $html);
        $this->assertStringContainsString('03/2021 – 11/2022', $html);
    }

    public function test_las_tecnologias_salen_del_catalogo_con_su_nota_y_el_texto_libre_es_respaldo(): void
    {
        Fixture::insert();
        $html = $this->home();

        $this->assertStringContainsString('<p class="stack">PHP, Laravel (reportes).</p>', $html);
        $this->assertStringContainsString('<p class="stack">Texto libre heredado.</p>', $html);
        $this->assertStringContainsString('<dd>Node.js, PHP.</dd>', $html, 'las herramientas respetan su orden');
    }

    public function test_el_detalle_muestra_la_etiqueta_solo_si_existe(): void
    {
        Fixture::insert();
        $html = $this->home();

        $this->assertStringContainsString('<li><strong>Reportes:</strong> informes en PDF.</li>', $html);
        $this->assertStringContainsString('<li>Sin etiqueta.</li>', $html);
        $this->assertSame(1, substr_count($html, '<details>'), 'solo el proyecto con puntos de detalle');
    }

    public function test_el_contenido_se_escapa_nada_se_inserta_como_html(): void
    {
        Fixture::insert();
        $html = $this->home();

        $this->assertStringContainsString('Con &lt;b&gt;HTML&lt;/b&gt; &amp; más.', $html);
        $this->assertStringNotContainsString('<b>HTML</b>', $html);
    }

    public function test_secciones_y_grupos_vacios_desaparecen(): void
    {
        Fixture::insert();
        $html = $this->home();

        $this->assertStringNotContainsString('Vacío', $html, 'grupo de herramientas sin tecnologías');
        $this->assertStringNotContainsString('Grupo vacío', $html, 'grupo de certificaciones sin certificados');
        $this->assertStringNotContainsString('No se ve', $html, '"Actualmente" oculto');

        DB::table('courses')->delete();
        DB::table('workshops')->delete();
        DB::table('experiences')->where('kind', 'teaching')->delete();
        $sin = $this->home();
        $this->assertStringNotContainsString('id="docencia"', $sin);
        $this->assertStringNotContainsString('href="#docencia"', $sin, 'y sale también de la navegación');
    }

    public function test_docencia_con_articulo_materias_y_talleres(): void
    {
        Fixture::insert();
        $html = $this->home();

        $this->assertStringContainsString('Profesora en el Instituto Ejemplo</strong>, Ciudad Ejemplo.', $html);
        $this->assertStringContainsString('<h3>Talleres que impartí</h3>', $html);
        $this->assertStringContainsString('<dt>07 – 10 nov 2025</dt><dd>Docker</dd>', $html);
        $this->assertStringContainsString('08/2025 – <span class="now">actualidad</span>', $html);
    }

    public function test_trabajo_muestra_desde_cuando_y_ubicacion_sin_espacios_de_mas(): void
    {
        Fixture::insert();

        $this->assertStringContainsString(
            'Desarrolladora en Acme Software</strong>, Ciudad Ejemplo, desde enero de 2020. Proyectos del más reciente al más antiguo:',
            $this->home(),
        );
    }

    public function test_los_proyectos_propios_respetan_el_texto_libre_del_periodo(): void
    {
        Fixture::insert();

        $this->assertMatchesRegularExpression('#<article class="project">\s*<p class="when">21 jul – 19 oct 2022</p>#', $this->home());
    }

    public function test_certificaciones_enlaces_y_orden_por_ano(): void
    {
        Fixture::insert();
        $html = $this->home();

        $this->assertStringContainsString('<a href="https://example.com/a" target="_blank" rel="noopener noreferrer">Curso viejo</a>, Plataforma.', $html);
        $this->assertStringContainsString('<dd>Curso nuevo. Vigente.</dd>', $html);
        $this->assertStringContainsString('<dt>2020</dt>', $html);
    }

    public function test_solo_los_enlaces_http_abren_pestana_nueva_y_el_texto_visible_es_limpio(): void
    {
        Fixture::insert();
        $html = $this->home();

        $this->assertStringContainsString('<a href="https://www.github.com/ana/" target="_blank" rel="noopener noreferrer">github.com/ana</a>', $html);
        $this->assertStringContainsString('<a href="mailto:ana@example.com">ana@example.com</a>', $html, 'mailto no abre pestaña nueva');
    }

    public function test_sirve_el_favicon_con_las_iniciales_y_cabeceras_de_cache(): void
    {
        Fixture::insert();

        $this->get('/favicon.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('>AP<', false);
        $this->assertStringContainsString('max-age=0', (string) $this->get('/')->headers->get('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', (string) $this->get('/')->headers->get('Cache-Control'));
    }

    public function test_todo_el_javascript_es_externo(): void
    {
        Fixture::insert();
        $html = $this->home();

        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $html);
        $this->assertDoesNotMatchRegularExpression('/\sonclick=/', $html);
    }
}
