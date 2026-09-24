<?php

namespace App\Filament\Support;

use App\Models\Experience;
use App\Rules\SafeUrl;
use App\Rules\UniqueTechnologyName;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;

/** Campos que se repiten en varios formularios del panel, con las mismas reglas que el formulario anterior. */
final class Fields
{
    public static function position(): TextInput
    {
        return TextInput::make('position')
            ->label('Orden')
            ->numeric()->integer()->minValue(0)->maxValue(9999)
            ->default(0)->required()
            ->helperText('Menor va primero. Sirve para desempatar cuando el orden no sale de una fecha.');
    }

    /** Las fechas tienen precisión de mes en el sitio, pero se guardan como día completo. */
    public static function date(string $name, string $label): DatePicker
    {
        return DatePicker::make($name)->label($label)->native(false)->displayFormat('d/m/Y')->closeOnDateSelection();
    }

    public static function url(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->maxLength(2000)
            ->rule(new SafeUrl)
            ->helperText('Empieza con https://, mailto: o tel:.');
    }

    /** Una tecnología del catálogo (se elige, no se escribe). Se puede crear una nueva desde aquí mismo. */
    public static function technology(): Select
    {
        return Select::make('technology_id')
            ->label('Tecnología')
            ->relationship('technology', 'name')
            ->searchable()->preload()
            ->required()
            ->distinct()
            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
            ->createOptionForm([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()->maxLength(80)
                    ->rule(fn (?Model $record) => new UniqueTechnologyName($record?->getKey())),
            ]);
    }

    /** @return Select una experiencia del tipo indicado ('work' o 'teaching') */
    public static function experience(string $kind, bool $required = true): Select
    {
        return Select::make('experience_id')
            ->label('Experiencia')
            ->options(fn () => Experience::query()->where('kind', $kind)->orderByDesc('start_date')->get()
                ->mapWithKeys(fn (Experience $e) => [$e->id => "{$e->company} — {$e->role}"])->all())
            ->searchable()
            ->required($required);
    }
}
