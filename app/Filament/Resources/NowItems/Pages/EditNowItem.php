<?php

namespace App\Filament\Resources\NowItems\Pages;

use App\Filament\Resources\NowItems\NowItemResource;
use App\Filament\Support\SafeDeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNowItem extends EditRecord
{
    protected static string $resource = NowItemResource::class;

    protected function getHeaderActions(): array
    {
        return [SafeDeleteAction::make()];
    }
}
