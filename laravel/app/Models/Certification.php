<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Certificado, con enlace opcional para verificarlo.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class Certification extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'certifications';
}
