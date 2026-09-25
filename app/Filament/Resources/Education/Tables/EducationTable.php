<?php

namespace App\Filament\Resources\Education\Tables;

use App\Filament\Support\SafeDeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EducationTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Estudio')->searchable(),
                TextColumn::make('institution')->label('Institución'),
                TextColumn::make('period_label')->label('Periodo'),
                TextColumn::make('professional_license')->label('Cédula')->placeholder('—'),
            ])
            ->defaultSort('position')
            ->recordActions([
                EditAction::make(),
                SafeDeleteAction::make(),
            ]);
    }
}
