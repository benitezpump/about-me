<?php

namespace App\Filament\Resources\Workshops\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class WorkshopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Fields::experience('teaching')->columnSpanFull(),
                TextInput::make('name')->label('Nombre')->required(),
                TextInput::make('period_label')->label('Fechas (texto)')->required()->helperText('Se muestra tal cual, por ejemplo "07 – 10 nov 2024".'),
                Fields::date('sort_date', 'Fecha para ordenar')->required()->helperText('Los más recientes salen primero.'),
                Fields::position(),
            ]);
    }
}
