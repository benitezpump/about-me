<?php

namespace App\Content;

/**
 * El JSON legible que se descarga: cada experiencia solo lleva las listas de su tipo, no repite lo vacío y sale con sangría de
 * dos espacios (igual que la versión anterior, así una exportación de una app se puede comparar con la de la otra).
 */
final class ContentSerializer
{
    /** @param array<string, mixed> $data */
    public static function serialize(array $data): string
    {
        $project = static fn (array $p): array => [
            'title' => $p['title'], 'description' => $p['description'],
            ...($p['stack'] !== '' ? ['stack' => $p['stack']] : []),
            'start_date' => $p['start_date'], 'end_date' => $p['end_date'], 'period_label' => $p['period_label'], 'visible' => $p['visible'],
            'technologies' => $p['technologies'], 'highlights' => $p['highlights'],
            ...(isset($p['legacy_stack']) && $p['legacy_stack'] !== '' ? ['legacy_stack' => $p['legacy_stack']] : []),
        ];

        $out = [
            'format' => ContentParser::FORMAT,
            'version' => ContentParser::VERSION,
            'profile' => $data['profile'],
            'contact_links' => $data['contact_links'],
            'now_items' => $data['now_items'],
            'tool_groups' => $data['tool_groups'],
            'technologies' => $data['technologies'],
            'experiences' => array_map(static fn (array $e): array => [
                'kind' => $e['kind'], 'company' => $e['company'], 'article' => $e['article'], 'role' => $e['role'], 'location' => $e['location'],
                'start_date' => $e['start_date'], 'end_date' => $e['end_date'], 'show_since' => $e['show_since'], 'workshops_title' => $e['workshops_title'],
                ...($e['kind'] === 'work'
                    ? ['projects' => array_map($project, $e['projects'])]
                    : ['courses' => $e['courses'], 'workshops' => $e['workshops']]),
            ], $data['experiences']),
            'personal_projects' => array_map($project, $data['personal_projects']),
            'education' => $data['education'],
            'certification_groups' => $data['certification_groups'],
        ];

        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // PHP sangra con 4 espacios; el formato usa 2. Las cadenas JSON no contienen saltos de línea reales, así que es seguro.
        $json = preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json) ?? $json;

        return $json."\n";
    }
}
