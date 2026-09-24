<?php

namespace App\Models;

use App\Casts\PgTextArray;
use App\Models\Concerns\CoalescesNulls;
use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;

/**
 * Una sola fila (id = 1): nombre, presentación y contacto de la portada.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Profile extends Model
{
    use ForgetsSiteContent, CoalescesNulls;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'profile';

    /** Columnas `not null default ''`: Filament guarda un texto vacío como NULL. */
    protected array $emptyStrings = ['location', 'cta_label', 'cta_url', 'contact_prompt', 'meta_description', 'og_description'];

    protected function casts(): array
    {
        return [
            'intro' => PgTextArray::class,
            'show_view_count' => 'boolean',
        ];
    }
}
