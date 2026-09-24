<?php

namespace App\Content;

/** Resultado de validar un archivo de contenido: los datos normalizados, o TODOS los problemas encontrados (con su ruta). */
final readonly class ParseResult
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  list<string>  $issues
     */
    private function __construct(public bool $ok, public ?array $data, public array $issues) {}

    /** @param array<string, mixed> $data */
    public static function success(array $data): self
    {
        return new self(true, $data, []);
    }

    /** @param list<string> $issues */
    public static function failure(array $issues): self
    {
        return new self(false, null, $issues);
    }
}
