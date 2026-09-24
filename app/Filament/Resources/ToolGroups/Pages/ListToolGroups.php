<?php

namespace App\Filament\Resources\ToolGroups\Pages;

use App\Filament\Resources\ToolGroups\ToolGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListToolGroups extends ListRecords
{
    protected static string $resource = ToolGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Agregar grupo de herramientas')];
    }
}
