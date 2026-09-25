<?php

namespace App\Content;

/**
 * Lector con ruta: cada problema dice DÓNDE está ("experiences[0].projects[2].title"). Los problemas se acumulan en la
 * lista compartida en lugar de lanzar en el primero.
 *
 * @internal Lo usa ContentParser.
 */
final class Reader
{
    /**
     * @param  list<string>  $issues  lista compartida (por referencia)
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    public function __construct(private array &$issues, private readonly string $path, private readonly array $data, array $allowed)
    {
        foreach (array_keys($data) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $this->fail((string) $key, 'campo desconocido (¿está bien escrito? Permitidos: '.implode(', ', $allowed).')');
            }
        }
    }

    public function fail(?string $key, string $message): void
    {
        $where = $key === null ? $this->path : $this->child($key);
        $this->issues[] = "{$where}: {$message}";
    }

    private function child(string $key): string
    {
        return $this->path === '' ? $key : "{$this->path}.{$key}";
    }

    private function absent(string $key): bool
    {
        return ! array_key_exists($key, $this->data) || $this->data[$key] === null;
    }

    /** Texto recortado. `required`: no puede faltar ni estar vacío. Sin él, falta = ''. */
    public function text(string $key, bool $required = false, ?int $max = null): string
    {
        if ($this->absent($key)) {
            if ($required) {
                $this->fail($key, 'es obligatorio');
            }

            return '';
        }
        $v = $this->data[$key];
        if (! is_string($v)) {
            $this->fail($key, 'debe ser texto');

            return '';
        }
        $s = trim($v);
        if ($required && $s === '') {
            $this->fail($key, 'no puede estar vacío');
        }
        if ($max !== null && mb_strlen($s) > $max) {
            $this->fail($key, "máximo {$max} caracteres (tiene ".mb_strlen($s).')');
        }

        return $s;
    }

    /** Texto que la base guarda como NULL cuando está vacío. */
    public function nullableText(string $key, ?int $max = null): ?string
    {
        $s = $this->text($key, false, $max);

        return $s === '' ? null : $s;
    }

    public function bool(string $key, bool $fallback): bool
    {
        if ($this->absent($key)) {
            return $fallback;
        }
        if (! is_bool($this->data[$key])) {
            $this->fail($key, 'debe ser true o false');

            return $fallback;
        }

        return $this->data[$key];
    }

    public function date(string $key, bool $required): ?string
    {
        $s = $this->text($key, $required);
        if ($s === '') {
            return $required ? '1970-01-01' : null;
        }
        if (! ContentParser::isValidDate($s)) {
            $this->fail($key, "\"{$s}\" no es una fecha válida (usa AAAA-MM-DD)");

            return $required ? '1970-01-01' : null;
        }

        return $s;
    }

    public function url(string $key, bool $required): string
    {
        $s = $this->text($key, $required, 2000);
        if ($s !== '' && ! ContentParser::isSafeUrl($s)) {
            $this->fail($key, 'el enlace debe empezar con https://, http://, mailto: o tel: y no llevar espacios');
        }

        return $s;
    }

    /** Cédula profesional: opcional, solo dígitos (6 a 10). Va como texto: un número perdería ceros a la izquierda. */
    public function professionalLicense(string $key): ?string
    {
        $s = $this->nullableText($key);
        if ($s !== null && preg_match('/^[0-9]{6,10}$/', $s) !== 1) {
            $this->fail($key, 'la cédula profesional debe llevar solo números (de 6 a 10 dígitos)');
        }

        return $s;
    }

    public function year(string $key): int
    {
        $v = $this->data[$key] ?? null;
        if (! is_int($v)) {
            $this->fail($key, 'debe ser un año (número entero)');

            return 2000;
        }
        if ($v < 1990 || $v > 2100) {
            $this->fail($key, 'debe estar entre 1990 y 2100');
        }

        return $v;
    }

    /**
     * Lista de objetos; falta = []. Cada elemento se lee con `$each`, que recibe su propio Reader.
     *
     * @template T
     *
     * @param  list<string>  $allowed
     * @param  callable(Reader): T  $each
     * @return list<T>
     */
    public function list(string $key, array $allowed, callable $each, bool $required = false): array
    {
        if ($this->absent($key)) {
            if ($required) {
                $this->fail($key, 'es obligatorio');
            }

            return [];
        }
        $v = $this->data[$key];
        if (! is_array($v) || ($v !== [] && ! array_is_list($v))) {
            $this->fail($key, 'debe ser una lista');

            return [];
        }
        $out = [];
        foreach ($v as $i => $item) {
            $itemPath = $this->child($key)."[{$i}]";
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                $this->issues[] = "{$itemPath}: debe ser un objeto";

                continue;
            }
            $out[] = $each(new Reader($this->issues, $itemPath, $item, $allowed));
        }

        return $out;
    }

    /**
     * Lista de textos (p. ej. los párrafos o los nombres de tecnologías).
     *
     * @return list<string>
     */
    public function strings(string $key, ?int $max = null, bool $required = false): array
    {
        if ($this->absent($key)) {
            if ($required) {
                $this->fail($key, 'es obligatorio');
            }

            return [];
        }
        $v = $this->data[$key];
        if (! is_array($v) || ($v !== [] && ! array_is_list($v))) {
            $this->fail($key, 'debe ser una lista de textos');

            return [];
        }
        $out = [];
        foreach ($v as $i => $item) {
            if (! is_string($item) || trim($item) === '') {
                $this->fail("{$key}[{$i}]", 'debe ser un texto no vacío');

                continue;
            }
            $s = trim($item);
            if ($max !== null && mb_strlen($s) > $max) {
                $this->fail("{$key}[{$i}]", "máximo {$max} caracteres (tiene ".mb_strlen($s).')');
            }
            $out[] = $s;
        }

        return $out;
    }

    /** Avisa si `$end` es anterior a `$start` (la base también lo exige). */
    public function order(?string $start, ?string $end, string $endKey = 'end_date'): void
    {
        if ($start && $end && $end < $start) {
            $this->fail($endKey, "no puede ser anterior al inicio ({$start})");
        }
    }

    /**
     * Avisa de nombres repetidos sin distinguir mayúsculas (el catálogo no admite duplicados).
     *
     * @param  list<string>  $names
     */
    public function noRepeats(string $key, array $names): void
    {
        $seen = [];
        foreach ($names as $n) {
            $k = mb_strtolower($n);
            if (isset($seen[$k])) {
                $this->fail($key, "\"{$n}\" está repetida (sin distinguir mayúsculas)");
            }
            $seen[$k] = true;
        }
    }
}
