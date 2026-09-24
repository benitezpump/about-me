<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Contenido ficticio y mínimo para las pruebas de la portada. Se escribe con SQL directo (no con los modelos) para probar
 * la lectura contra el esquema real y no depender de cómo evolucione la escritura.
 */
final class Fixture
{
    public static function insert(): void
    {
        DB::table('profile')->insert([
            'first_names' => 'Ana', 'last_names' => 'Prueba Ejemplo', 'display_name' => 'Ana Prueba',
            'site_title' => 'Ana Prueba, desarrolladora', 'headline' => 'Desarrolladora de ejemplo.',
            'location' => 'Ciudad Ejemplo', 'intro' => '{"Primer párrafo.","Segundo, con coma y \"comillas\"."}',
            'cta_label' => 'Escríbeme por LinkedIn', 'cta_url' => 'https://www.linkedin.com/in/ejemplo',
            'contact_prompt' => '¿Hablamos?', 'meta_description' => 'Descripción', 'og_description' => 'Al compartir',
        ]);

        DB::table('now_items')->insert([
            ['since_label' => 'Desde 01/2020', 'title' => 'Desarrolladora', 'body' => 'en Acme.', 'position' => 0, 'visible' => true],
            ['since_label' => 'Oculto', 'title' => 'No se ve', 'body' => '', 'position' => 1, 'visible' => false],
        ]);

        foreach (['PHP', 'Laravel', 'Node.js'] as $name) {
            DB::table('technologies')->insert(['name' => $name]);
        }
        $tech = DB::table('technologies')->pluck('id', 'name');

        $group = DB::table('tool_groups')->insertGetId(['label' => 'Principales', 'emphasis' => true, 'position' => 0]);
        DB::table('tool_groups')->insert(['label' => 'Vacío', 'emphasis' => false, 'position' => 1]); // sin tecnologías: no aparece
        DB::table('tool_group_items')->insert([
            ['group_id' => $group, 'technology_id' => $tech['Node.js'], 'position' => 0],
            ['group_id' => $group, 'technology_id' => $tech['PHP'], 'position' => 1],
        ]);

        $work = DB::table('experiences')->insertGetId([
            'kind' => 'work', 'company' => 'Acme Software', 'role' => 'Desarrolladora', 'location' => 'Ciudad Ejemplo',
            'start_date' => '2020-01-01', 'show_since' => true, 'position' => 0,
        ]);
        $project = fn (array $row) => DB::table('projects')->insertGetId($row + [
            'experience_id' => $work, 'kind' => 'work', 'description' => '', 'stack' => '', 'visible' => true, 'position' => 0,
        ]);
        $project(['title' => 'Proyecto antiguo', 'start_date' => '2021-03-01', 'end_date' => '2022-11-01', 'stack' => 'Texto libre heredado.']);
        $reciente = $project(['title' => 'Proyecto reciente', 'start_date' => '2023-02-01', 'description' => 'Con <b>HTML</b> & más.']);
        $project(['title' => 'Proyecto oculto', 'start_date' => '2024-01-01', 'visible' => false]);
        DB::table('project_technologies')->insert([
            ['project_id' => $reciente, 'technology_id' => $tech['PHP'], 'note' => null, 'position' => 0],
            ['project_id' => $reciente, 'technology_id' => $tech['Laravel'], 'note' => 'reportes', 'position' => 1],
        ]);
        DB::table('project_highlights')->insert([
            ['project_id' => $reciente, 'label' => 'Reportes', 'body' => 'informes en PDF.', 'position' => 0],
            ['project_id' => $reciente, 'label' => null, 'body' => 'Sin etiqueta.', 'position' => 1],
        ]);

        $teaching = DB::table('experiences')->insertGetId([
            'kind' => 'teaching', 'company' => 'Instituto Ejemplo', 'article' => 'el', 'role' => 'Profesora', 'location' => 'Ciudad Ejemplo',
            'start_date' => '2025-01-01', 'show_since' => false, 'workshops_title' => 'Talleres que impartí', 'position' => 1,
        ]);
        DB::table('courses')->insert(['experience_id' => $teaching, 'subject' => 'Redes', 'start_date' => '2025-08-01']);
        DB::table('workshops')->insert(['experience_id' => $teaching, 'name' => 'Docker', 'period_label' => '07 – 10 nov 2025', 'sort_date' => '2025-11-07']);

        DB::table('projects')->insert([
            'kind' => 'personal', 'title' => 'Herramienta propia', 'description' => 'Propia.', 'stack' => '',
            'start_date' => '2019-01-01', 'end_date' => '2019-06-01', 'period_label' => '21 jul – 19 oct 2022',
        ]);

        DB::table('education')->insert(['title' => 'Ingeniería', 'institution' => 'Universidad Ejemplo', 'period_label' => '2014 – 2018', 'position' => 0]);

        $cg = DB::table('certification_groups')->insertGetId(['title' => 'Cursos', 'position' => 0]);
        DB::table('certification_groups')->insert(['title' => 'Grupo vacío', 'position' => 1]);
        DB::table('certifications')->insert([
            ['group_id' => $cg, 'name' => 'Curso viejo', 'issuer' => 'Plataforma', 'year' => 2020, 'note' => null, 'url' => 'https://example.com/a', 'position' => 0],
            ['group_id' => $cg, 'name' => 'Curso nuevo', 'issuer' => null, 'year' => 2024, 'note' => 'Vigente.', 'url' => null, 'position' => 1],
        ]);

        DB::table('contact_links')->insert([
            ['label' => 'GitHub', 'url' => 'https://www.github.com/ana/', 'position' => 0],
            ['label' => 'Correo', 'url' => 'mailto:ana@example.com', 'position' => 1],
        ]);
    }
}
