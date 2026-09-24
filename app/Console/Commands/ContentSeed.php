<?php

namespace App\Console\Commands;

use App\Content\SeedContent;
use Illuminate\Console\Command;

class ContentSeed extends Command
{
    protected $signature = 'content:seed
                            {--force : Reemplaza TODO el contenido editable aunque la base ya tenga contenido}
                            {--if-empty-env : Solo si SEED_ON_EMPTY es true (para el arranque del contenedor)}';

    protected $description = 'Carga el contenido inicial (database/seed/content.json, o el de ejemplo) si la base está vacía';

    public function handle(): int
    {
        if ($this->option('if-empty-env') && ! config('security.seed_on_empty')) {
            $this->line('SEED_ON_EMPTY no está activo: no se carga contenido inicial.');

            return self::SUCCESS;
        }

        $source = basename(SeedContent::file());
        $done = SeedContent::run((bool) $this->option('force'));

        $this->line($done
            ? ($this->option('force') ? "Contenido reemplazado con {$source}." : "Contenido inicial cargado desde {$source}.")
            : 'La base ya tiene contenido; no se cambió nada (usa --force para reemplazarlo).');

        return self::SUCCESS;
    }
}
