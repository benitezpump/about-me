<?php

namespace Tests\Unit;

use App\Content\ContentParser;
use App\Content\ContentSerializer;
use App\Content\ParseResult;
use PHPUnit\Framework\TestCase;

/** Portada de tests/content-data.test.ts (versión Node): el formato JSON del contenido y su validación. */
class ContentParserTest extends TestCase
{
    private function example(): string
    {
        return dirname(__DIR__, 2).'/database/seed/content.example.json';
    }

    /** Contenido mínimo válido, para romperlo de a una cosa. @return array<string, mixed> */
    private function minimal(): array
    {
        return ['profile' => [
            'first_names' => 'Ada', 'last_names' => 'Lovelace', 'display_name' => 'Ada', 'site_title' => 'Ada',
            'headline' => 'Programadora.', 'intro' => ['Hola.'],
        ]];
    }

    /** @return list<string> */
    private function issuesOf(mixed $value): array
    {
        $r = (new ContentParser)->parse($value);
        $this->assertFalse($r->ok, 'debía fallar');

        return $r->issues;
    }

    private function joined(mixed $value): string
    {
        return implode("\n", $this->issuesOf($value));
    }

    public function test_el_contenido_inicial_de_ejemplo_del_repositorio_es_valido(): void
    {
        $r = ContentParser::parseText((string) file_get_contents($this->example()));

        $this->assertTrue($r->ok, implode("\n", $r->issues));
        $this->assertGreaterThanOrEqual(2, count($r->data['experiences']));
        $this->assertNotEmpty($r->data['certification_groups']);
    }

    public function test_lo_minimo_es_un_perfil_y_todo_lo_demas_es_opcional_con_valores_por_defecto(): void
    {
        $r = (new ContentParser)->parse($this->minimal());

        $this->assertTrue($r->ok, implode("\n", $r->issues));
        $this->assertSame('', $r->data['profile']['location']);
        $this->assertTrue($r->data['profile']['show_view_count']);
        $this->assertSame([[], [], []], [$r->data['experiences'], $r->data['tool_groups'], $r->data['certification_groups']]);
    }

    public function test_serializar_y_volver_a_leer_da_lo_mismo_y_el_texto_es_estable(): void
    {
        $data = ContentParser::parseText((string) file_get_contents($this->example()))->data;
        $text = ContentSerializer::serialize($data);
        $again = ContentParser::parseText($text);

        $this->assertTrue($again->ok);
        $this->assertSame($data, $again->data);
        $this->assertSame($text, ContentSerializer::serialize($again->data), 'el texto es estable');
        $this->assertStringContainsString('"version": '.ContentParser::VERSION, $text);
    }

    public function test_el_serializador_da_exactamente_los_mismos_bytes_que_la_version_node(): void
    {
        // La referencia la escribió el serializador de la app Node (tests/fixtures/content.node-serialized.json, con casos
        // delicados: emoji, comillas, barras, acentos y un legacy_stack). El de PHP debe reproducirla al byte: sangría de
        // 2 espacios, unicode y barras sin escapar, orden de claves y listas según el tipo de experiencia.
        $original = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__).'/fixtures/content.node-serialized.json'));

        $parsed = ContentParser::parseText($original);
        $this->assertTrue($parsed->ok, implode("\n", $parsed->issues));
        $this->assertSame($original, ContentSerializer::serialize($parsed->data));
    }

    public function test_cada_experiencia_solo_lleva_las_listas_de_su_tipo(): void
    {
        $out = json_decode(ContentSerializer::serialize(ContentParser::parseText((string) file_get_contents($this->example()))->data), true);

        foreach ($out['experiences'] as $e) {
            if ($e['kind'] === 'work') {
                $this->assertArrayHasKey('projects', $e);
                $this->assertArrayNotHasKey('courses', $e);
                $this->assertArrayNotHasKey('workshops', $e);
            } else {
                $this->assertArrayNotHasKey('projects', $e);
                $this->assertArrayHasKey('courses', $e);
                $this->assertArrayHasKey('workshops', $e);
            }
        }
    }

    public function test_los_errores_dicen_donde_estan_y_se_juntan_todos_no_solo_el_primero(): void
    {
        $bad = $this->minimal() + [
            'experiences' => [['kind' => 'work', 'company' => 'X', 'role' => 'Y', 'start_date' => '2020-13-01', 'projects' => [['title' => '', 'start_date' => '2020-01-01']]]],
            'education' => [['title' => 'T']],
        ];
        $issues = $this->issuesOf($bad);

        $this->assertNotEmpty(array_filter($issues, fn ($i) => str_starts_with($i, 'experiences[0].start_date:') && str_contains($i, 'fecha válida')), implode("\n", $issues));
        $this->assertNotEmpty(array_filter($issues, fn ($i) => str_starts_with($i, 'experiences[0].projects[0].title:') && str_contains($i, 'vacío')));
        $this->assertNotEmpty(array_filter($issues, fn ($i) => str_starts_with($i, 'education[0].period_label:') && str_contains($i, 'obligatorio')));
    }

    public function test_rechaza_campos_desconocidos_un_error_de_tipeo_no_se_pierde_en_silencio(): void
    {
        $this->assertStringContainsString('education[0].institucion: campo desconocido',
            $this->joined($this->minimal() + ['education' => [['title' => 'T', 'period_label' => 'P', 'institucion' => 'X']]]));
        $this->assertStringContainsString('extra: campo desconocido', $this->joined($this->minimal() + ['extra' => 1]));
    }

    public function test_exige_coherencia_fechas_en_orden_enlaces_seguros_anio_razonable_y_tipos_de_experiencia(): void
    {
        $at = fn (array $patch): string => $this->joined($this->minimal() + $patch);
        $exp = fn (array $x): array => ['experiences' => [array_merge(['kind' => 'work', 'company' => 'X', 'role' => 'Y', 'start_date' => '2020-01-01'], $x)]];

        $this->assertStringContainsString('anterior al inicio', $at($exp(['start_date' => '2020-05-01', 'end_date' => '2020-01-01'])));
        $this->assertStringContainsString('el enlace debe empezar con', $at(['contact_links' => [['label' => 'x', 'url' => 'javascript:alert(1)']]]));
        $this->assertStringContainsString('el enlace debe empezar con', $at(['contact_links' => [['label' => 'x', 'url' => 'https://a b.com']]]));
        $this->assertStringContainsString('entre 1990 y 2100', $at(['certification_groups' => [['title' => 'G', 'items' => [['name' => 'n', 'year' => 1800]]]]]));
        $this->assertStringContainsString('año (número entero)', $at(['certification_groups' => [['title' => 'G', 'items' => [['name' => 'n', 'year' => '2020']]]]]));
        $this->assertStringContainsString('"work" o "teaching"', $at($exp(['kind' => 'freelance'])));
        $this->assertStringContainsString('de docencia no lleva "projects"', $at($exp(['kind' => 'teaching', 'projects' => [['title' => 'p', 'start_date' => '2020-01-01']]])));
        $this->assertStringContainsString('de trabajo no lleva', $at($exp(['courses' => [['subject' => 's', 'start_date' => '2020-01-01']]])));
        $this->assertStringContainsString('no es una fecha válida', $at($exp(['start_date' => '2021-02-30'])));
    }

    public function test_una_tecnologia_repetida_en_el_mismo_proyecto_o_grupo_se_rechaza_sin_distinguir_mayusculas(): void
    {
        $project = ['title' => 'p', 'start_date' => '2020-01-01', 'technologies' => [['name' => 'Node.js'], ['name' => 'node.js']]];

        $this->assertStringContainsString('repetida', $this->joined($this->minimal() + ['personal_projects' => [$project]]));
        $this->assertStringContainsString('repetida', $this->joined($this->minimal() + ['tool_groups' => [['label' => 'g', 'tools' => ['Go', 'GO']]]]));
    }

    public function test_las_mismas_longitudes_que_los_formularios(): void
    {
        $this->assertStringContainsString('máximo 1500', $this->joined($this->minimal() + ['personal_projects' => [['title' => 'p', 'start_date' => '2020-01-01', 'description' => str_repeat('x', 1501)]]]));
        $this->assertStringContainsString('máximo 80', $this->joined($this->minimal() + ['tool_groups' => [['label' => 'g', 'tools' => [str_repeat('y', 81)]]]]));
    }

    public function test_el_texto_que_no_es_json_un_arreglo_o_una_version_futura_dan_un_mensaje_claro(): void
    {
        $text = ContentParser::parseText('{ no es json');
        $this->assertFalse($text->ok);
        $this->assertStringContainsString('no es JSON válido', $text->issues[0]);

        $this->assertStringContainsString('objeto JSON', ContentParser::parseText('[]')->issues[0]);
        $this->assertStringContainsString('objeto JSON', ContentParser::parseText('[1, 2]')->issues[0]);
        $this->assertStringContainsString('versión 1, no 99', $this->joined($this->minimal() + ['version' => 99]));
        $this->assertStringContainsString('about-me-content', $this->joined($this->minimal() + ['format' => 'otra-cosa']));
        $this->assertStringContainsString('profile: es obligatorio', $this->joined([]));
    }

    public function test_tolera_el_bom_de_los_editores_de_windows_y_recorta_espacios(): void
    {
        $profile = ['headline' => '  Hola  '] + $this->minimal()['profile'];
        $r = ContentParser::parseText("\xEF\xBB\xBF".json_encode(['profile' => $profile]));

        $this->assertTrue($r->ok, implode("\n", $r->issues));
        $this->assertSame('Hola', $r->data['profile']['headline']);
    }

    public function test_con_muchos_errores_se_muestran_los_primeros_y_se_cuenta_el_resto(): void
    {
        $r = ContentParser::parseText(json_encode($this->minimal() + ['education' => array_fill(0, 40, new \stdClass)]));

        $this->assertFalse($r->ok);
        $this->assertCount(26, $r->issues);
        $issues = $r->issues;
        $this->assertMatchesRegularExpression('/y \d+ problemas más/', end($issues));
    }

    public function test_el_resultado_no_se_puede_construir_a_mano_y_conserva_sus_datos(): void
    {
        $ok = ParseResult::success(['a' => 1]);
        $bad = ParseResult::failure(['x']);

        $this->assertTrue($ok->ok);
        $this->assertNull($bad->data);
        $this->assertSame(['x'], $bad->issues);
    }
}
