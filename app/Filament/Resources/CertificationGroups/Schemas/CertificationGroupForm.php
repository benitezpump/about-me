<?php

namespace App\Filament\Resources\CertificationGroups\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CertificationGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('title')->label('Título del grupo')->required(),
                Fields::position(),
            ]);
    }
}
