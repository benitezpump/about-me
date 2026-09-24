<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Formación académica.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Education extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'education';
}
