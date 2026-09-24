<?php

namespace App\Filament\Resources\Education\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EducationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')->label('Título o carrera')->required(),
                TextInput::make('institution')->label('Institución'),
                TextInput::make('period_label')->label('Periodo (texto)')->required()->helperText('Por ejemplo "2010 – 2014".'),
                Fields::position(),
            ]);
    }
}
