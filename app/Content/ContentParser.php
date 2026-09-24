<?php

namespace App\Content;

use App\Rules\SafeUrl;
use DateTimeImmutable;
use JsonException;

/**
 * Valida y normaliza el JSON del contenido del sitio (el mismo formato que usaba la app anterior; ver docs).
 *
 * Reglas de forma: los nombres de campo son los de las columnas; el orden de las listas ES el orden (`position` no se escribe);
 * las fechas son 'AAAA-MM-DD'; las tecnologías van por nombre (se buscan o crean en el catálogo sin distinguir mayúsculas). Los
 * textos se recortan. Las reglas repiten las de los formularios del panel (longitudes, enlaces seguros, fechas coherentes) y la
 * base las vuelve a exigir. Se juntan TODOS los problemas, cada uno con su ruta ("experiences[0].projects[2].title").
 *
 * @phpstan-type Data array<string, mixed>
 */
final class ContentParser
{
    public const FORMAT = 'about-me-content';

    public const VERSION = 1;

    private const MAX_ISSUES = 25;

    private const PROFILE_KEYS = [
        'first_names', 'last_names', 'display_name', 'site_title', 'headline', 'location', 'intro', 'cta_label', 'cta_url',
        'contact_prompt', 'meta_description', 'og_description', 'show_view_count',
    ];

    private const PROJECT_KEYS = [
        'title', 'description', 'stack', 'start_date', 'end_date', 'period_label', 'visible', 'technologies', 'highlights', 'legacy_stack',
    ];

    private const TOP_KEYS = [
        'format', 'version', 'profile', 'contact_links', 'now_items', 'tool_groups', 'technologies', 'experiences',
        'personal_projects', 'education', 'certification_groups',
    ];

    /** @var list<string> */
    private array $issues = [];

    /** Convierte el texto de un archivo JSON. Tolera el BOM que algunos editores de Windows añaden. */
    public static function parseText(string $text): ParseResult
    {
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        if (str_starts_with(ltrim($clean), '[')) {
            return ParseResult::failure(['El archivo debe ser un objeto JSON con el contenido del sitio.']);
        }
        try {
            $value = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ParseResult::failure(['El texto no es JSON válido: '.$e->getMessage()]);
        }

        $result = (new self)->parse($value);
        if ($result->ok || count($result->issues) <= self::MAX_ISSUES) {
            return $result;
        }

        return ParseResult::failure([
            ...array_slice($result->issues, 0, self::MAX_ISSUES),
            '…y '.(count($result->issues) - self::MAX_ISSUES).' problemas más.',
        ]);
    }

    /** Valida un valor ya decodificado desde JSON y lo normaliza. */
    public function parse(mixed $input): ParseResult
    {
        $this->issues = [];
        if (! is_array($input) || array_is_list($input) && $input !== []) {
            return ParseResult::failure(['El archivo debe ser un objeto JSON con el contenido del sitio.']);
        }

        $top = new Reader($this->issues, '', $input, self::TOP_KEYS);

        if (array_key_exists('format', $input) && $input['format'] !== self::FORMAT) {
            $top->fail('format', 'debe ser "'.self::FORMAT.'"');
        }
        if (array_key_exists('version', $input) && $input['version'] !== self::VERSION) {
            $top->fail('version', 'este sitio entiende la versión '.self::VERSION.', no '.json_encode($input['version']));
        }

        $profile = null;
        $raw = $input['profile'] ?? null;
        if (! is_array($raw) || array_is_list($raw) && $raw !== []) {
            $top->fail('profile', 'es obligatorio y debe ser un objeto');
        } else {
            $profile = $this->profile(new Reader($this->issues, 'profile', $raw, self::PROFILE_KEYS));
        }

        $contactLinks = $top->list('contact_links', ['label', 'url'], fn (Reader $r) => [
            'label' => $r->text('label', true, 60), 'url' => $r->url('url', true),
        ]);
        $nowItems = $top->list('now_items', ['since_label', 'title', 'body', 'visible'], fn (Reader $r) => [
            'since_label' => $r->text('since_label', true), 'title' => $r->text('title', true),
            'body' => $r->text('body'), 'visible' => $r->bool('visible', true),
        ]);
        $toolGroups = $top->list('tool_groups', ['label', 'emphasis', 'tools'], function (Reader $r) {
            $tools = $r->strings('tools', 80);
            $r->noRepeats('tools', $tools);

            return ['label' => $r->text('label', true), 'emphasis' => $r->bool('emphasis', false), 'tools' => $tools];
        });
        $technologies = $top->strings('technologies', 80);

        $experiences = $top->list(
            'experiences',
            ['kind', 'company', 'article', 'role', 'location', 'start_date', 'end_date', 'show_since', 'workshops_title', 'projects', 'courses', 'workshops'],
            fn (Reader $r) => $this->experience($r),
        );
        $personal = $top->list('personal_projects', self::PROJECT_KEYS, fn (Reader $r) => $this->project($r));
        $education = $top->list('education', ['title', 'institution', 'period_label'], fn (Reader $r) => [
            'title' => $r->text('title', true), 'institution' => $r->text('institution'), 'period_label' => $r->text('period_label', true),
        ]);
        $certificationGroups = $top->list('certification_groups', ['title', 'items'], fn (Reader $r) => [
            'title' => $r->text('title', true),
            'items' => $r->list('items', ['name', 'issuer', 'year', 'note', 'url'], fn (Reader $i) => [
                'name' => $i->text('name', true), 'issuer' => $i->nullableText('issuer'), 'year' => $i->year('year'),
                'note' => $i->nullableText('note'), 'url' => $i->url('url', false) ?: null,
            ]),
        ]);

        if ($this->issues !== [] || $profile === null) {
            return ParseResult::failure($this->issues);
        }

        return ParseResult::success([
            'profile' => $profile, 'contact_links' => $contactLinks, 'now_items' => $nowItems, 'tool_groups' => $toolGroups,
            'technologies' => $technologies, 'experiences' => $experiences, 'personal_projects' => $personal,
            'education' => $education, 'certification_groups' => $certificationGroups,
        ]);
    }

    /** @return array<string, mixed> */
    private function profile(Reader $r): array
    {
        $intro = $r->strings('intro', null, true);
        if (mb_strlen(implode("\n\n", $intro)) > 3000) {
            $r->fail('intro', 'el texto completo pasa de 3000 caracteres');
        }
        if ($intro === []) {
            $r->fail('intro', 'necesita al menos un párrafo');
        }

        return [
            'first_names' => $r->text('first_names', true), 'last_names' => $r->text('last_names', true),
            'display_name' => $r->text('display_name', true), 'site_title' => $r->text('site_title', true),
            'headline' => $r->text('headline', true), 'location' => $r->text('location'), 'intro' => $intro,
            'cta_label' => $r->text('cta_label'), 'cta_url' => $r->url('cta_url', false), 'contact_prompt' => $r->text('contact_prompt'),
            'meta_description' => $r->text('meta_description', false, 300), 'og_description' => $r->text('og_description', false, 300),
            'show_view_count' => $r->bool('show_view_count', true),
        ];
    }

    /** @return array<string, mixed> */
    private function project(Reader $r): array
    {
        $start = $r->date('start_date', true);
        $end = $r->date('end_date', false);
        $r->order($start, $end);
        $technologies = $r->list('technologies', ['name', 'note'], fn (Reader $t) => [
            'name' => $t->text('name', true, 80), 'note' => $t->nullableText('note', 80),
        ]);
        $r->noRepeats('technologies', array_column($technologies, 'name'));

        $project = [
            'title' => $r->text('title', true), 'description' => $r->text('description', false, 1500), 'stack' => $r->text('stack', false, 500),
            'start_date' => $start, 'end_date' => $end, 'period_label' => $r->nullableText('period_label'), 'visible' => $r->bool('visible', true),
            'technologies' => $technologies,
            'highlights' => $r->list('highlights', ['label', 'body'], fn (Reader $h) => [
                'label' => $h->nullableText('label', 80), 'body' => $h->text('body', true, 800),
            ]),
        ];
        $legacy = $r->text('legacy_stack');
        if ($legacy !== '') {
            $project['legacy_stack'] = $legacy;
        }

        return $project;
    }

    /** @return array<string, mixed> */
    private function experience(Reader $r): array
    {
        $kindText = $r->text('kind', true);
        $kind = $kindText === 'teaching' ? 'teaching' : 'work';
        if ($kindText !== '' && $kindText !== 'work' && $kindText !== 'teaching') {
            $r->fail('kind', 'debe ser "work" o "teaching"');
        }
        $start = $r->date('start_date', true);
        $end = $r->date('end_date', false);
        $r->order($start, $end);

        $projects = $r->list('projects', self::PROJECT_KEYS, fn (Reader $p) => $this->project($p));
        $courses = $r->list('courses', ['subject', 'start_date', 'end_date'], function (Reader $c) {
            $s = $c->date('start_date', true);
            $e = $c->date('end_date', false);
            $c->order($s, $e);

            return ['subject' => $c->text('subject', true), 'start_date' => $s, 'end_date' => $e];
        });
        $workshops = $r->list('workshops', ['name', 'period_label', 'sort_date'], fn (Reader $w) => [
            'name' => $w->text('name', true), 'period_label' => $w->text('period_label', true), 'sort_date' => $w->date('sort_date', true),
        ]);
        if ($kind === 'work' && ($courses !== [] || $workshops !== [])) {
            $r->fail(null, 'una experiencia de trabajo no lleva "courses" ni "workshops" (son de docencia)');
        }
        if ($kind === 'teaching' && $projects !== []) {
            $r->fail(null, 'una experiencia de docencia no lleva "projects" (son de trabajo)');
        }

        return [
            'kind' => $kind, 'company' => $r->text('company', true), 'article' => $r->nullableText('article', 10), 'role' => $r->text('role', true),
            'location' => $r->text('location'), 'start_date' => $start, 'end_date' => $end, 'show_since' => $r->bool('show_since', true),
            'workshops_title' => $r->nullableText('workshops_title'), 'projects' => $projects, 'courses' => $courses, 'workshops' => $workshops,
        ];
    }

    public static function isValidDate(string $s): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) !== 1) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);

        return $d !== false && $d->format('Y-m-d') === $s;
    }

    public static function isSafeUrl(string $url): bool
    {
        return SafeUrl::isSafe($url);
    }
}
