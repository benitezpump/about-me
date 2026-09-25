<?php

namespace Tests\Feature;

use App\Content\ContentImporter;
use App\Content\SeedContent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Fidelidad con el sitio original (portada de tests/fidelity.test.ts de la versión Node).
 *
 * Compara el sitio generado desde tu contenido REAL (`database/seed/content.json`) con el HTML de origen
 * (`legacy/index.static.html`). Ninguno de los dos archivos se sube al repositorio (contienen datos personales; ver
 * .gitignore), así que esta prueba solo corre en tu máquina y se OMITE donde faltan, por ejemplo en la integración continua.
 * El resto de la suite usa contenido de ejemplo.
 */
class FidelityTest extends TestCase
{
    use DatabaseTransactions;

    private function legacy(): string
    {
        return base_path('legacy/index.static.html');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file($this->legacy()) || ! is_file(SeedContent::realFile())) {
            $this->markTestSkipped('Faltan legacy/index.static.html o database/seed/content.json (no se suben al repositorio).');
        }
    }

    private static function textOf(string $html): string
    {
        $html = preg_replace('#<script[\s\S]*?</script>#', '', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/\s+/u', '', $text) ?? $text;
    }

    private static function mainOf(string $html): string
    {
        return preg_match('#<main[\s\S]*</main>#', $html, $m) === 1 ? $m[0] : '';
    }

    public function test_renderiza_el_mismo_contenido_que_el_index_html_original(): void
    {
        (new ContentImporter)->replace(SeedContent::load(SeedContent::realFile()));

        $actualHtml = $this->get('/', ['User-Agent' => ''])->assertOk()->getContent();

        // Las líneas de tecnologías ya no son texto libre sino que salen del catálogo (y pierden algo de prosa, p. ej.
        // "en backend"): se verifican con las pruebas del catálogo. Todo lo demás debe ser idéntico.
        $stripStack = fn (string $html): string => preg_replace('#<(p|ul) class="stack[^"]*"[^>]*>[\s\S]*?</\1>#', '', $html) ?? $html;
        $expected = self::textOf($stripStack(self::mainOf((string) file_get_contents($this->legacy()))));
        $actual = self::textOf($stripStack(self::mainOf($actualHtml)));

        $this->assertGreaterThan(5000, mb_strlen($expected), 'el HTML original debería tener contenido');
        $this->assertSame($expected, $actual);
    }
}
