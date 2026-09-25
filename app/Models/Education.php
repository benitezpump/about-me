<?php

namespace App\Models;

use App\Models\Concerns\CoalescesNulls;
use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;

/**
 * Formación académica.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Education extends Model
{
    use ForgetsSiteContent, CoalescesNulls;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'education';

    /** Columnas `not null default ''`: Filament guarda un texto vacío como NULL. */
    protected array $emptyStrings = ['institution'];

    // `professional_license` sí admite NULL: sin cédula queda vacío en la base (Filament ya guarda '' como NULL).
}
