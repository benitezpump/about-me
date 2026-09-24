<?php

namespace App\Filament\Resources\CertificationGroups\Pages;

use App\Filament\Resources\CertificationGroups\CertificationGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCertificationGroups extends ListRecords
{
    protected static string $resource = CertificationGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Agregar grupo de certificaciones')];
    }
}
