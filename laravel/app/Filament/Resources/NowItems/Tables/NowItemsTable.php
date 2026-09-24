<?php

namespace App\Filament\Resources\NowItems\Tables;

use App\Filament\Support\SafeDeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NowItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Título')->searchable(),
                TextColumn::make('since_label')->label('Desde'),
                IconColumn::make('visible')->label('Visible')->boolean(),
                TextColumn::make('position')->label('Orden')->sortable(),
            ])
            ->defaultSort('position')
            ->recordActions([
                EditAction::make(),
                SafeDeleteAction::make(),
            ]);
    }
}
