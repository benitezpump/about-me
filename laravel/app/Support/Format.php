<?php

namespace App\Support;

/**
 * Formato de fechas, periodos y enlaces para la portada. Son funciones puras (sin base de datos), y por eso se prueban solas.
 * Las fechas llegan como 'AAAA-MM-DD' (texto): no se convierten a objetos de fecha para evitar corrimientos de zona horaria.
 */
final class Format
{
    private const MONTHS = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    /** '2023-02-01' → '02/2023' */
    public static function monthYear(string $date): string
    {
        [$y, $m] = explode('-', $date);

        return "{$m}/{$y}";
    }

    /** '2015-03-01' → 'marzo de 2015' */
    public static function longMonthYear(string $date): string
    {
        [$y, $m] = explode('-', $date);

        return self::MONTHS[(int) $m - 1]." de {$y}";
    }

    public static function period(string $start, ?string $end, ?string $label = null): Period
    {
        $custom = $label !== null && trim($label) !== '';

        return new Period(
            label: $custom ? trim($label) : null,
            from: self::monthYear($start),
            to: $end !== null ? self::monthYear($end) : null,
            current: $end === null && ! $custom,
        );
    }

    /** 'Ana Prueba' → 'AP' (para el favicon). */
    public static function initials(string $name): string
    {
        $words = array_slice(preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2);

        return implode('', array_map(fn (string $w) => mb_strtoupper(mb_substr($w, 0, 1)), $words));
    }

    /**
     * Divide un texto por líneas en blanco en párrafos; recorta y descarta los vacíos.
     *
     * @return list<string>
     */
    public static function paragraphs(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R\s*\R/u', $text) ?: []),
            fn (string $p) => $p !== '',
        ));
    }

    /** Solo los enlaces http(s) se abren en pestaña nueva; mailto: y tel: no. */
    public static function isHttp(?string $url): bool
    {
        return $url !== null && preg_match('#^https?://#i', $url) === 1;
    }

    /** 'https://www.ejemplo.com/' → 'ejemplo.com' (para mostrar como texto del enlace). */
    public static function displayUrl(?string $url): string
    {
        $text = preg_replace('#^(https?://(www\.)?|mailto:|tel:)#i', '', (string) $url);

        return (string) preg_replace('#/$#', '', (string) $text);
    }

    /**
     * Línea de tecnologías de un proyecto, desde el catálogo: "PHP, Laravel, Browsershot (generación de PDF).".
     * Solo si el proyecto aún no tiene tecnologías se muestra su texto libre heredado.
     *
     * @param  list<array{name: string, note: ?string}>  $techs
     */
    public static function stackLine(array $techs, string $legacyText): string
    {
        if ($techs === []) {
            return $legacyText;
        }

        return implode(', ', array_map(
            fn (array $t) => ($t['note'] ?? '') !== '' ? "{$t['name']} ({$t['note']})" : $t['name'],
            $techs,
        )).'.';
    }
}
