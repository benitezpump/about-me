<?php

namespace App\Filament\Resources\ToolGroups\Tables;

use App\Filament\Support\SafeDeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ToolGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Grupo')->searchable(),
                TextColumn::make('items_count')->label('Herramientas')->counts('items'),
                IconColumn::make('emphasis')->label('Resaltado')->boolean(),
                TextColumn::make('position')->label('Orden')->sortable(),
            ])
            ->defaultSort('position')
            ->recordActions([
                EditAction::make(),
                SafeDeleteAction::make(),
            ]);
    }
}
