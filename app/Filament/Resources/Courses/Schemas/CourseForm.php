<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Fields::experience('teaching')->columnSpanFull(),
                TextInput::make('subject')->label('Materia')->required(),
                Fields::position(),
                Fields::date('start_date', 'Inicio')->required(),
                Fields::date('end_date', 'Fin')->afterOrEqual('start_date')->helperText('Déjalo vacío si sigue en curso.'),
            ]);
    }
}
