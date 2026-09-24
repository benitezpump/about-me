<?php

namespace App\Filament\Resources\Technologies\Tables;

use App\Filament\Support\SafeDeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TechnologiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tecnología')->searchable()->sortable(),
                TextColumn::make('project_links_count')->label('En proyectos')->counts('projectLinks')->sortable(),
                TextColumn::make('tool_items_count')->label('En herramientas')->counts('toolItems')->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                SafeDeleteAction::make(),
            ]);
    }
}
