<?php

namespace App\Filament\Resources\Education\Pages;

use App\Filament\Resources\Education\EducationResource;
use App\Filament\Support\SafeDeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEducation extends EditRecord
{
    protected static string $resource = EducationResource::class;

    protected function getHeaderActions(): array
    {
        return [SafeDeleteAction::make()];
    }
}
