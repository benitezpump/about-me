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
                TextInput::make('professional_license')
                    ->label('No. de cédula profesional')
                    ->maxLength(10)
                    ->regex('/^[0-9]{6,10}$/')
                    ->validationMessages(['regex' => 'Escribe solo números (de 6 a 10 dígitos).'])
                    ->helperText('Opcional: solo si este estudio tiene cédula. Se muestra en el sitio.'),
                Fields::position(),
            ]);
    }
}
