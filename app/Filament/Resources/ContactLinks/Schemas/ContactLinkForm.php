<?php

namespace App\Filament\Resources\ContactLinks\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ContactLinkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('label')->label('Etiqueta')->required()->maxLength(60),
                Fields::url('url', 'Enlace')->required(),
                Fields::position(),
            ]);
    }
}
