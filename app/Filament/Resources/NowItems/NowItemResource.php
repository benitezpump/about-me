<?php

namespace App\Filament\Resources\NowItems;

use App\Filament\Resources\NowItems\Pages\CreateNowItem;
use App\Filament\Resources\NowItems\Pages\EditNowItem;
use App\Filament\Resources\NowItems\Pages\ListNowItems;
use App\Filament\Resources\NowItems\Schemas\NowItemForm;
use App\Filament\Resources\NowItems\Tables\NowItemsTable;
use App\Models\NowItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class NowItemResource extends Resource
{
    protected static ?string $model = NowItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Contenido';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'elemento';

    protected static ?string $pluralModelLabel = 'elementos';

    protected static ?string $navigationLabel = 'Actualmente';

    protected static ?string $slug = 'actualmente';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return NowItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NowItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNowItems::route('/'),
            'create' => CreateNowItem::route('/create'),
            'edit' => EditNowItem::route('/{record}/edit'),
        ];
    }
}
