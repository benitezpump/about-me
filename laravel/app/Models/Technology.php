<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de tecnologías: un nombre único (sin distinguir mayúsculas). Se elige de aquí, no se escribe.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Technology extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'technologies';
}
