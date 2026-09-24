<?php

namespace App\Filament\Resources\CertificationGroups;

use App\Filament\Resources\CertificationGroups\Pages\CreateCertificationGroup;
use App\Filament\Resources\CertificationGroups\Pages\EditCertificationGroup;
use App\Filament\Resources\CertificationGroups\Pages\ListCertificationGroups;
use App\Filament\Resources\CertificationGroups\Schemas\CertificationGroupForm;
use App\Filament\Resources\CertificationGroups\Tables\CertificationGroupsTable;
use App\Models\CertificationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CertificationGroupResource extends Resource
{
    protected static ?string $model = CertificationGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Contenido';

    protected static ?int $navigationSort = 9;

    protected static ?string $modelLabel = 'grupo de certificaciones';

    protected static ?string $pluralModelLabel = 'grupos de certificaciones';

    protected static ?string $navigationLabel = 'Grupos de certificaciones';

    protected static ?string $slug = 'grupos-de-certificaciones';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return CertificationGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CertificationGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCertificationGroups::route('/'),
            'create' => CreateCertificationGroup::route('/create'),
            'edit' => EditCertificationGroup::route('/{record}/edit'),
        ];
    }
}
