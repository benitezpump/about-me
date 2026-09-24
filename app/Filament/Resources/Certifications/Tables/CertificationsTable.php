<?php

namespace App\Filament\Resources\Certifications\Tables;

use App\Filament\Support\SafeDeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CertificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Certificación')->searchable(),
                TextColumn::make('year')->label('Año')->sortable(),
                TextColumn::make('group.title')->label('Grupo'),
            ])
            ->defaultSort('year', 'desc')
            ->recordActions([
                EditAction::make(),
                SafeDeleteAction::make(),
            ]);
    }
}
