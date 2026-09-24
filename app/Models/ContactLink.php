<?php

namespace App\Models;

use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;

/**
 * Enlace extra de la sección Contacto.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class ContactLink extends Model
{
    use ForgetsSiteContent;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'contact_links';
}
