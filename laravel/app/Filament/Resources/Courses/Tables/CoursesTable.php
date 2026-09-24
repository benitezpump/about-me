<?php

namespace App\Filament\Resources\Courses\Tables;

use App\Filament\Support\SafeDeleteAction;
use App\Support\Format;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject')->label('Materia')->searchable(),
                TextColumn::make('experience.company')->label('Institución'),
                TextColumn::make('start_date')->label('Inicio')->formatStateUsing(fn ($state): string => Format::monthYear($state))->sortable(),
            ])
            ->defaultSort('start_date', 'desc')
            ->recordActions([
                EditAction::make(),
                SafeDeleteAction::make(),
            ]);
    }
}
