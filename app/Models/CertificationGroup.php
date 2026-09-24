<?php

namespace App\Models;

use App\Models\Concerns\ForgetsSiteContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Encabezado bajo el que se agrupan las certificaciones.
 *
 * Las tablas no llevan marcas de tiempo de Laravel: `updated_at` (donde existe) lo mantiene un trigger de la base.
 */
class CertificationGroup extends Model
{
    use ForgetsSiteContent;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'certification_groups';

    /** Más recientes primero. */
    public function certifications(): HasMany
    {
        return $this->hasMany(Certification::class, 'group_id')->orderByDesc('year')->orderBy('position')->orderBy('id');
    }
}
