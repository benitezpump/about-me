<?php

namespace App\Models;

use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;

/**
 * Punto del detalle de un proyecto (etiqueta opcional en negritas + texto).
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class ProjectHighlight extends Model
{
    use ForgetsSiteContent;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'project_highlights';
}
