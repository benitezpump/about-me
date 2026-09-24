<?php

namespace App\Filament\Resources\CertificationGroups\Pages;

use App\Filament\Resources\CertificationGroups\CertificationGroupResource;
use App\Filament\Support\SafeDeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCertificationGroup extends EditRecord
{
    protected static string $resource = CertificationGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [SafeDeleteAction::make()];
    }
}
