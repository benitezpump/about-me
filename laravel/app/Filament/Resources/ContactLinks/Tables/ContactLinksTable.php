<?php

namespace App\Filament\Resources\ContactLinks\Tables;

use App\Filament\Support\SafeDeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContactLinksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Etiqueta')->searchable(),
                TextColumn::make('url')->label('Enlace')->limit(50),
            ])
            ->defaultSort('position')
            ->recordActions([
                EditAction::make(),
                SafeDeleteAction::make(),
            ]);
    }
}
