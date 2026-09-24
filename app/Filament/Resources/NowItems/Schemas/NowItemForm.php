<?php

namespace App\Filament\Resources\NowItems\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class NowItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('since_label')->label('Desde')->required()->helperText('Texto libre, por ejemplo "Desde 03/2015".'),
                TextInput::make('title')->label('Título (en negritas)')->required(),
                Textarea::make('body')->label('Texto que sigue al título')->rows(3)->columnSpanFull(),
                Fields::position(),
                Toggle::make('visible')->label('Mostrar en el sitio')->default(true),
            ]);
    }
}
