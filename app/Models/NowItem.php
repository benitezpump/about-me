<?php

namespace App\Models;

use App\Models\Concerns\CoalescesNulls;
use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que hace la persona hoy (recuadro "Actualmente" de la portada).
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class NowItem extends Model
{
    use ForgetsSiteContent, CoalescesNulls;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'now_items';

    /** Columnas `not null default ''`: Filament guarda un texto vacío como NULL. */
    protected array $emptyStrings = ['body'];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
        ];
    }
}
