<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Taller o curso impartido.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Workshop extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'workshops';
}
