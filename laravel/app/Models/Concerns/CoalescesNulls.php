<?php

namespace App\Models\Concerns;

/**
 * Filament guarda un campo de texto vacío como NULL, pero varias columnas del esquema son `not null default ''`. Los modelos
 * que usan este trait declaran cuáles en `$emptyStrings` y se guardan como '' (igual que hacía la versión anterior).
 */
trait CoalescesNulls
{
    protected static function bootCoalescesNulls(): void
    {
        static::saving(function ($model): void {
            foreach ($model->emptyStrings ?? [] as $column) {
                if ($model->getAttribute($column) === null) {
                    $model->setAttribute($column, '');
                }
            }
        });
    }
}
