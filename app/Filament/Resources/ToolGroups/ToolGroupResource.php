<?php

namespace App\Filament\Resources\ToolGroups;

use App\Filament\Resources\ToolGroups\Pages\CreateToolGroup;
use App\Filament\Resources\ToolGroups\Pages\EditToolGroup;
use App\Filament\Resources\ToolGroups\Pages\ListToolGroups;
use App\Filament\Resources\ToolGroups\Schemas\ToolGroupForm;
use App\Filament\Resources\ToolGroups\Tables\ToolGroupsTable;
use App\Models\ToolGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ToolGroupResource extends Resource
{
    protected static ?string $model = ToolGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Contenido';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'grupo de herramientas';

    protected static ?string $pluralModelLabel = 'herramientas';

    protected static ?string $navigationLabel = 'Herramientas';

    protected static ?string $slug = 'herramientas';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return ToolGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ToolGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListToolGroups::route('/'),
            'create' => CreateToolGroup::route('/create'),
            'edit' => EditToolGroup::route('/{record}/edit'),
        ];
    }
}
