<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('kind')
                    ->label('Tipo')
                    ->options(['work' => 'De trabajo (cuelga de una experiencia)', 'personal' => 'Propio (sección Proyectos propios)'])
                    ->required()->default('work')->live(),
                Fields::experience('work', required: false)
                    ->required(fn (Get $get): bool => $get('kind') === 'work')
                    ->visible(fn (Get $get): bool => $get('kind') === 'work')
                    ->helperText('Obligatoria para proyectos de trabajo.'),
                TextInput::make('title')->label('Título')->required()->columnSpanFull(),
                Textarea::make('description')->label('Descripción')->rows(4)->maxLength(1500)->columnSpanFull(),
                Textarea::make('stack')->label('Tecnologías en texto libre (respaldo)')->rows(2)->maxLength(500)->columnSpanFull()
                    ->helperText('Solo se muestra si el proyecto no tiene tecnologías del catálogo (más abajo). En cuanto elijas alguna, este texto deja de usarse.'),
                Fields::date('start_date', 'Inicio')->required(),
                Fields::date('end_date', 'Fin')->afterOrEqual('start_date')->helperText('Déjalo vacío si sigue en curso (se muestra como "actualidad").'),
                TextInput::make('period_label')->label('Periodo con texto libre')->helperText('Opcional. Sustituye al periodo calculado, por ejemplo "21 jul – 19 oct 2022".'),
                Toggle::make('visible')->label('Mostrar en el sitio')->default(true),
                Fields::position(),
                Repeater::make('technologyLinks')
                    ->label('Tecnologías del proyecto')
                    ->relationship('technologyLinks')
                    ->orderColumn('position')
                    ->schema([
                        Fields::technology(),
                        TextInput::make('note')->label('Nota (opcional, se muestra entre paréntesis)')->maxLength(80),
                    ])
                    ->columns(2)->addActionLabel('Agregar tecnología')->defaultItems(0)->columnSpanFull(),
                Repeater::make('highlights')
                    ->label('Puntos del detalle')
                    ->relationship('highlights')
                    ->orderColumn('position')
                    ->schema([
                        TextInput::make('label')->label('Etiqueta (negritas, opcional)')->maxLength(80),
                        Textarea::make('body')->label('Texto')->required()->rows(3)->maxLength(800),
                    ])
                    ->addActionLabel('Agregar punto')->defaultItems(0)->columnSpanFull(),
            ]);
    }
}
