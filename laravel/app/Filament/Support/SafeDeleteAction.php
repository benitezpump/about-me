<?php

namespace App\Filament\Support;

use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Borrar entre entidades independientes usa `on delete restrict`: la base impide borrar algo que aún tiene proyectos,
 * materias, talleres, certificaciones o tecnologías asociados. Aquí ese rechazo se explica en lugar de mostrar un error.
 */
final class SafeDeleteAction
{
    private const IN_USE_MESSAGE = 'No se puede eliminar porque se está usando o tiene elementos asociados (proyectos, materias, talleres, certificaciones o tecnologías). Quítalo de donde aparece o elimina primero lo asociado.';

    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->failureNotificationTitle('No se puede eliminar')
            ->using(function (Model $record, DeleteAction $action): bool {
                try {
                    // Su propia transacción (punto de guardado si ya hay una abierta): un rechazo de la base no debe
                    // dejar abortada la transacción de quien llama.
                    return (bool) DB::transaction(fn () => $record->delete());
                } catch (QueryException $e) {
                    // 23503 en PostgreSQL real (llave foránea); 23001 lo usa PGlite para `restrict`.
                    if (in_array($e->getCode(), ['23503', '23001'], true)) {
                        $action->failureNotificationTitle(self::IN_USE_MESSAGE);

                        return false;
                    }
                    throw $e;
                }
            });
    }
}
