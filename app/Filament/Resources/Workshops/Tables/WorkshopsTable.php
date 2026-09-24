<?php

namespace App\Filament\Resources\Workshops\Tables;

use App\Filament\Support\SafeDeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkshopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Taller')->searchable(),
                TextColumn::make('period_label')->label('Fechas'),
                TextColumn::make('sort_date')->label('Fecha para ordenar')->date('d/m/Y')->sortable(),
            ])
            ->defaultSort('sort_date', 'desc')
            ->recordActions([
                EditAction::make(),
                SafeDeleteAction::make(),
            ]);
    }
}
