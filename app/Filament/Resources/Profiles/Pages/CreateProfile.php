<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\Profiles\ProfileResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProfile extends CreateRecord
{
    protected static string $resource = ProfileResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['id'] = 1; // la tabla solo admite la fila 1

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return ProfileResource::getUrl('index');
    }
}
