<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Filament\Support\SafeDeleteAction;
use App\Support\Format;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Proyecto')->searchable(),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn (string $state): string => $state === 'work' ? 'De trabajo' : 'Propio'),
                TextColumn::make('experience.company')->label('Experiencia'),
                TextColumn::make('start_date')->label('Inicio')->formatStateUsing(fn ($state): string => Format::monthYear($state))->sortable(),
                IconColumn::make('visible')->label('Visible')->boolean(),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Tipo')->options(['work' => 'De trabajo', 'personal' => 'Propio']),
            ])
            ->defaultSort('start_date', 'desc')
            ->recordActions([
                EditAction::make(),
                SafeDeleteAction::make(),
            ]);
    }
}
