<?php

namespace App\Console\Commands;

use App\Content\ContentImporter;
use App\Content\ContentParser;
use Illuminate\Console\Command;

class ContentImport extends Command
{
    protected $signature = 'content:import {file : Archivo JSON} {--yes : Confirma que se reemplaza TODO el contenido actual}';

    protected $description = 'Reemplaza todo el contenido por el de un archivo JSON (lo valida completo y lo aplica en una transacción)';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("No existe el archivo {$file}.");

            return self::FAILURE;
        }

        $result = ContentParser::parseText((string) file_get_contents($file));
        if (! $result->ok) {
            $this->error('No se importó nada. Corrige esto en el archivo:');
            foreach ($result->issues as $issue) {
                $this->line("  - {$issue}");
            }

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm('Esto reemplaza TODO el contenido actual del sitio. ¿Continuar?')) {
            $this->line('Cancelado: no se cambió nada.');

            return self::FAILURE;
        }

        (new ContentImporter)->replace($result->data);
        $this->info('Contenido importado.');

        return self::SUCCESS;
    }
}
