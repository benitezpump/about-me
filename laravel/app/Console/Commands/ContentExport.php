<?php

namespace App\Console\Commands;

use App\Content\ContentExporter;
use App\Content\ContentSerializer;
use App\Content\NoContentException;
use Illuminate\Console\Command;

class ContentExport extends Command
{
    protected $signature = 'content:export {file? : Archivo de salida (sin él, se imprime en pantalla)}';

    protected $description = 'Exporta todo el contenido del sitio a JSON (respaldo). No incluye administradores ni visitas';

    public function handle(): int
    {
        try {
            $json = ContentSerializer::serialize((new ContentExporter)->export());
        } catch (NoContentException) {
            $this->error('Todavía no hay perfil, así que no hay contenido que exportar.');

            return self::FAILURE;
        }

        if ($file = $this->argument('file')) {
            file_put_contents($file, $json);
            $this->info("Contenido exportado a {$file}.");
        } else {
            $this->getOutput()->write($json);
        }

        return self::SUCCESS;
    }
}
