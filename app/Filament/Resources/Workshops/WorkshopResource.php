<?php

namespace App\Filament\Resources\Workshops;

use App\Filament\Resources\Workshops\Pages\CreateWorkshop;
use App\Filament\Resources\Workshops\Pages\EditWorkshop;
use App\Filament\Resources\Workshops\Pages\ListWorkshops;
use App\Filament\Resources\Workshops\Schemas\WorkshopForm;
use App\Filament\Resources\Workshops\Tables\WorkshopsTable;
use App\Models\Workshop;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WorkshopResource extends Resource
{
    protected static ?string $model = Workshop::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Contenido';

    protected static ?int $navigationSort = 7;

    protected static ?string $modelLabel = 'taller';

    protected static ?string $pluralModelLabel = 'talleres';

    protected static ?string $navigationLabel = 'Talleres';

    protected static ?string $slug = 'talleres';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return WorkshopForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkshopsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkshops::route('/'),
            'create' => CreateWorkshop::route('/create'),
            'edit' => EditWorkshop::route('/{record}/edit'),
        ];
    }
}
