<?php

namespace App\Filament\Resources\Technologies\Schemas;

use App\Rules\UniqueTechnologyName;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class TechnologyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()->maxLength(80)
                    ->rule(fn (?Model $record) => new UniqueTechnologyName($record?->getKey()))
                    ->helperText('No puede repetirse (sin distinguir mayúsculas). Renombrarla aquí la actualiza en todo el sitio.'),
            ]);
    }
}
