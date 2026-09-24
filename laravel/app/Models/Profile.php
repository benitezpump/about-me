<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Casts\PgTextArray;

/**
 * Una sola fila (id = 1): nombre, presentación y contacto de la portada.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Profile extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'profile';

    protected function casts(): array
    {
        return [
            'intro' => PgTextArray::class,
            'show_view_count' => 'boolean',
        ];
    }
}
