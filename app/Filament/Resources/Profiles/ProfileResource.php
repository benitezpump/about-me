<?php

namespace App\Filament\Resources\Profiles;

use App\Filament\Resources\Profiles\Pages\CreateProfile;
use App\Filament\Resources\Profiles\Pages\EditProfile;
use App\Filament\Resources\Profiles\Schemas\ProfileForm;
use App\Models\Profile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * El perfil es UNA sola fila (id = 1): no hay lista ni borrado. La ruta principal abre el formulario; si aún no existe
 * (base vacía), abre el de creación.
 */
class ProfileResource extends Resource
{
    protected static ?string $model = Profile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUser;

    protected static ?int $navigationSort = 0;

    protected static ?string $modelLabel = 'perfil';

    protected static ?string $pluralModelLabel = 'perfil';

    protected static ?string $navigationLabel = 'Perfil';

    protected static ?string $slug = 'perfil';

    public static function form(Schema $schema): Schema
    {
        return ProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([]);
    }

    public static function canCreate(): bool
    {
        return ! Profile::query()->exists();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => EditProfile::route('/'),
            'create' => CreateProfile::route('/create'),
        ];
    }
}
