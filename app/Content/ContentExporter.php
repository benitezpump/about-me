<?php

namespace App\Content;

use App\Casts\PgTextArray;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lee todo el contenido editable en el mismo formato que acepta la importación. Las listas salen en su orden (`position`, luego
 * `id`), así que importar el archivo reproduce el sitio tal cual. La lectura es una instantánea consistente (transacción
 * `repeatable read`) para que un cambio en medio de la exportación no mezcle dos versiones.
 */
final class ContentExporter
{
    /** @return array<string, mixed> */
    public function export(): array
    {
        // Si ya hay una transacción abierta (pruebas) no se puede cambiar el aislamiento: se lee dentro de ella.
        if (DB::transactionLevel() > 0) {
            return $this->read();
        }

        return DB::transaction(function (): array {
            DB::statement('set transaction isolation level repeatable read read only');

            return $this->read();
        });
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $ordered = static fn (string $table): Collection => DB::table($table)->orderBy('position')->orderBy('id')->get();
        $byParent = static fn (Collection $rows, string $key): Collection => $rows->groupBy($key);

        $p = DB::table('profile')->where('id', 1)->first();
        if (! $p) {
            throw new NoContentException('No hay perfil.');
        }
        $profile = [
            'first_names' => $p->first_names, 'last_names' => $p->last_names, 'display_name' => $p->display_name,
            'site_title' => $p->site_title, 'headline' => $p->headline, 'location' => $p->location,
            'intro' => PgTextArray::parse((string) $p->intro), 'cta_label' => $p->cta_label, 'cta_url' => $p->cta_url,
            'contact_prompt' => $p->contact_prompt, 'meta_description' => $p->meta_description, 'og_description' => $p->og_description,
            'show_view_count' => (bool) $p->show_view_count,
        ];

        $groupTools = $byParent(
            DB::table('tool_group_items as i')->join('technologies as t', 't.id', '=', 'i.technology_id')
                ->orderBy('i.position')->orderBy('i.id')->get(['i.group_id as parent', 't.name']),
            'parent',
        );
        $techs = $byParent(
            DB::table('project_technologies as pt')->join('technologies as t', 't.id', '=', 'pt.technology_id')
                ->orderBy('pt.position')->orderBy('pt.id')->get(['pt.project_id as parent', 't.name', 'pt.note']),
            'parent',
        );
        $highlights = $byParent($ordered('project_highlights'), 'project_id');
        $projects = $ordered('projects');
        $toProject = static fn (object $r): array => [
            'title' => $r->title, 'description' => $r->description, 'stack' => $r->stack,
            'start_date' => $r->start_date, 'end_date' => $r->end_date, 'period_label' => $r->period_label, 'visible' => (bool) $r->visible,
            'technologies' => ($techs[$r->id] ?? collect())->map(fn ($t) => ['name' => $t->name, 'note' => $t->note])->values()->all(),
            'highlights' => ($highlights[$r->id] ?? collect())->map(fn ($h) => ['label' => $h->label, 'body' => $h->body])->values()->all(),
        ];

        $courses = $byParent($ordered('courses'), 'experience_id');
        $workshops = $byParent($ordered('workshops'), 'experience_id');
        $experiences = $ordered('experiences')->map(fn (object $e): array => [
            'kind' => $e->kind, 'company' => $e->company, 'article' => $e->article, 'role' => $e->role, 'location' => $e->location,
            'start_date' => $e->start_date, 'end_date' => $e->end_date, 'show_since' => (bool) $e->show_since, 'workshops_title' => $e->workshops_title,
            'projects' => $projects->where('experience_id', $e->id)->map($toProject)->values()->all(),
            'courses' => ($courses[$e->id] ?? collect())->map(fn ($c) => ['subject' => $c->subject, 'start_date' => $c->start_date, 'end_date' => $c->end_date])->values()->all(),
            'workshops' => ($workshops[$e->id] ?? collect())->map(fn ($w) => ['name' => $w->name, 'period_label' => $w->period_label, 'sort_date' => $w->sort_date])->values()->all(),
        ])->values()->all();

        $certs = $byParent($ordered('certifications'), 'group_id');

        return [
            'profile' => $profile,
            'contact_links' => $ordered('contact_links')->map(fn ($l) => ['label' => $l->label, 'url' => $l->url])->values()->all(),
            'now_items' => $ordered('now_items')->map(fn ($n) => [
                'since_label' => $n->since_label, 'title' => $n->title, 'body' => $n->body, 'visible' => (bool) $n->visible,
            ])->values()->all(),
            'tool_groups' => $ordered('tool_groups')->map(fn ($g) => [
                'label' => $g->label, 'emphasis' => (bool) $g->emphasis,
                'tools' => ($groupTools[$g->id] ?? collect())->pluck('name')->values()->all(),
            ])->values()->all(),
            'technologies' => DB::table('technologies')->orderByRaw('lower(name)')->orderBy('id')->pluck('name')->all(),
            'experiences' => $experiences,
            'personal_projects' => $projects->where('kind', 'personal')->map($toProject)->values()->all(),
            'education' => $ordered('education')->map(fn ($e) => [
                'title' => $e->title, 'institution' => $e->institution, 'period_label' => $e->period_label,
                'professional_license' => $e->professional_license,
            ])->values()->all(),
            'certification_groups' => $ordered('certification_groups')->map(fn ($g) => [
                'title' => $g->title,
                'items' => ($certs[$g->id] ?? collect())->map(fn ($c) => [
                    'name' => $c->name, 'issuer' => $c->issuer, 'year' => (int) $c->year, 'note' => $c->note, 'url' => $c->url,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
