<?php

namespace App\Filament\Resources\CertificationGroups\Tables;

use App\Filament\Support\SafeDeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CertificationGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Grupo')->searchable(),
                TextColumn::make('certifications_count')->label('Certificaciones')->counts('certifications'),
                TextColumn::make('position')->label('Orden')->sortable(),
            ])
            ->defaultSort('position')
            ->recordActions([
                EditAction::make(),
                SafeDeleteAction::make(),
            ]);
    }
}
