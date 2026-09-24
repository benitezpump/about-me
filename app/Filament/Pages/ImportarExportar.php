<?php

namespace App\Filament\Pages;

use App\Content\ContentExporter;
use App\Content\ContentImporter;
use App\Content\ContentParser;
use App\Content\ContentSerializer;
use App\Content\NoContentException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Exportar todo el contenido a un JSON e importarlo (reemplaza todo). El archivo lo lee el NAVEGADOR y se envía como texto de
 * un formulario: no hay subida ni almacenamiento de archivos (alcance del panel: docs/decisions/0001).
 */
class ImportarExportar extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    protected static ?int $navigationSort = 98;

    protected static ?string $navigationLabel = 'Importar y exportar';

    protected static ?string $title = 'Importar y exportar';

    protected static ?string $slug = 'importar-exportar';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var list<string> */
    public array $issues = [];

    public function mount(): void
    {
        $this->form->fill(['text' => '', 'confirm' => false]);
    }

    public function getSubheading(): ?string
    {
        return 'Todo el contenido del sitio en un solo archivo JSON: sirve de respaldo y para llevarlo a otra base.';
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.components.json-file-input'),
            Textarea::make('text')
                ->label('Contenido')
                ->rows(14)
                ->required()
                ->extraInputAttributes(['spellcheck' => 'false', 'style' => 'font-family: ui-monospace, monospace; font-size: .8125rem; white-space: pre; overflow: auto;'])
                ->helperText('El archivo se lee en tu navegador y su texto aparece aquí, donde puedes revisarlo antes de importar.'),
            Checkbox::make('confirm')
                ->label('Entiendo que esto reemplaza todo el contenido actual')
                ->accepted()
                ->validationMessages(['accepted' => 'Marca la casilla para confirmar que quieres reemplazar el contenido actual.']),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Exportar')
                ->description('Descarga el contenido actual: perfil, herramientas, empleos, proyectos, docencia, estudios y certificaciones. No incluye usuarios, contraseñas ni estadísticas de visitas.')
                ->footer([Actions::make([$this->exportAction()])]),
            Section::make('Importar')
                ->description('Reemplaza TODO el contenido actual por el del archivo. Se revisa completo antes de guardar y se aplica de una sola vez: si algo no es válido, no cambia nada. Descarga una exportación antes por si quieres volver atrás.')
                ->schema([
                    View::make('filament.components.import-issues')->visible(fn (): bool => $this->issues !== []),
                    Form::make([EmbeddedSchema::make('form')])
                        ->id('form')
                        ->livewireSubmitHandler('import')
                        ->footer([Actions::make([
                            // La confirmación es la casilla obligatoria de arriba (un botón `submit` no abre modales).
                            Action::make('import')->label('Importar y reemplazar')->submit('import'),
                        ])]),
                ]),
        ]);
    }

    public function exportAction(): Action
    {
        return Action::make('export')
            ->label('Descargar contenido (JSON)')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function (): ?StreamedResponse {
                try {
                    $json = ContentSerializer::serialize((new ContentExporter)->export());
                } catch (NoContentException) {
                    Notification::make()->title('Todavía no hay perfil, así que no hay contenido que exportar.')
                        ->body('Llena el Perfil o importa un archivo.')->warning()->send();

                    return null;
                }

                return response()->streamDownload(
                    static function () use ($json): void {
                        echo $json;
                    },
                    'about-me-'.now()->format('Y-m-d').'.json',
                    ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
                );
            });
    }

    public function import(): void
    {
        $this->issues = [];
        $state = $this->form->getState(); // valida: texto obligatorio y casilla marcada

        $result = ContentParser::parseText((string) $state['text']);
        if (! $result->ok) {
            $this->issues = $result->issues;

            return;
        }

        try {
            (new ContentImporter)->replace($result->data);
        } catch (Throwable $e) {
            report($e);
            $rule = method_exists($e, 'getPrevious') && $e->getPrevious() ? $e->getPrevious() : $e;
            preg_match('/constraint "([^"]+)"/', $rule->getMessage(), $m);
            $this->issues = ['La base de datos rechazó el contenido'.(isset($m[1]) ? " (regla {$m[1]})" : '').'. No se cambió nada.'];

            return;
        }

        $this->form->fill(['text' => '', 'confirm' => false]);
        Notification::make()->title('Se importó el contenido. El sitio ya lo muestra.')->success()->send();
    }
}
