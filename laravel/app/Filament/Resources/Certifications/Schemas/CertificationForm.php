<?php

namespace App\Filament\Resources\Certifications\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CertificationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('group_id')->label('Grupo')->relationship('group', 'title')->required()->preload()->columnSpanFull(),
                TextInput::make('name')->label('Nombre')->required(),
                TextInput::make('issuer')->label('Emisor')->helperText('Se muestra después del nombre. Déjalo vacío si el grupo ya lo indica.'),
                TextInput::make('year')->label('Año')->required()->numeric()->integer()->minValue(1990)->maxValue(2100),
                TextInput::make('note')->label('Nota')->helperText('Por ejemplo "Vigente hasta 2029."'),
                Fields::url('url', 'Enlace al certificado'),
                Fields::position(),
            ]);
    }
}
