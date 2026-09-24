<?php

namespace App\Filament\Resources\ToolGroups\Pages;

use App\Filament\Resources\ToolGroups\ToolGroupResource;
use App\Filament\Support\SafeDeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditToolGroup extends EditRecord
{
    protected static string $resource = ToolGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [SafeDeleteAction::make()];
    }
}
