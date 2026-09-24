<?php

namespace App\Content;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contenido inicial. `database/seed/content.json` es el contenido REAL de quien despliega el sitio y NO se sube al repositorio
 * (está en .gitignore); `content.example.json` es ficticio y sí se versiona. Se usa el real si existe y, si no, el de ejemplo:
 * así un despliegue desde el repositorio arranca con datos de ejemplo y el contenido propio se carga después desde el panel
 * (Importar y exportar).
 */
final class SeedContent
{
    public static function realFile(): string
    {
        return database_path('seed/content.json');
    }

    public static function exampleFile(): string
    {
        return database_path('seed/content.example.json');
    }

    public static function file(): string
    {
        return is_file(self::realFile()) ? self::realFile() : self::exampleFile();
    }

    /** Lee y valida un archivo de contenido. Si está mal, el error dice dónde. @return array<string, mixed> */
    public static function load(?string $file = null): array
    {
        $file ??= self::file();
        $result = ContentParser::parseText((string) file_get_contents($file));
        if (! $result->ok) {
            throw new RuntimeException("El contenido inicial ({$file}) no es válido:\n  - ".implode("\n  - ", $result->issues));
        }

        return $result->data;
    }

    public static function isEmpty(): bool
    {
        return ! DB::table('profile')->exists();
    }

    /**
     * Carga el contenido inicial. Si la base ya tiene perfil no hace nada, salvo con `$force` (que borra TODO el contenido
     * editable y lo vuelve a cargar; no toca administradores, sesiones ni visitas). Devuelve si cargó algo.
     */
    public static function run(bool $force = false, ?string $file = null): bool
    {
        if (! $force && ! self::isEmpty()) {
            return false;
        }
        // Se lee y valida ANTES de tocar la base: un archivo roto falla con un mensaje claro y no cambia nada.
        (new ContentImporter)->replace(self::load($file));

        return true;
    }
}
