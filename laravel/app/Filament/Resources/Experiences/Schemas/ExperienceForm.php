<?php

namespace App\Filament\Resources\Experiences\Schemas;

use App\Filament\Support\Fields;
use App\Models\Experience;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ExperienceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('kind')
                    ->label('Tipo')
                    ->options(['work' => 'Trabajo (aparece en Trabajo)', 'teaching' => 'Docencia (aparece en Docencia)'])
                    ->required()->default('work')->live()
                    ->rule(fn (?Experience $record): Closure => function (string $attribute, $value, Closure $fail) use ($record): void {
                        // Los proyectos son de experiencias de trabajo; las materias y los talleres, de docencia (llave compuesta).
                        if ($record && $value !== $record->kind
                            && ($record->allProjects()->exists() || $record->courses()->exists() || $record->workshops()->exists())) {
                            $fail('Ese cambio dejaría elementos asociados sin una experiencia válida. Cambia o elimina primero sus proyectos, materias y talleres.');
                        }
                    }),
                TextInput::make('company')->label('Empresa o institución')->required(),
                TextInput::make('article')->label('Artículo antes del nombre')->maxLength(10)
                    ->helperText('Por ejemplo "el" para escribir "en el Instituto…". Déjalo vacío si no lleva.'),
                TextInput::make('role')->label('Cargo')->required(),
                TextInput::make('location')->label('Ciudad'),
                Fields::date('start_date', 'Inicio')->required(),
                Fields::date('end_date', 'Fin')->afterOrEqual('start_date')->helperText('Déjalo vacío si sigue en curso.'),
                Toggle::make('show_since')->label('Mostrar "desde <mes año>" en la introducción')->default(true),
                TextInput::make('workshops_title')->label('Título de la lista de talleres')->helperText('Solo para docencia.')
                    ->visible(fn (Get $get): bool => $get('kind') === 'teaching'),
                Fields::position(),
            ]);
    }
}
