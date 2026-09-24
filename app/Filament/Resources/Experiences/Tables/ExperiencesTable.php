<?php

namespace App\Filament\Resources\Experiences\Tables;

use App\Filament\Support\SafeDeleteAction;
use App\Support\Format;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExperiencesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company')->label('Empresa')->searchable(),
                TextColumn::make('role')->label('Cargo'),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn (string $state): string => $state === 'work' ? 'Trabajo' : 'Docencia'),
                TextColumn::make('start_date')->label('Inicio')->formatStateUsing(fn ($state): string => Format::monthYear($state))->sortable(),
                TextColumn::make('end_date')->label('Fin')->formatStateUsing(fn ($state): string => $state ? Format::monthYear($state) : 'En curso'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Tipo')->options(['work' => 'Trabajo', 'teaching' => 'Docencia']),
            ])
            ->defaultSort('start_date', 'desc')
            ->recordActions([
                EditAction::make(),
                SafeDeleteAction::make(),
            ]);
    }
}
