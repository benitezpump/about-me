<?php

namespace App\Filament\Resources\Certifications\Pages;

use App\Filament\Resources\Certifications\CertificationResource;
use App\Filament\Support\SafeDeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCertification extends EditRecord
{
    protected static string $resource = CertificationResource::class;

    protected function getHeaderActions(): array
    {
        return [SafeDeleteAction::make()];
    }
}
