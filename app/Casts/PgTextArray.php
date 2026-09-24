<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Columna `text[]` de PostgreSQL <-> array de PHP. Laravel no interpreta los arreglos de PostgreSQL: llegan como el
 * literal `{"uno","dos"}`. Se lee byte a byte (los delimitadores son ASCII y nunca aparecen dentro de un carácter UTF-8).
 *
 * @implements CastsAttributes<list<string|null>, list<string|null>>
 */
class PgTextArray implements CastsAttributes
{
    /** @return list<string|null> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) ? $value : self::parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : self::literal((array) $value);
    }

    /** @return list<string|null> */
    public static function parse(string $literal): array
    {
        $s = trim($literal);
        if ($s === '' || $s === '{}') {
            return [];
        }
        $s = substr($s, 1, -1); // quita { }
        $n = strlen($s);
        $out = [];
        $i = 0;

        while ($i < $n) {
            if ($s[$i] === '"') {
                $i++;
                $buffer = '';
                while ($i < $n && $s[$i] !== '"') {
                    if ($s[$i] === '\\') {
                        $i++;
                    }
                    $buffer .= $s[$i++];
                }
                $i++; // comilla de cierre
                $out[] = $buffer;
            } else {
                $start = $i;
                while ($i < $n && $s[$i] !== ',') {
                    $i++;
                }
                $token = substr($s, $start, $i - $start);
                $out[] = $token === 'NULL' ? null : $token;
            }
            if ($i < $n && $s[$i] === ',') {
                $i++;
            }
        }

        return $out;
    }

    /** @param list<string|null> $items */
    public static function literal(array $items): string
    {
        $parts = array_map(
            fn ($v) => $v === null ? 'NULL' : '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"',
            $items,
        );

        return '{'.implode(',', $parts).'}';
    }
}
