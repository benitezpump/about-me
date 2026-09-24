<?php

namespace App\Filament\Resources\ContactLinks;

use App\Filament\Resources\ContactLinks\Pages\CreateContactLink;
use App\Filament\Resources\ContactLinks\Pages\EditContactLink;
use App\Filament\Resources\ContactLinks\Pages\ListContactLinks;
use App\Filament\Resources\ContactLinks\Schemas\ContactLinkForm;
use App\Filament\Resources\ContactLinks\Tables\ContactLinksTable;
use App\Models\ContactLink;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ContactLinkResource extends Resource
{
    protected static ?string $model = ContactLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'Contenido';

    protected static ?int $navigationSort = 11;

    protected static ?string $modelLabel = 'enlace';

    protected static ?string $pluralModelLabel = 'enlaces de contacto';

    protected static ?string $navigationLabel = 'Enlaces de contacto';

    protected static ?string $slug = 'enlaces-de-contacto';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return ContactLinkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactLinksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactLinks::route('/'),
            'create' => CreateContactLink::route('/create'),
            'edit' => EditContactLink::route('/{record}/edit'),
        ];
    }
}
