<?php

namespace App\Services;

use App\Models\CertificationGroup;
use App\Models\ContactLink;
use App\Models\Education;
use App\Models\Experience;
use App\Models\NowItem;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ToolGroup;
use App\Support\Format;
use RuntimeException;

/**
 * Lee el contenido público y lo deja listo para la plantilla (orden y formato incluidos). Es el "modelo de vista" de la
 * portada: la plantilla no consulta ni formatea nada por su cuenta.
 *
 * Orden (igual que la versión anterior): proyectos, materias y experiencias por fecha de inicio descendente
 * (`position` solo desempata); talleres por `sort_date`; certificaciones por año descendente. Una sección vacía no
 * aparece en el sitio.
 */
class SiteContent
{
    /** @return array<string, mixed> */
    public function load(): array
    {
        $profile = Profile::find(1);
        if (! $profile) {
            throw new RuntimeException('No hay perfil en la base de datos. Carga el contenido inicial o llénalo desde /admin.');
        }

        $experiences = Experience::query()
            ->orderByDesc('start_date')->orderBy('position')->orderBy('id')
            ->with(['projects.technologies', 'projects.highlights', 'courses', 'workshops'])
            ->get();

        $personal = Project::query()
            ->where('kind', 'personal')->where('visible', true)
            ->orderByDesc('start_date')->orderBy('position')->orderBy('id')
            ->with(['technologies', 'highlights'])
            ->get();

        return [
            'profile' => (object) [
                'firstNames' => $profile->first_names,
                'lastNames' => $profile->last_names,
                'displayName' => $profile->display_name,
                'siteTitle' => $profile->site_title,
                'headline' => $profile->headline,
                'location' => $profile->location,
                'intro' => $profile->intro,
                'ctaLabel' => $profile->cta_label,
                'ctaUrl' => $profile->cta_url,
                'contactPrompt' => $profile->contact_prompt,
                'metaDescription' => $profile->meta_description,
                'ogDescription' => $profile->og_description,
                'showViewCount' => (bool) $profile->show_view_count,
            ],
            'now' => NowItem::where('visible', true)->orderBy('position')->orderBy('id')->get()
                ->map(fn ($n) => (object) ['sinceLabel' => $n->since_label, 'title' => $n->title, 'body' => $n->body])->all(),
            'toolGroups' => ToolGroup::with('technologies')->orderBy('position')->orderBy('id')->get()
                ->map(fn ($g) => (object) [
                    'label' => $g->label,
                    'emphasis' => (bool) $g->emphasis,
                    'tools' => $g->technologies->pluck('name')->all(),
                ])
                ->filter(fn ($g) => $g->tools !== [])->values()->all(),
            'work' => $experiences->where('kind', 'work')->map(fn ($e) => $this->experience($e))->values()->all(),
            'teaching' => $experiences->where('kind', 'teaching')->map(fn ($e) => $this->experience($e))->values()->all(),
            'personalProjects' => $personal->map(fn ($p) => $this->project($p))->all(),
            'education' => Education::orderBy('position')->orderBy('id')->get()
                ->map(fn ($e) => (object) ['title' => $e->title, 'institution' => $e->institution, 'periodLabel' => $e->period_label])->all(),
            'certificationGroups' => CertificationGroup::with('certifications')->orderBy('position')->orderBy('id')->get()
                ->map(fn ($g) => (object) [
                    'title' => $g->title,
                    'items' => $g->certifications->map(fn ($c) => (object) [
                        'name' => $c->name, 'issuer' => $c->issuer, 'year' => $c->year, 'note' => $c->note, 'url' => $c->url,
                    ])->all(),
                ])
                ->filter(fn ($g) => $g->items !== [])->values()->all(),
            'contactLinks' => ContactLink::orderBy('position')->orderBy('id')->get()
                ->map(fn ($l) => (object) ['label' => $l->label, 'url' => $l->url])->all(),
        ];
    }

    private function experience(Experience $e): object
    {
        return (object) [
            'id' => $e->id,
            'company' => $e->company,
            'article' => $e->article,
            'role' => $e->role,
            'location' => $e->location,
            /** 'marzo de 2015' cuando la experiencia muestra "desde …". */
            'since' => $e->show_since ? Format::longMonthYear($e->start_date) : null,
            'projects' => $e->projects->map(fn ($p) => $this->project($p))->all(),
            'courses' => $e->courses->map(fn ($c) => (object) [
                'subject' => $c->subject,
                'period' => Format::period($c->start_date, $c->end_date),
            ])->all(),
            'workshopsTitle' => $e->workshops_title,
            'workshops' => $e->workshops->map(fn ($w) => (object) ['name' => $w->name, 'label' => $w->period_label])->all(),
        ];
    }

    private function project(Project $p): object
    {
        $techs = $p->technologies->map(fn ($t) => ['name' => $t->name, 'note' => $t->pivot->note])->all();

        return (object) [
            'id' => $p->id,
            'title' => $p->title,
            'description' => $p->description,
            'stack' => Format::stackLine($techs, $p->stack),
            'period' => Format::period($p->start_date, $p->end_date, $p->period_label),
            'highlights' => $p->highlights->map(fn ($h) => (object) ['label' => $h->label, 'body' => $h->body])->all(),
        ];
    }
}
