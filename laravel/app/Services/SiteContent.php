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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
    private const KEY = 'site.content';

    private const GENERATION = 'site.content.generation';

    /**
     * Contenido público con caché (por defecto 60 s). Cada edición desde el panel la invalida (`forget`). Garantías:
     *  - una sola carga a la vez: si expira y llegan muchas peticiones juntas, se carga una vez, no una por petición;
     *  - sin datos obsoletos: si una edición invalida la caché mientras hay una carga en curso, el resultado de esa carga
     *    (que pudo leer antes de la edición) no se guarda;
     *  - un error de carga no se cachea: la siguiente petición reintenta.
     * Se guarda como JSON, no como objetos PHP: `cache.serializable_classes` de Laravel prohíbe deserializar objetos.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $ttl = (int) config('security.content_cache_ttl', 60);
        if ($ttl <= 0) {
            return $this->load();
        }

        if (($hit = $this->read()) !== null) {
            return $hit;
        }

        try {
            return Cache::lock(self::KEY.'.lock', 15)->block(5, fn () => $this->read() ?? $this->loadAndStore($ttl));
        } catch (LockTimeoutException) {
            return $this->load(); // otra petición tarda demasiado: se sirve sin esperar y sin cachear
        }
    }

    /** Descarta el contenido en caché y cualquier carga que haya empezado antes de esta llamada. */
    public static function forget(): void
    {
        Cache::increment(self::GENERATION);
        Cache::forget(self::KEY);
    }

    /** @return array<string, mixed>|null */
    private function read(): ?array
    {
        $json = Cache::get(self::KEY);

        return is_string($json) ? $this->decode($json) : null;
    }

    /** @return array<string, mixed> */
    private function loadAndStore(int $ttl): array
    {
        $generation = (int) Cache::get(self::GENERATION, 0);
        $content = $this->load();
        if ($generation === (int) Cache::get(self::GENERATION, 0)) {
            Cache::put(self::KEY, json_encode($content, JSON_THROW_ON_ERROR), $ttl);
        }

        return $this->decode(json_encode($content, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        return (array) json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

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
