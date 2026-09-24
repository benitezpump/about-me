<?php

namespace App\Filament\Resources\ToolGroups\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ToolGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('label')->label('Nombre del grupo')->required(),
                Toggle::make('emphasis')->label('Resaltar este grupo')->helperText('Muestra sus herramientas con más contraste.'),
                Fields::position(),
                Repeater::make('items')
                    ->label('Herramientas del grupo')
                    ->relationship('items')
                    ->orderColumn('position')
                    ->schema([Fields::technology()])
                    ->addActionLabel('Agregar herramienta')
                    ->defaultItems(0)
                    ->columnSpanFull(),
            ]);
    }
}
