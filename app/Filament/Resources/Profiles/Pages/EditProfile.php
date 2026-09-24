<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\Profiles\ProfileResource;
use App\Models\Profile;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Http\Exceptions\HttpResponseException;

class EditProfile extends EditRecord
{
    protected static string $resource = ProfileResource::class;

    public function mount(int|string|null $record = null): void
    {
        // Base vacía: no hay perfil que editar todavía; se pasa al formulario de creación. Se lanza como respuesta HTTP porque
        // `$this->redirect()` deja que Livewire siga renderizando la página sin registro.
        if (! Profile::query()->whereKey(1)->exists()) {
            throw new HttpResponseException(redirect(ProfileResource::getUrl('create')));
        }

        parent::mount(1);
    }

    protected function getRedirectUrl(): ?string
    {
        return null; // se queda en el formulario
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
