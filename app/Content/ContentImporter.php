<?php

namespace App\Content;

use App\Casts\PgTextArray;
use App\Services\SiteContent;
use Illuminate\Support\Facades\DB;

/**
 * Reemplaza TODO el contenido editable por el de un archivo ya validado (`ContentParser`), en una sola transacción: si algo
 * falla, la base queda como estaba. No toca administradores, sesiones ni visitas. Invalida la caché del sitio.
 */
final class ContentImporter
{
    /** Tablas de contenido, hijas primero, para vaciarlas sin violar llaves foráneas. */
    public const TABLES = [
        'project_highlights', 'project_technologies', 'projects', 'courses', 'workshops', 'experiences',
        'tool_group_items', 'tool_groups', 'technologies',
        'now_items', 'education', 'certifications', 'certification_groups', 'contact_links', 'profile',
    ];

    /** @var array<string, int> */
    private array $catalog = [];

    /** @param array<string, mixed> $data */
    public function replace(array $data): void
    {
        DB::transaction(function () use ($data): void {
            DB::statement('truncate '.implode(', ', self::TABLES).' restart identity cascade');
            $this->catalog = [];
            $this->insert($data);
        });

        // El truncado y las inserciones se saltan los modelos: se invalida a mano.
        SiteContent::forget();
    }

    /** @param array<string, mixed> $d */
    private function insert(array $d): void
    {
        foreach ($d['technologies'] as $name) {
            $this->technology($name);
        }

        $p = $d['profile'];
        DB::table('profile')->insert([...$p, 'intro' => PgTextArray::literal($p['intro'])]);

        foreach ($d['contact_links'] as $i => $l) {
            DB::table('contact_links')->insert([...$l, 'position' => $i]);
        }
        foreach ($d['now_items'] as $i => $n) {
            DB::table('now_items')->insert([...$n, 'position' => $i]);
        }

        foreach ($d['tool_groups'] as $gi => $g) {
            $id = DB::table('tool_groups')->insertGetId(['label' => $g['label'], 'emphasis' => $g['emphasis'], 'position' => $gi]);
            foreach ($g['tools'] as $ti => $name) {
                DB::table('tool_group_items')->insert(['group_id' => $id, 'technology_id' => $this->technology($name), 'position' => $ti]);
            }
        }

        foreach ($d['experiences'] as $i => $e) {
            $this->experience($e, $i);
        }
        foreach ($d['personal_projects'] as $i => $proj) {
            $this->project($proj, 'personal', null, $i);
        }

        foreach ($d['education'] as $i => $e) {
            DB::table('education')->insert([...$e, 'position' => $i]);
        }

        foreach ($d['certification_groups'] as $gi => $g) {
            $id = DB::table('certification_groups')->insertGetId(['title' => $g['title'], 'position' => $gi]);
            foreach ($g['items'] as $i => $item) {
                DB::table('certifications')->insert([...$item, 'group_id' => $id, 'position' => $i]);
            }
        }
    }

    /** @param array<string, mixed> $e */
    private function experience(array $e, int $position): void
    {
        $id = DB::table('experiences')->insertGetId([
            'kind' => $e['kind'], 'company' => $e['company'], 'article' => $e['article'], 'role' => $e['role'], 'location' => $e['location'],
            'start_date' => $e['start_date'], 'end_date' => $e['end_date'], 'show_since' => $e['show_since'],
            'workshops_title' => $e['workshops_title'], 'position' => $position,
        ]);

        foreach ($e['projects'] as $i => $proj) {
            $this->project($proj, 'work', $id, $i);
        }
        foreach ($e['courses'] as $i => $c) {
            DB::table('courses')->insert([...$c, 'experience_id' => $id, 'position' => $i]);
        }
        foreach ($e['workshops'] as $i => $w) {
            DB::table('workshops')->insert([...$w, 'experience_id' => $id, 'position' => $i]);
        }
    }

    /** @param array<string, mixed> $proj */
    private function project(array $proj, string $kind, ?int $experienceId, int $position): void
    {
        $id = DB::table('projects')->insertGetId([
            'experience_id' => $experienceId, 'kind' => $kind, 'title' => $proj['title'], 'description' => $proj['description'],
            'stack' => $proj['stack'], 'start_date' => $proj['start_date'], 'end_date' => $proj['end_date'],
            'period_label' => $proj['period_label'], 'visible' => $proj['visible'], 'position' => $position,
        ]);

        foreach ($proj['technologies'] as $i => $t) {
            DB::table('project_technologies')->insert([
                'project_id' => $id, 'technology_id' => $this->technology($t['name']), 'note' => $t['note'], 'position' => $i,
            ]);
        }
        foreach ($proj['highlights'] as $i => $h) {
            DB::table('project_highlights')->insert(['project_id' => $id, 'label' => $h['label'], 'body' => $h['body'], 'position' => $i]);
        }
    }

    /** Busca una tecnología del catálogo (sin distinguir mayúsculas) y la crea si no existe. */
    private function technology(string $name): int
    {
        $clean = trim($name);
        $key = mb_strtolower($clean);
        if (isset($this->catalog[$key])) {
            return $this->catalog[$key];
        }

        $id = DB::table('technologies')->whereRaw('lower(btrim(name)) = ?', [$key])->value('id')
            ?? DB::table('technologies')->insertGetId(['name' => $clean]);

        return $this->catalog[$key] = (int) $id;
    }
}
